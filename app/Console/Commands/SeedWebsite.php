<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Category;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Price;
use App\Models\Release;
use App\Releases\ReleasePipeline;
use App\Support\Tarball;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * `php artisan registry:seed-website [--json]`
 *
 * Demo data for the publisher website's end-to-end tests, on top of `registry:seed
 * --seed-release` (a published free and a paid extension):
 *
 *   staff@registry.test      member of the `registry-staff` organization (is_staff)
 *   publisher@registry.test  owner of the `acme` organization, which has a listing with a
 *                            release waiting in the staff queue and a draft listing
 *
 * Both accounts are active and verified with the password `password123`, reset on every run.
 * Safe to re-run: records are matched by email, slug and version. The queued release goes
 * through the real pipeline (upload, checks, submit; checks run inline). Never runs in
 * production.
 */
class SeedWebsite extends Command
{
    protected $signature = 'registry:seed-website
        {--json : Print the accounts, extension slugs and queued release as JSON}';

    protected $description = 'Seed demo accounts, listings and a queued release for the publisher website';

    public const PASSWORD = 'password123';

    public const STAFF_EMAIL = 'staff@registry.test';

    public const PUBLISHER_EMAIL = 'publisher@registry.test';

    public const QUEUED_SLUG = 'acme-fleet-insights';

    public const DRAFT_SLUG = 'acme-route-planner';

    public function handle(ReleasePipeline $pipeline): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('registry:seed-website never runs in production.');

            return self::FAILURE;
        }

        if ($this->callSilently('registry:seed', ['--seed-release' => true]) !== self::SUCCESS) {
            $this->error('registry:seed --seed-release failed.');

            return self::FAILURE;
        }

        $catalog = $this->enrichCatalogListings();

        $staff = $this->account(self::STAFF_EMAIL, 'Registry Staff', 'registry-staff-reviewer');
        $staffOrganization = $this->organization('registry-staff', 'Fleetbase Registry Staff', $staff, Membership::ROLE_MEMBER);
        $staffOrganization->forceFill(['is_staff' => true])->save();

        $publisher = $this->account(self::PUBLISHER_EMAIL, 'Acme Publisher', 'acme-publisher');
        $this->organization('acme-publisher', 'Acme Publisher', $publisher, Membership::ROLE_OWNER, personal: true);
        $acme = $this->organization('acme', 'Acme Logistics', $publisher, Membership::ROLE_OWNER);
        $acme->forceFill(['website' => 'https://acme.example.com', 'bio' => 'Demo publisher seeded by registry:seed-website.', 'verified_at' => $acme->verified_at ?? now()])->save();

        $queuedListing = $this->listing($acme, $publisher, self::QUEUED_SLUG, 'Acme Fleet Insights', ready: true);
        $draftListing = $this->listing($acme, $publisher, self::DRAFT_SLUG, 'Acme Route Planner', ready: false);
        $queued = $this->queuedRelease($pipeline, $queuedListing, $publisher);

        $result = [
            'staff' => ['email' => self::STAFF_EMAIL, 'password' => self::PASSWORD],
            'publisher' => ['email' => self::PUBLISHER_EMAIL, 'password' => self::PASSWORD],
            'extensions' => array_merge($catalog, [$queuedListing->slug, $draftListing->slug]),
            'queued_release' => $queued !== null && in_array($queued->status, Release::QUEUE_STATUSES, true) ? $queued->public_id : null,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Seeded the publisher website demo.');
        $this->table(['Account', 'Email', 'Password'], [
            ['staff', self::STAFF_EMAIL, self::PASSWORD],
            ['publisher (acme)', self::PUBLISHER_EMAIL, self::PASSWORD],
        ]);
        $this->line('Extensions: '.implode(', ', $result['extensions']));
        if ($result['queued_release']) {
            $this->line("Queued release: {$result['queued_release']} ({$queuedListing->slug} {$queued->version}, {$queued->status})");
        } else {
            $this->warn('The demo release is no longer in the review queue (status '.($queued?->status ?? 'missing').'). Run on a fresh database to queue it again.');
        }

        return self::SUCCESS;
    }

    /**
     * Fill the catalog copy of the two `registry:seed` listings.
     *
     * @return list<string> their slugs
     */
    protected function enrichCatalogListings(): array
    {
        $listings = [
            SeedRegistry::PUBLISHER.'-hello' => [
                'category' => 'fleet-management',
                'subtitle' => 'A free demo extension that says hello from the console.',
                'tags' => ['demo', 'free', 'getting-started'],
            ],
            SeedRegistry::PUBLISHER.'-pro' => [
                'category' => 'analytics-and-reporting',
                'subtitle' => 'A paid demo extension with reports for fleet managers.',
                'tags' => ['demo', 'paid', 'reports'],
            ],
        ];

        foreach ($listings as $slug => $fields) {
            Extension::query()->where('slug', $slug)->firstOrFail()->forceFill([
                'category_uuid' => $this->categoryUuid($fields['category']),
                'subtitle' => $fields['subtitle'],
                'description' => 'Demo listing seeded by registry:seed-website. It installs a small engine and API package so the install flow can be exercised end to end.',
                'promotional_text' => '<p>Seeded for the <strong>publisher website</strong> end-to-end tests.</p><ul><li>Installs on cloud and self-hosted instances</li><li>No configuration needed</li></ul>',
                'tags' => $fields['tags'],
                'copyright' => '© Registry Demo',
                'website_url' => 'https://example.com',
                'support_url' => 'https://example.com/support',
                'privacy_url' => 'https://example.com/privacy',
            ])->save();
        }

        return array_keys($listings);
    }

    protected function listing(Organization $organization, Account $creator, string $slug, string $name, bool $ready): Extension
    {
        $extension = Extension::withTrashed()->firstOrNew(['slug' => $slug]);
        $extension->forceFill(array_merge([
            'organization_uuid' => $organization->uuid,
            'created_by_account_uuid' => $creator->uuid,
            'name' => $name,
            'hosting' => Extension::HOSTING_BOTH,
            'status' => $extension->exists && $extension->status !== Extension::STATUS_DRAFT ? $extension->status : Extension::STATUS_DRAFT,
            'deleted_at' => null,
        ], $ready ? [
            'category_uuid' => $this->categoryUuid('analytics-and-reporting'),
            'subtitle' => 'Dashboards for fuel, utilisation and driver behaviour.',
            'description' => 'Acme Fleet Insights adds dashboards and scheduled reports to the Fleetbase console.',
            'promotional_text' => '<p>See where your fleet <em>really</em> spends its time.</p>',
            'tags' => ['analytics', 'reports'],
            'languages' => ['en'],
            'copyright' => '© Acme Logistics',
            'website_url' => 'https://acme.example.com/insights',
            'support_url' => 'https://acme.example.com/support',
            'privacy_url' => 'https://acme.example.com/privacy',
            'fa_icon' => 'chart-line',
        ] : [
            'subtitle' => 'Plan multi-stop routes (draft).',
            'tags' => ['routing'],
        ]))->save();

        Price::query()->firstOrCreate(['extension_uuid' => $extension->uuid], ['model' => Price::MODEL_FREE, 'amount' => 0, 'currency' => 'USD']);

        foreach ($this->packageNames($organization, $slug) as $kind => $name) {
            ExtensionPackage::query()->firstOrCreate(['kind' => $kind, 'name' => $name], ['extension_uuid' => $extension->uuid]);
        }

        return $extension;
    }

    /**
     * The listing's 1.0.0 release, uploaded and submitted through the pipeline (first
     * release, so it waits for staff). A release staff already decided on is left alone,
     * except a rejected one, which is uploaded and submitted again.
     */
    protected function queuedRelease(ReleasePipeline $pipeline, Extension $extension, Account $publisher): ?Release
    {
        $version = '1.0.0';
        $existing = Release::query()->where('extension_uuid', $extension->uuid)->where('version', $version)->first();
        if ($existing !== null && ! $existing->isReplaceable()) {
            return $existing;
        }

        ['npm' => $npm, 'composer' => $composer] = $this->packageNames($extension->organization()->firstOrFail(), $extension->slug);
        $manifest = [
            'name' => $extension->name,
            'version' => $version,
            'engine' => $npm,
            'api' => $composer,
            'compat' => ['console' => '>=0.7.0', 'api' => '^1.6'],
        ];

        $uploads = [
            'engine' => $this->upload(Tarball::create([
                'package/package.json' => json_encode(['name' => $npm, 'version' => $version, 'main' => 'index.js', 'keywords' => ['fleetbase-extension']]),
                'package/index.js' => "module.exports = { name: 'acme-fleet-insights' };\n",
            ]), 'engine.tgz'),
            'api' => $this->upload(Tarball::create([
                'package/composer.json' => json_encode(['name' => $composer, 'type' => 'library', 'autoload' => ['psr-4' => ['Acme\\FleetInsights\\' => 'src/']]]),
                'package/src/FleetInsights.php' => "<?php\n\nnamespace Acme\\FleetInsights;\n\nclass FleetInsights {}\n",
            ]), 'api.tar.gz'),
        ];

        // Checks run inline so the release is submitted before this command returns.
        $connection = config('queue.default');
        config(['queue.default' => 'sync']);
        try {
            $release = $pipeline->upload($extension, $publisher, $manifest, Release::CHANNEL_STABLE, 'Seeded by registry:seed-website.', $uploads, true);
        } finally {
            config(['queue.default' => $connection]);
            foreach ($uploads as $upload) {
                @unlink($upload->getRealPath());
            }
        }

        return $release->fresh();
    }

    /**
     * @return array{npm: string, composer: string}
     */
    protected function packageNames(Organization $organization, string $slug): array
    {
        return ['npm' => '@'.$organization->slug.'/'.$slug.'-engine', 'composer' => $organization->slug.'/'.$slug.'-api'];
    }

    protected function upload(string $contents, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'seed');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'application/gzip', null, true);
    }

    protected function categoryUuid(string $slug): ?string
    {
        return Category::query()->where('slug', $slug)->value('uuid') ?? Category::query()->orderBy('sort')->value('uuid');
    }

    protected function account(string $email, string $name, string $username): Account
    {
        $account = Account::withTrashed()->firstOrNew(['email' => $email]);
        $account->fill([
            'name' => $name,
            'username' => $account->username ?? $username,
            'status' => Account::STATUS_ACTIVE,
            'email_verified_at' => $account->email_verified_at ?? now(),
            'timezone' => $account->timezone ?? 'UTC',
        ]);
        $account->password = self::PASSWORD;
        $account->deleted_at = null;
        $account->save();

        return $account;
    }

    protected function organization(string $slug, string $name, Account $member, string $role, bool $personal = false): Organization
    {
        $organization = Organization::withTrashed()->firstOrNew(['slug' => $slug]);
        $organization->fill(['name' => $organization->name ?? $name, 'is_personal' => $personal]);
        $organization->deleted_at = null;
        $organization->save();

        if ($member->roleIn($organization) === null) {
            $member->organizations()->attach($organization->uuid, ['role' => $role]);
        }

        return $organization;
    }
}
