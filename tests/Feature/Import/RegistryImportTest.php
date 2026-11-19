<?php

use App\Import\SourceDatabase;
use App\Import\SqlDumpLoader;
use App\Models\Account;
use App\Models\Category;
use App\Models\Event;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\File;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Price;
use App\Models\Purchase;
use App\Models\Release;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const FIXTURE_DUMP = __DIR__.'/../../Fixtures/import/cloud-registry.sql';

beforeEach(function () {
    $this->seed(CategorySeeder::class);

    config(['registry.storage.disk' => 'registry', 'registry.import.source_disk' => 'import_source']);
    Storage::fake('registry');
    $this->source = Storage::fake('import_source');

    $this->source->put('uploads/c1/icons/fleet-insights.png', 'PNG!');
    $this->source->put('uploads/c1/screens/dashboard.png', 'SHOT!');
    $this->source->put('uploads/c1/extensions/fleet-insights-1.0.0.tar.gz', 'fleet-insights 1.0.0 bundle');
    $this->source->put('uploads/c1/extensions/fleet-insights-1.1.0.tar.gz', 'fleet-insights 1.1.0 bundle');
    // route-planner-0.3.0.tar.gz is deliberately absent.
});

function runImport(array $options = []): array
{
    $report = tempnam(sys_get_temp_dir(), 'import-report-');

    test()->artisan('registry:import', array_merge(['--from' => FIXTURE_DUMP, '--report' => $report], $options))->assertSuccessful();

    $data = json_decode(file_get_contents($report), true);
    unlink($report);

    return $data;
}

function anomalyItems(array $report, string $type): array
{
    return $report['anomalies'][$type]['items'] ?? [];
}

it('dry-runs: prints counts and anomalies and writes nothing', function () {
    $categoriesBefore = Category::count();

    $this->artisan('registry:import', ['--from' => FIXTURE_DUMP, '--dry-run' => true])
        ->expectsOutputToContain('Dry run from')
        ->expectsOutputToContain('Would write')
        ->expectsOutputToContain('Extensions without package names or bundles: 1')
        ->expectsOutputToContain('Bundles whose tarball path is missing (imported as yanked): 2')
        ->expectsOutputToContain('Duplicate package names (first listing keeps the name): 1')
        ->expectsOutputToContain('Users without emails (not imported): 1')
        ->expectsOutputToContain('Dry run: nothing was written.')
        ->assertSuccessful();

    expect(Extension::withTrashed()->count())->toBe(0)
        ->and(Account::count())->toBe(0)
        ->and(Organization::count())->toBe(0)
        ->and(Release::withTrashed()->count())->toBe(0)
        ->and(File::withTrashed()->count())->toBe(0)
        ->and(Category::count())->toBe($categoriesBefore)
        ->and(Category::whereNotNull('source_uuid')->count())->toBe(0)
        ->and(Storage::disk('registry')->allFiles())->toBe([])
        ->and(Event::where('kind', 'registry.imported')->exists())->toBeFalse();
});

it('reports the same counts in a dry run as a real run', function () {
    $dry = runImport(['--dry-run' => true]);
    $real = runImport();

    expect($dry['dry_run'])->toBeTrue()
        ->and($dry['counts'])->toBe($real['counts'])
        ->and(array_map(fn ($a) => $a['count'], $dry['anomalies']))->toBe(array_map(fn ($a) => $a['count'], $real['anomalies']));
});

it('maps the cloud tables onto the service data model', function () {
    $report = runImport();

    expect($report['source'])->toMatchArray([
        'registry_extensions' => 5, 'registry_extension_bundles' => 6, 'registry_extension_purchases' => 2,
        'registry_extension_installs' => 4, 'registry_users' => 5, 'registry_developer_accounts' => 3,
        'companies' => 3, 'users' => 3, 'categories' => 2,
    ]);

    expect($report['counts'])->toMatchArray([
        'organizations' => ['created' => 6],
        'accounts' => ['created' => 4, 'unchanged' => 1, 'skipped' => 1],
        'memberships' => ['created' => 5],
        'extensions' => ['created' => 5],
        'prices' => ['created' => 5],
        'extension_packages' => ['created' => 5, 'skipped' => 1],
        'releases' => ['created' => 4, 'skipped' => 2],
        'purchases' => ['created' => 2],
        'instances' => ['created' => 1],
        'installs' => ['created' => 4],
        'licence_activations' => ['created' => 2],
    ]);
    expect($report['counts']['licences']['created'])->toBe(3);

    // Organizations: a personal one per developer account, one per referenced company.
    $acme = Organization::where('source_uuid', 'c0000000-0000-4000-8000-000000000001')->sole();
    expect($acme->slug)->toBe('acme-logistics')
        ->and($acme->stripe_connect_id)->toBe('acct_1Acme')
        ->and($acme->bio)->toBe("Acme's logistics arm")
        ->and($acme->is_personal)->toBeFalse()
        ->and(Organization::where('source_uuid', 'c0000000-0000-4000-8000-000000000002')->value('slug'))->toBe('beta-freight')
        ->and(Organization::where('is_personal', true)->pluck('slug')->sort()->values()->all())->toBe(['alice-dev', 'devon', 'penny']);

    // Accounts: developer accounts and cloud registry users; Alice's two identities merge by email.
    $devon = Account::where('email', 'devon@indie.test')->sole();
    expect($devon->status)->toBe(Account::STATUS_ACTIVE)
        ->and($devon->username)->toBe('devon')
        ->and($devon->meta['github_username'])->toBe('devon-gh')
        ->and(Account::where('email', 'penny@indie.test')->value('status'))->toBe(Account::STATUS_PENDING_VERIFICATION);

    $alice = Account::where('email', 'alice@acme.test')->sole();
    expect($alice->source_uuid)->toBe('d0000000-0000-4000-8000-000000000003')
        ->and(Membership::where('account_uuid', $alice->uuid)->where('organization_uuid', $acme->uuid)->value('role'))->toBe(Membership::ROLE_OWNER);

    // A registry user who is not the company owner joins as publisher.
    $carol = Account::where('email', 'carol@beta.test')->sole();
    expect(Membership::where('account_uuid', $carol->uuid)->value('role'))->toBe(Membership::ROLE_PUBLISHER)
        ->and(Account::where('name', 'Bob NoEmail')->exists())->toBeFalse();

    // Extensions, prices and package names.
    $fleet = Extension::where('source_uuid', 'e0000000-0000-4000-8000-000000000001')->sole();
    expect($fleet->slug)->toBe('fleet-insights')
        ->and($fleet->organization_uuid)->toBe($acme->uuid)
        ->and($fleet->status)->toBe(Extension::STATUS_PUBLISHED)
        ->and($fleet->hosting)->toBe(Extension::HOSTING_BOTH)
        ->and($fleet->description)->toBe("Dashboards and \"insights\" for fleet operators.\r\nSecond line.")
        ->and($fleet->copyright)->toBe('© 2024 Acme')
        ->and($fleet->tags)->toBe(['analytics', 'fleet'])
        ->and($fleet->privacy_url)->toBe('https://acme.test/privacy')
        ->and($fleet->category->slug)->toBe('telematics')
        ->and($fleet->created_by_account_uuid)->toBe($alice->uuid)
        ->and($fleet->published_at->toDateTimeString())->toBe('2024-05-01 10:00:00');

    expect($fleet->price->only(['model', 'amount', 'currency', 'sale_amount', 'on_sale']))
        ->toBe(['model' => Price::MODEL_ONE_TIME, 'amount' => 4900, 'currency' => 'USD', 'sale_amount' => 3900, 'on_sale' => false]);

    expect($fleet->packages()->pluck('name', 'kind')->all())
        ->toBe(['npm' => '@acme/fleet-insights-engine', 'composer' => 'acme/fleet-insights-api']);

    $route = Extension::where('slug', 'route-planner')->sole();
    expect($route->organization->slug)->toBe('devon')
        ->and($route->hosting)->toBe(Extension::HOSTING_SELF_HOSTED)
        ->and($route->price->model)->toBe(Price::MODEL_FREE)
        ->and($route->category->slug)->toBe('custom-tools')
        ->and(Category::where('slug', 'custom-tools')->value('source_uuid'))->toBe('b0000000-0000-4000-8000-000000000002')
        ->and(Category::where('slug', 'telematics')->value('source_uuid'))->toBe('b0000000-0000-4000-8000-000000000001')
        ->and(Category::where('slug', 'telematics')->value('description'))->not->toBe('Cloud copy of telematics');

    expect(Extension::where('slug', 'draft-thing')->value('status'))->toBe(Extension::STATUS_DRAFT)
        ->and(Extension::where('slug', 'copycat')->value('status'))->toBe(Extension::STATUS_IN_REVIEW)
        ->and(Extension::where('slug', 'copycat')->sole()->packages()->count())->toBe(0);

    $old = Extension::withTrashed()->where('slug', 'old-widget')->sole();
    expect($old->trashed())->toBeTrue()
        ->and($old->status)->toBe(Extension::STATUS_UNPUBLISHED)
        ->and($old->packages()->pluck('name', 'kind')->all())->toBe(['npm' => '@acme/old-widget-engine']);

    // Releases: version from extension.json, statuses mapped, the newest bundle wins a repeated version.
    $releases = Release::where('extension_uuid', $fleet->uuid)->orderBy('version')->get();
    expect($releases->pluck('version')->all())->toBe(['1.0.0', '1.1.0'])
        ->and($releases->pluck('status')->unique()->all())->toBe([Release::STATUS_PUBLISHED])
        ->and($releases[1]->compat)->toBe(['console' => '>=0.7.0', 'api' => '^1.6'])
        ->and($releases[1]->manifest['bundle_number'])->toBe('2')
        ->and($releases[1]->meta['source']['bundle_id'])->toBe('EXTBNDLBBBBBBB')
        ->and($releases[1]->published_at->toDateTimeString())->toBe('2024-05-01 10:00:00')
        ->and($releases[0]->published_at->toDateTimeString())->toBe('2024-04-02 00:00:00');

    // Purchases and licences: one licence per purchase, 5 seats; a deleted purchase revokes it.
    $paid = Purchase::where('stripe_checkout_session_id', 'cs_test_1')->sole();
    expect((int) $paid->amount_total)->toBe(4900)
        ->and($paid->buyer->slug)->toBe('beta-freight')
        ->and($paid->licence->only(['kind', 'max_activations', 'status']))->toBe(['kind' => 'paid', 'max_activations' => 5, 'status' => 'active']);

    $refunded = Purchase::withTrashed()->where('stripe_checkout_session_id', 'cs_test_2')->sole();
    expect($refunded->trashed())->toBeTrue()
        ->and((int) $refunded->amount_total)->toBe(4900)
        ->and(Licence::where('purchase_uuid', $refunded->uuid)->value('status'))->toBe(Licence::STATUS_REVOKED);

    // Installs: every cloud install on the one cloud instance, for its company's organization;
    // deleted installs uninstalled. The instance waits for the cloud API to register (no token).
    $cloud = Instance::sole();
    $beta = Organization::where('source_uuid', 'c0000000-0000-4000-8000-000000000002')->sole();
    $gamma = Organization::where('source_uuid', 'c0000000-0000-4000-8000-000000000003')->sole();
    expect($cloud->kind)->toBe(Instance::KIND_CLOUD)
        ->and($cloud->isPlatform())->toBeTrue()
        ->and($cloud->token_hash)->toBeNull()
        ->and($cloud->organization->slug)->toBe('fleetbase')
        ->and(Install::where('instance_uuid', '!=', $cloud->uuid)->exists())->toBeFalse();

    $routeInstalls = Install::with('release')->where('extension_uuid', $route->uuid)->orderBy('started_at')->get();
    expect($routeInstalls->pluck('status')->all())->toBe(['uninstalled', 'installed'])
        ->and($routeInstalls->pluck('organization_uuid')->unique()->all())->toBe([$gamma->uuid])
        ->and($routeInstalls[1]->release->version)->toBe('0.3.0');

    // Live installs hold a seat on the cloud instance: Beta's paid licence, Gamma's automatic free licence.
    $betaSeat = LicenceActivation::whereNull('deactivated_at')->whereHas('licence', fn ($q) => $q->where('organization_uuid', $beta->uuid))->sole();
    expect($betaSeat->licence_uuid)->toBe($paid->licence->uuid)
        ->and($betaSeat->instance_uuid)->toBe($cloud->uuid);

    $free = Licence::where('extension_uuid', $route->uuid)->sole();
    expect($free->kind)->toBe(Licence::KIND_FREE)
        ->and($free->max_activations)->toBeNull()
        ->and($free->organization_uuid)->toBe($gamma->uuid)
        ->and($free->activeActivations()->sole()->instance_uuid)->toBe($cloud->uuid);

    // Acme installed its own paid extension without a purchase: install kept, no seat.
    expect(Install::where('organization_uuid', $acme->uuid)->value('status'))->toBe('installed')
        ->and(LicenceActivation::whereHas('licence', fn ($q) => $q->where('organization_uuid', $acme->uuid))->count())->toBe(0);

    expect(Event::where('kind', 'registry.imported')->sole()->payload['counts']['extensions']['created'])->toBe(5);

    // The catalog counts installs from the imported rows.
    $this->getJson('/v1/catalog/extensions?q=Fleet')->assertOk()->assertJsonPath('data.0.installs_count', 2);
});

it('lists every anomaly class the plan asks for', function () {
    $report = runImport(['--dry-run' => true]);

    expect(collect(anomalyItems($report, 'extension_without_packages'))->pluck('name')->all())->toBe(['Draft Thing'])
        ->and(collect(anomalyItems($report, 'tarball_missing'))->pluck('reason', 'bundle')->all())->toBe([
            'EXTBNDLDDDDDDD' => 'object not found on source disk',
            'EXTBNDLFFFFFFF' => 'file row not found',
        ])
        ->and(anomalyItems($report, 'duplicate_package_name'))->toHaveCount(1)
        ->and(anomalyItems($report, 'duplicate_package_name')[0])->toMatchArray(['name' => '@acme/fleet-insights-engine', 'extension' => 'Copycat', 'kept_by' => 'fleet-insights'])
        ->and(anomalyItems($report, 'user_without_email')[0])->toMatchArray(['source' => 'users', 'name' => 'Bob NoEmail'])
        ->and(anomalyItems($report, 'merged_account')[0])->toMatchArray(['email' => 'alice@acme.test', 'source' => 'users'])
        ->and(anomalyItems($report, 'duplicate_version')[0])->toMatchArray(['extension' => 'fleet-insights', 'version' => '1.0.0', 'bundle' => 'EXTBNDLCCCCCCC'])
        ->and(anomalyItems($report, 'bundle_without_version')[0])->toMatchArray(['extension' => 'route-planner', 'bundle' => 'EXTBNDLEEEEEEE'])
        ->and(anomalyItems($report, 'install_without_licence')[0])->toMatchArray(['extension' => 'fleet-insights', 'company' => 'c0000000-0000-4000-8000-000000000001']);
});

it('copies tarballs into service storage with checksums and yanks releases without one', function () {
    runImport();

    $release = Release::where('version', '1.1.0')->sole();
    $file = File::find($release->meta['source_bundle_file_uuid']);

    expect($file->type)->toBe(File::TYPE_SOURCE_BUNDLE)
        ->and($file->disk)->toBe('registry')
        ->and($file->subject_uuid)->toBe($release->uuid)
        ->and($file->path)->toBe('tarballs/imported/f0000000-0000-4000-8000-000000000012/fleet-insights-1.1.0.tar.gz')
        ->and(Storage::disk('registry')->get($file->path))->toBe('fleet-insights 1.1.0 bundle')
        ->and($file->checksum)->toBe('sha256:'.hash('sha256', 'fleet-insights 1.1.0 bundle'))
        ->and($release->checksum)->toBe($file->checksum)
        ->and($release->size_bytes)->toBe(strlen('fleet-insights 1.1.0 bundle'));

    $yanked = Release::whereIn('version', ['0.3.0'])->orWhere(fn ($q) => $q->where('version', '1.0.0')->whereHas('extension', fn ($e) => $e->where('slug', 'copycat')))->get();
    expect($yanked)->toHaveCount(2)
        ->and($yanked->pluck('status')->unique()->all())->toBe([Release::STATUS_YANKED])
        ->and($yanked->every(fn ($r) => $r->yanked_at !== null && $r->checksum === null))->toBeTrue()
        ->and($yanked->pluck('meta.import_yank_reason')->sort()->values()->all())->toBe(['file row not found', 'object not found on source disk'])
        ->and($yanked->firstWhere('version', '0.3.0')->size_bytes)->toBe(9001);

    $fleet = Extension::where('slug', 'fleet-insights')->sole();
    expect(Storage::disk('registry')->get($fleet->icon->path))->toBe('PNG!')
        ->and($fleet->icon->type)->toBe(File::TYPE_ICON)
        ->and($fleet->screenshots()->sole()->checksum)->toBe('sha256:'.hash('sha256', 'SHOT!'));
});

it('re-runs idempotently', function () {
    runImport();
    $snapshot = fn () => [
        Organization::withTrashed()->count(), Account::withTrashed()->count(), Membership::count(),
        Extension::withTrashed()->count(), ExtensionPackage::count(), Price::count(), Release::withTrashed()->count(),
        File::withTrashed()->count(), Purchase::withTrashed()->count(), Licence::withTrashed()->count(),
        Instance::withTrashed()->count(), Install::count(), LicenceActivation::count(), Category::count(),
    ];
    $before = $snapshot();
    $yankedAt = Release::where('version', '0.3.0')->sole()->yanked_at->toDateTimeString();

    $second = runImport();

    expect($snapshot())->toBe($before);
    foreach ($second['counts'] as $table => $counts) {
        expect($counts['created'] ?? 0)->toBe(0, "{$table} created rows on the second run")
            ->and($counts['updated'] ?? 0)->toBe(0, "{$table} updated rows on the second run");
    }
    expect(Release::where('version', '0.3.0')->sole()->yanked_at->toDateTimeString())->toBe($yankedAt);
});

it('re-runs as unchanged when the database reorders JSON keys, as MySQL does', function () {
    runImport();

    // MySQL returns JSON objects with their keys sorted by length, then bytes.
    $reorder = function (?string $json) use (&$reorder) {
        $sort = function ($value) use (&$sort) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                uksort($value, fn ($a, $b) => [strlen($a), $a] <=> [strlen($b), $b]);
            }

            return array_map($sort, $value);
        };

        return $json === null ? null : json_encode($sort(json_decode($json, true)));
    };
    foreach (['extensions' => ['tags', 'meta'], 'releases' => ['manifest', 'compat', 'meta'], 'purchases' => ['price_snapshot', 'meta'], 'instances' => ['meta']] as $table => $columns) {
        foreach (DB::table($table)->get() as $row) {
            DB::table($table)->where('id', $row->id)->update(collect($columns)->mapWithKeys(fn ($c) => [$c => $reorder($row->{$c})])->all());
        }
    }

    $second = runImport();
    foreach ($second['counts'] as $table => $counts) {
        expect($counts['updated'] ?? 0)->toBe(0, "{$table} updated rows on the second run");
    }
});

it('picks up a tarball that becomes retrievable on a later run', function () {
    runImport();
    expect(Release::where('version', '0.3.0')->value('status'))->toBe(Release::STATUS_YANKED);

    $this->source->put('uploads/developers/route-planner-0.3.0.tar.gz', 'route planner bundle');
    runImport();

    $release = Release::where('version', '0.3.0')->sole();
    expect($release->status)->toBe(Release::STATUS_PUBLISHED)
        ->and($release->yanked_at)->toBeNull()
        ->and($release->checksum)->toBe('sha256:'.hash('sha256', 'route planner bundle'));
});

it('lets imported users log in with their cloud password', function () {
    runImport();

    $this->postJson('/v1/auth/login', ['email' => 'devon@indie.test', 'password' => 'secret-password'])
        ->assertOk()->assertJsonStructure(['token']);
    $this->postJson('/v1/auth/login', ['email' => 'carol@beta.test', 'password' => 'secret-password'])->assertOk();
    $this->postJson('/v1/auth/login', ['email' => 'carol@beta.test', 'password' => 'wrong'])->assertStatus(401);
});

it('never overwrites an account created in the service', function () {
    $existing = Account::factory()->create(['email' => 'devon@indie.test', 'name' => 'Devon (service)', 'password' => 'service-password']);

    $report = runImport();

    $existing->refresh();
    expect($existing->name)->toBe('Devon (service)')
        ->and($existing->source_uuid)->toBeNull()
        ->and(collect(anomalyItems($report, 'merged_account'))->pluck('into', 'email')->get('devon@indie.test'))->toBe('service account')
        ->and(Extension::where('slug', 'route-planner')->sole()->organization->slug)->toBe('devon');

    $this->postJson('/v1/auth/login', ['email' => 'devon@indie.test', 'password' => 'service-password'])->assertOk();
});

it('reads from a sqlite:// source', function () {
    $sqlite = tempnam(sys_get_temp_dir(), 'cloud-').'.sqlite';
    (new SqlDumpLoader(SourceDatabase::TABLES))->load(FIXTURE_DUMP, $sqlite);

    try {
        $report = runImport(['--from' => 'sqlite://'.$sqlite, '--dry-run' => true]);
    } finally {
        @unlink($sqlite);
    }

    expect($report['counts']['extensions']['created'])->toBe(5);
});

it('reads a dump from a filesystem disk', function () {
    $this->source->put('registry-cutover/cloud-registry.sql', file_get_contents(FIXTURE_DUMP));

    $report = runImport(['--from' => 'import_source:registry-cutover/cloud-registry.sql', '--dry-run' => true]);

    expect($report['counts']['extensions']['created'])->toBe(5);
    $this->artisan('registry:import', ['--from' => 'import_source:registry-cutover/missing.sql', '--dry-run' => true])
        ->expectsOutputToContain('SQL dump not found on the import_source disk')->assertFailed();
});

it('reads socket and TLS settings from a mysql:// source', function () {
    $source = new class('mysql://importer:p%40ss@db.internal:3307/fleetbase?unix_socket=/tmp/my.sock&ssl_ca=/etc/ssl/rds.pem') extends SourceDatabase
    {
        public function config(): array
        {
            return $this->connectionConfig();
        }
    };

    expect($source->config())->toMatchArray([
        'driver' => 'mysql', 'host' => 'db.internal', 'port' => 3307, 'database' => 'fleetbase',
        'username' => 'importer', 'password' => 'p@ss', 'unix_socket' => '/tmp/my.sock',
        'options' => [PDO::MYSQL_ATTR_SSL_CA => '/etc/ssl/rds.pem'],
    ])->and($source->describe())->toBe('mysql://importer:***@db.internal:3307/fleetbase?unix_socket=/tmp/my.sock&ssl_ca=/etc/ssl/rds.pem');
});

it('fails cleanly without a source or with an unreadable one', function () {
    $this->artisan('registry:import')->expectsOutputToContain('--from is required')->assertExitCode(2);
    $this->artisan('registry:import', ['--from' => '/nope/missing.sql'])->expectsOutputToContain('SQL dump not found')->assertFailed();
    $this->artisan('registry:import', ['--from' => 'mysql://user@/'])->expectsOutputToContain('Expected a DSN like')->assertFailed();
});

it('attaches cloud installs to the instance of the cloud host, which the cloud API then registers onto', function () {
    config(['registry.cloud.platform_secret' => CLOUD_PLATFORM_SECRET]);

    runImport(['--cloud-host' => 'api.fleetbase.test']);
    $cloud = Instance::sole();
    expect($cloud->host)->toBe('api.fleetbase.test');

    $token = registerCloudInstance(['host' => 'https://api.fleetbase.test'])->assertOk()
        ->assertJsonPath('rotated', true)
        ->assertJsonPath('instance.id', $cloud->public_id)
        ->json('token');

    // Beta's console sees its purchase, with the seat on this instance.
    $beta = Organization::where('source_uuid', 'c0000000-0000-4000-8000-000000000002')->sole();
    $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Registry-Tenant' => $beta->source_uuid])
        ->getJson('/v1/instances/me')->assertOk()
        ->assertJsonPath('tenant.id', $beta->public_id)
        ->assertJsonPath('licences.0.extension.slug', 'fleet-insights')
        ->assertJsonPath('licences.0.activated_here', true);

    // A later import run finds the registered instance again.
    $second = runImport(['--cloud-host' => 'api.fleetbase.test']);
    expect(Instance::count())->toBe(1)
        ->and($second['counts']['instances'])->toBe(['unchanged' => 1]);
});

it('retires the per-company instances of earlier import runs into the cloud instance', function () {
    $gamma = Organization::factory()->create();
    $gamma->forceFill(['source_uuid' => 'c0000000-0000-4000-8000-000000000003'])->save();
    $old = Instance::factory()->create(['organization_uuid' => $gamma->uuid, 'kind' => Instance::KIND_CLOUD, 'token_hash' => null, 'meta' => ['synthetic' => true, 'source' => 'companies']]);
    $extension = Extension::factory()->create();
    $licence = Licence::create(['extension_uuid' => $extension->uuid, 'organization_uuid' => $gamma->uuid, 'kind' => Licence::KIND_FREE, 'status' => Licence::STATUS_ACTIVE]);
    $seat = LicenceActivation::create(['licence_uuid' => $licence->uuid, 'instance_uuid' => $old->uuid, 'activated_at' => now()]);

    runImport();

    $cloud = Instance::get()->first(fn (Instance $i) => $i->isPlatform());
    expect($old->fresh()->status)->toBe(Instance::STATUS_REVOKED)
        ->and($old->fresh()->meta['retired_into'])->toBe($cloud->public_id)
        ->and($seat->fresh()->instance_uuid)->toBe($cloud->uuid)
        ->and($seat->fresh()->deactivated_at)->toBeNull();
});

it('verifies a clean import: no pending changes, no missing rows, sampled rows and tarballs match', function () {
    runImport();

    $this->artisan('registry:import', ['--from' => FIXTURE_DUMP, '--verify' => true, '--seed' => 7])
        ->expectsOutputToContain('none: the import is current')
        ->expectsOutputToContain('every sampled row matches')
        ->expectsOutputToContain('waiting for the cloud API to register')
        ->expectsOutputToContain('No drift.')
        ->assertSuccessful();
});

it('exits non-zero when the target drifted from the source', function (Closure $tamper, string $expected) {
    runImport();
    $tamper();

    $this->artisan('registry:import', ['--from' => FIXTURE_DUMP, '--verify' => true, '--sample' => 100])
        ->expectsOutputToContain($expected)
        ->expectsOutputToContain('Drift between source and target.')
        ->assertFailed();
})->with([
    'a licence lost' => [fn () => Licence::whereNotNull('source_uuid')->where('kind', 'paid')->where('status', 'active')->first()->forceDelete(), 'Would create'],
    'an install moved off the cloud instance' => [fn () => Install::where('status', 'installed')->first()->forceFill(['instance_uuid' => Instance::factory()->create()->uuid])->save(), 'instance'],
    'a release renamed' => [fn () => Release::where('version', '1.1.0')->update(['version' => '1.1.1']), 'version'],
    'a tarball changed in storage' => [fn () => Storage::disk('registry')->put(File::where('type', File::TYPE_SOURCE_BUNDLE)->first()->path, 'tampered'), 'tarball sha256'],
    'an extension deleted' => [fn () => Extension::where('slug', 'route-planner')->forceDelete(), 'yes'],
]);

it('verifies with nothing imported yet as drift', function () {
    $this->artisan('registry:import', ['--from' => FIXTURE_DUMP, '--verify' => true])
        ->expectsOutputToContain('Drift between source and target.')
        ->assertFailed();

    expect(Extension::withTrashed()->count())->toBe(0);
});
