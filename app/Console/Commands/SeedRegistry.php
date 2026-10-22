<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Category;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\File;
use App\Models\Licence;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Price;
use App\Models\Release;
use App\Models\Token;
use App\Services\ReleaseStorage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `php artisan registry:seed --seed-release`
 *
 * Seeds reference data and, with --seed-release, fabricates a published free and a paid
 * extension from the fixture tarballs in database/fixtures/packages, plus a buyer holding a
 * licence for the paid one, so the npm and Composer endpoints can be exercised end to end.
 * Safe to re-run: records are matched by slug, email and version; tokens are reissued.
 */
class SeedRegistry extends Command
{
    protected $signature = 'registry:seed
        {--seed-release : Fabricate a published free and a paid release from the fixture tarballs}
        {--json : Print the seeded package names and tokens as JSON}
        {--force : Allow running in production}';

    protected $description = 'Seed reference data and, optionally, demo releases for the package endpoints';

    public const PUBLISHER = 'registry-demo';

    public const TOKEN_NAME = 'registry:seed';

    /** Fixture extensions: key (fixture file prefix) => [name, price in cents or null for free]. */
    public const FIXTURES = [
        'hello' => ['Registry Demo Hello', null],
        'pro' => ['Registry Demo Pro', 4900],
    ];

    public function handle(ReleaseStorage $storage): int
    {
        if ($this->laravel->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to seed in production without --force.');

            return self::FAILURE;
        }

        $this->callSilently('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        if (! $this->option('seed-release')) {
            $this->option('json') ? $this->line(json_encode(['seeded' => ['categories']])) : $this->info('Seeded categories.');

            return self::SUCCESS;
        }

        $result = DB::transaction(fn () => $this->seedReleases($storage));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Seeded demo releases.');
        $this->table(['Extension', 'Pricing', 'npm', 'Composer', 'Version'], array_map(fn ($e) => [
            $e['name'], $e['paid'] ? 'paid' : 'free', $e['npm'], $e['composer'], $e['version'],
        ], $result['extensions']));
        $this->newLine();
        $this->line('Tokens (shown once, install scope):');
        $this->line("  licensed   {$result['tokens']['licensed']}   (organization {$result['organizations']['buyer']} holds a licence for the paid extension)");
        $this->line("  unlicensed {$result['tokens']['unlicensed']}   (organization {$result['organizations']['outsider']} holds none)");

        return self::SUCCESS;
    }

    protected function seedReleases(ReleaseStorage $storage): array
    {
        $publisherAccount = $this->account('publisher@registry-demo.test', 'Registry Demo Publisher', 'registry-demo-publisher');
        $publisher = $this->organization(self::PUBLISHER, 'Registry Demo', $publisherAccount);
        $category = Category::query()->orderBy('sort')->first();

        $extensions = [];
        foreach (self::FIXTURES as $key => [$name, $amount]) {
            $extension = Extension::withTrashed()->updateOrCreate(['slug' => self::PUBLISHER.'-'.$key], [
                'organization_uuid' => $publisher->uuid,
                'category_uuid' => $category?->uuid,
                'created_by_account_uuid' => $publisherAccount->uuid,
                'name' => $name,
                'subtitle' => "Fixture extension seeded by registry:seed ({$key}).",
                'description' => 'Demo listing used to exercise the npm and Composer endpoints.',
                'hosting' => Extension::HOSTING_BOTH,
                'status' => Extension::STATUS_PUBLISHED,
                'published_at' => now(),
                'fa_icon' => 'puzzle-piece',
                'tags' => ['demo'],
                'languages' => ['en'],
            ]);
            $extension->restore();

            Price::query()->updateOrCreate(['extension_uuid' => $extension->uuid], $amount === null
                ? ['model' => Price::MODEL_FREE, 'amount' => 0, 'currency' => 'USD']
                : ['model' => Price::MODEL_ONE_TIME, 'amount' => $amount, 'currency' => 'USD']);

            $npm = ExtensionPackage::query()->firstOrCreate(
                ['kind' => ExtensionPackage::KIND_NPM, 'name' => '@'.self::PUBLISHER.'/'.$key.'-engine'],
                ['extension_uuid' => $extension->uuid],
            );
            $composer = ExtensionPackage::query()->firstOrCreate(
                ['kind' => ExtensionPackage::KIND_COMPOSER, 'name' => self::PUBLISHER.'/'.$key.'-api'],
                ['extension_uuid' => $extension->uuid],
            );

            $release = $this->release($storage, $extension, $key, '1.0.0', $publisherAccount);

            $extensions[] = [
                'name' => $extension->name,
                'slug' => $extension->slug,
                'paid' => $amount !== null,
                'npm' => $npm->name,
                'composer' => $composer->name,
                'version' => $release->version,
            ];
        }

        $paid = Extension::query()->where('slug', self::PUBLISHER.'-pro')->firstOrFail();

        $buyerAccount = $this->account('buyer@registry-demo.test', 'Registry Demo Buyer', 'registry-demo-buyer');
        $buyer = $this->organization('registry-demo-buyer', 'Registry Demo Buyer', $buyerAccount);
        Licence::withTrashed()->updateOrCreate(
            ['extension_uuid' => $paid->uuid, 'organization_uuid' => $buyer->uuid],
            ['kind' => Licence::KIND_PAID, 'status' => Licence::STATUS_ACTIVE, 'expires_at' => null],
        )->restore();

        $outsiderAccount = $this->account('outsider@registry-demo.test', 'Registry Demo Outsider', 'registry-demo-outsider');
        $outsider = $this->organization('registry-demo-outsider', 'Registry Demo Outsider', $outsiderAccount);

        return [
            'extensions' => $extensions,
            'organizations' => ['publisher' => $publisher->slug, 'buyer' => $buyer->slug, 'outsider' => $outsider->slug],
            'tokens' => [
                'licensed' => $this->reissueToken($buyerAccount),
                'unlicensed' => $this->reissueToken($outsiderAccount),
            ],
        ];
    }

    protected function release(ReleaseStorage $storage, Extension $extension, string $key, string $version, Account $creator): Release
    {
        $release = Release::withTrashed()->firstOrNew(['extension_uuid' => $extension->uuid, 'version' => $version]);
        $release->fill([
            'channel' => Release::CHANNEL_STABLE,
            'status' => Release::STATUS_PUBLISHED,
            'created_by_account_uuid' => $creator->uuid,
            'manifest' => ['id' => $extension->slug, 'name' => $extension->name, 'version' => $version],
            'compat' => ['console' => '>=0.7.0', 'api' => '>=1.6.0'],
            'notes' => 'Seeded by registry:seed --seed-release.',
            'published_at' => $release->published_at ?? now(),
        ]);
        $release->deleted_at = null;
        $release->save();

        $files = [
            'engine_tarball_file_uuid' => [File::TYPE_ENGINE_TARBALL, "{$key}-engine-{$version}.tgz"],
            'api_tarball_file_uuid' => [File::TYPE_API_TARBALL, "{$key}-api-{$version}.tar.gz"],
        ];
        foreach ($files as $column => [$type, $fixture]) {
            $existing = $release->{$column} ? File::find($release->{$column}) : null;
            if ($existing && $storage->exists($existing)) {
                continue;
            }

            $file = $storage->store(base_path('database/fixtures/packages/'.$fixture), $type, $release, [
                'uploader_account_uuid' => $creator->uuid,
            ]);
            $release->{$column} = $file->uuid;
        }

        $release->size_bytes = File::query()->whereIn('uuid', [$release->engine_tarball_file_uuid, $release->api_tarball_file_uuid])->sum('size');
        $release->checksum = 'sha256:'.File::find($release->engine_tarball_file_uuid)?->checksum;
        $release->save();

        return $release;
    }

    protected function account(string $email, string $name, string $username): Account
    {
        $account = Account::withTrashed()->firstOrNew(['email' => $email]);
        $account->fill([
            'name' => $name,
            'username' => $username,
            'status' => Account::STATUS_ACTIVE,
            'email_verified_at' => $account->email_verified_at ?? now(),
        ]);
        if (! $account->exists) {
            $account->password = Str::random(40);
        }
        $account->deleted_at = null;
        $account->save();

        return $account;
    }

    protected function organization(string $slug, string $name, Account $owner): Organization
    {
        $organization = Organization::withTrashed()->updateOrCreate(['slug' => $slug], ['name' => $name, 'verified_at' => now()]);
        $organization->restore();

        if (! $owner->organizations()->whereKey($organization->uuid)->exists()) {
            $owner->organizations()->attach($organization->uuid, ['role' => Membership::ROLE_OWNER]);
        }

        return $organization;
    }

    protected function reissueToken(Account $account): string
    {
        $account->tokens()->where('name', self::TOKEN_NAME)->whereNull('revoked_at')->get()->each->revoke();

        return Token::issue($account, self::TOKEN_NAME, [Token::SCOPE_INSTALL, Token::SCOPE_READ], now()->addDays(30))->plainTextToken;
    }
}
