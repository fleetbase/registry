<?php

namespace App\Import;

use App\Actions\ActivateLicence;
use App\Actions\DeactivateLicence;
use App\Exceptions\LicenceRequiredException;
use App\Models\Account;
use App\Models\Category;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\File;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Membership;
use App\Models\Model;
use App\Models\Organization;
use App\Models\Price;
use App\Models\Purchase;
use App\Models\Release;
use App\Support\Compat;
use App\Support\Semver;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Maps the cloud registry-bridge tables onto the Registry Service data model, following
 * the plan's "Mapping rules for the importer".
 *
 * Every imported row carries the uuid of its source row in `source_uuid`, so the import
 * re-runs idempotently: rows are matched on `source_uuid` and updated in place, and an
 * unchanged source produces only "unchanged" counts. A dry run performs the same work
 * inside a transaction that is rolled back and copies no files, so its counts and
 * anomalies are exactly what a real run would produce.
 */
class RegistryImporter
{
    public const DEFAULT_PAID_SEATS = 5;

    protected ImportReport $report;

    /** @var Collection<string, object> */
    protected Collection $extensions;

    /** @var Collection<string, object> */
    protected Collection $bundles;

    /** @var Collection<string, object> */
    protected Collection $purchases;

    /** @var Collection<string, object> */
    protected Collection $installs;

    /** @var Collection<string, object> */
    protected Collection $registryUsers;

    /** @var Collection<string, object> */
    protected Collection $developers;

    /** @var Collection<string, object> */
    protected Collection $companies;

    /** @var Collection<string, object> */
    protected Collection $users;

    /** @var Collection<string, object> */
    protected Collection $files;

    /** @var Collection<string, Collection<int, object>> extension uuid => screenshot file rows */
    protected Collection $screenshots;

    /** @var Collection<string, object> */
    protected Collection $categories;

    /** @var array<string, string> source category uuid => category uuid */
    protected array $categoryMap = [];

    /** @var array<string, Organization> company uuid => organization */
    protected array $companyOrganizations = [];

    /** @var array<string, Organization> developer account uuid => personal organization */
    protected array $developerOrganizations = [];

    /** @var array<string, Account> developer account uuid => account */
    protected array $developerAccounts = [];

    /** @var array<string, Account> user uuid => account */
    protected array $userAccounts = [];

    /** @var array<string, Extension> source extension uuid => extension */
    protected array $extensionMap = [];

    /** @var array<string, Release> source bundle uuid => release */
    protected array $releaseMap = [];

    /** @var array<string, Instance> company uuid => synthetic instance */
    protected array $instances = [];

    public function __construct(
        protected Connection $source,
        protected string $sourceDisk,
        protected string $targetDisk,
        protected bool $dryRun = false,
    ) {
        $this->report = new ImportReport($dryRun);
    }

    public function run(): ImportReport
    {
        $this->loadSource();

        DB::beginTransaction();

        try {
            $this->importCategories();
            $this->importDeveloperAccounts();
            $this->importCompanies();
            $this->importCloudUsers();
            $this->importExtensions();
            $this->importReleases();
            $this->importPurchases();
            $this->importInstalls();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $this->dryRun ? DB::rollBack() : DB::commit();

        return $this->report;
    }

    // ---------------------------------------------------------------------
    // Source
    // ---------------------------------------------------------------------

    protected function loadSource(): void
    {
        $this->extensions = $this->rows('registry_extensions');
        $this->bundles = $this->rows('registry_extension_bundles');
        $this->purchases = $this->rows('registry_extension_purchases');
        $this->installs = $this->rows('registry_extension_installs');
        $this->registryUsers = $this->rows('registry_users');
        $this->developers = $this->rows('registry_developer_accounts');

        $companyUuids = collect()
            ->merge($this->extensions->pluck('company_uuid'))
            ->merge($this->extensions->filter(fn ($e) => ($e->publisher_type ?? 'cloud') !== 'developer')->pluck('publisher_uuid'))
            ->merge($this->registryUsers->pluck('company_uuid'))
            ->merge($this->purchases->pluck('company_uuid'))
            ->merge($this->purchases->filter(fn ($p) => $this->purchaserKind($p) === 'company')->pluck('purchaser_uuid'))
            ->merge($this->installs->pluck('company_uuid'));
        $this->companies = $this->rowsWhereIn('companies', 'uuid', $companyUuids);

        $userUuids = collect()
            ->merge($this->registryUsers->pluck('user_uuid'))
            ->merge($this->extensions->pluck('created_by_uuid'))
            ->merge($this->bundles->pluck('created_by_uuid'));
        $this->users = $this->rowsWhereIn('users', 'uuid', $userUuids);

        $this->files = $this->rowsWhereIn('files', 'uuid', collect()
            ->merge($this->extensions->pluck('icon_uuid'))
            ->merge($this->bundles->pluck('bundle_uuid')));

        $this->screenshots = $this->hasTable('files') && $this->extensions->isNotEmpty()
            ? $this->rowsWhereIn('files', 'subject_uuid', $this->extensions->keys(), false)
                ->filter(fn ($f) => ($f->type ?? null) === 'extension_screenshot')
                ->groupBy('subject_uuid')
            : collect();

        $this->categories = $this->rowsWhereIn('categories', 'uuid', $this->extensions->pluck('category_uuid'));
    }

    protected function hasTable(string $table): bool
    {
        return $this->source->getSchemaBuilder()->hasTable($table);
    }

    /**
     * @return Collection<string, object>
     */
    protected function rows(string $table): Collection
    {
        if (! $this->hasTable($table)) {
            $this->report->source[$table] = 0;

            return collect();
        }

        $rows = $this->source->table($table)->orderBy('id')->get()->keyBy('uuid');
        $this->report->source[$table] = $rows->count();

        return $rows;
    }

    /**
     * @return Collection<string, object>
     */
    protected function rowsWhereIn(string $table, string $column, Collection $values, bool $keyByUuid = true): Collection
    {
        $values = $values->filter()->unique()->values();
        if (! $this->hasTable($table) || $values->isEmpty()) {
            $this->report->source[$table] = $this->report->source[$table] ?? 0;

            return collect();
        }

        $rows = collect();
        foreach ($values->chunk(500) as $chunk) {
            $rows = $rows->merge($this->source->table($table)->whereIn($column, $chunk->all())->get());
        }

        $this->report->source[$table] = ($this->report->source[$table] ?? 0) + $rows->count();

        return $keyByUuid ? $rows->keyBy('uuid') : $rows;
    }

    // ---------------------------------------------------------------------
    // Categories
    // ---------------------------------------------------------------------

    protected function importCategories(): void
    {
        foreach ($this->categories as $row) {
            $category = Category::withTrashed()->where('source_uuid', $row->uuid)->first()
                ?? ($row->internal_id ? Category::withTrashed()->where('internal_id', $row->internal_id)->first() : null)
                ?? ($row->slug ? Category::withTrashed()->where('slug', $row->slug)->first() : null);

            if ($category) {
                // Seeded categories keep their own names; only the link to the source is recorded.
                $this->save($category, ['source_uuid' => $row->uuid], 'categories');
            } else {
                $category = $this->save(new Category, [
                    'source_uuid' => $row->uuid,
                    'slug' => $this->uniqueSlug(Category::class, $row->slug ?: $row->name ?: 'category'),
                    'name' => $row->name ?: 'Uncategorised',
                    'internal_id' => $row->internal_id,
                    'description' => $row->description ?? null,
                    'icon' => $row->icon ?? null,
                    'tags' => $this->json($row->tags ?? null),
                ], 'categories');
            }

            $this->categoryMap[$row->uuid] = $category->uuid;
        }
    }

    // ---------------------------------------------------------------------
    // Accounts, organizations, memberships
    // ---------------------------------------------------------------------

    /**
     * `registry_developer_accounts` → account + personal organization (owner).
     */
    protected function importDeveloperAccounts(): void
    {
        foreach ($this->developers as $row) {
            if (blank($row->email ?? null)) {
                $this->report->anomaly('user_without_email', ['source' => 'registry_developer_accounts', 'uuid' => $row->uuid, 'username' => $row->username ?? null]);
                $this->report->count('accounts', ImportReport::SKIPPED);

                continue;
            }

            $status = match ($row->status ?? null) {
                'active' => Account::STATUS_ACTIVE,
                'suspended' => Account::STATUS_SUSPENDED,
                'pending_verification' => Account::STATUS_PENDING_VERIFICATION,
                default => null,
            };
            if ($status === null) {
                $this->report->anomaly('unknown_status', ['table' => 'registry_developer_accounts', 'uuid' => $row->uuid, 'status' => $row->status ?? null, 'mapped_to' => Account::STATUS_PENDING_VERIFICATION]);
                $status = Account::STATUS_PENDING_VERIFICATION;
            }

            $account = $this->importAccount($row->uuid, 'registry_developer_accounts', [
                'email' => Str::lower(trim($row->email)),
                'name' => $row->name ?? null,
                'username' => $row->username ?? null,
                'avatar_url' => $row->avatar_url ?? null,
                'email_verified_at' => $this->date($row->email_verified_at ?? null),
                'status' => $status,
                'created_at' => $this->date($row->created_at ?? null),
                'deleted_at' => $this->date($row->deleted_at ?? null),
                'meta' => array_filter([
                    'source' => 'registry_developer_accounts',
                    'github_username' => $row->github_username ?? null,
                ]),
            ], $row->password ?? null);

            $this->developerAccounts[$row->uuid] = $account;

            $organization = Organization::withTrashed()->where('source_uuid', $row->uuid)->first() ?? new Organization;
            $organization = $this->save($organization, [
                'source_uuid' => $row->uuid,
                'slug' => $organization->slug ?? $this->uniqueSlug(Organization::class, $row->username ?: Str::before($row->email, '@')),
                'name' => $row->name ?: $row->username,
                'website' => $row->website ?? null,
                'bio' => $row->bio ?? null,
                'avatar_url' => $row->avatar_url ?? null,
                'is_personal' => true,
                'created_at' => $this->date($row->created_at ?? null),
                'meta' => ['source' => 'registry_developer_accounts'],
            ], 'organizations');

            $this->developerOrganizations[$row->uuid] = $organization;
            $this->membership($organization, $account, Membership::ROLE_OWNER, $row->uuid);
        }
    }

    /**
     * Every company the registry tables reference → organization. Companies are the
     * publishers, buyers and installers of the cloud registry.
     */
    protected function importCompanies(): void
    {
        foreach ($this->companies as $row) {
            $organization = Organization::withTrashed()->where('source_uuid', $row->uuid)->first() ?? new Organization;
            $name = $row->name ?: 'Company '.($row->public_id ?? Str::limit($row->uuid, 8, ''));

            $this->companyOrganizations[$row->uuid] = $this->save($organization, [
                'source_uuid' => $row->uuid,
                'slug' => $organization->slug ?? $this->uniqueSlug(Organization::class, $row->slug ?: $name),
                'name' => $name,
                'website' => $row->website_url ?? null,
                'bio' => $row->description ?? null,
                'is_personal' => false,
                'stripe_connect_id' => $row->stripe_connect_id ?? null,
                'created_at' => $this->date($row->created_at ?? null),
                'deleted_at' => $this->date($row->deleted_at ?? null),
                'meta' => array_filter(['source' => 'companies', 'public_id' => $row->public_id ?? null]),
            ], 'organizations');
        }
    }

    /**
     * Cloud `registry_users` (one row per token) + `users` → one account per user and a
     * membership in the company's organization: owner for the company owner, else publisher.
     */
    protected function importCloudUsers(): void
    {
        $pairs = $this->registryUsers
            ->filter(fn ($r) => ($r->account_type ?? 'cloud') !== 'developer')
            ->unique(fn ($r) => ($r->company_uuid ?? '').'|'.($r->user_uuid ?? ''));

        foreach ($pairs as $row) {
            $user = $this->users->get($row->user_uuid ?? '');
            $organization = $this->companyOrganizations[$row->company_uuid ?? ''] ?? null;

            if (! $user) {
                $this->report->anomaly('missing_reference', ['table' => 'registry_users', 'uuid' => $row->uuid, 'missing' => 'users', 'reference' => $row->user_uuid ?? null]);
                $this->report->count('accounts', ImportReport::SKIPPED);

                continue;
            }

            if (blank($user->email ?? null)) {
                $this->report->anomaly('user_without_email', ['source' => 'users', 'uuid' => $user->uuid, 'name' => $user->name ?? null]);
                $this->report->count('accounts', ImportReport::SKIPPED);

                continue;
            }

            $account = $this->userAccounts[$user->uuid] ??= $this->importAccount($user->uuid, 'users', [
                'email' => Str::lower(trim($user->email)),
                'name' => $user->name ?? null,
                'username' => $user->username ?? null,
                'email_verified_at' => $this->date($user->email_verified_at ?? null),
                'status' => Account::STATUS_ACTIVE,
                'timezone' => $user->timezone ?? null,
                'created_at' => $this->date($user->created_at ?? null),
                'meta' => array_filter(['source' => 'users', 'public_id' => $user->public_id ?? null]),
            ], $user->password ?? null);

            if (! $organization) {
                $this->report->anomaly('missing_reference', ['table' => 'registry_users', 'uuid' => $row->uuid, 'missing' => 'companies', 'reference' => $row->company_uuid ?? null]);

                continue;
            }

            $owner = ($this->companies->get($row->company_uuid)->owner_uuid ?? null) === $user->uuid;
            $this->membership($organization, $account, $owner ? Membership::ROLE_OWNER : Membership::ROLE_PUBLISHER, $row->uuid);
        }
    }

    /**
     * Find or create the account for a source person, matched on `source_uuid`. A person
     * whose email already belongs to another account (created in the service, or imported
     * from the other source table) is mapped onto that account without changing it.
     * Password hashes are copied verbatim (bcrypt, as core-api).
     */
    protected function importAccount(string $sourceUuid, string $sourceTable, array $attributes, ?string $passwordHash): Account
    {
        $account = Account::withTrashed()->where('source_uuid', $sourceUuid)->first();

        if (! $account && ($existing = Account::withTrashed()->where('email', $attributes['email'])->first())) {
            // Same email as an account created in the service or imported from another
            // source: map onto it and leave it untouched.
            $this->report->anomaly('merged_account', [
                'email' => $attributes['email'], 'source' => $sourceTable, 'uuid' => $sourceUuid,
                'into' => $existing->source_uuid === null ? 'service account' : 'imported account',
            ]);
            $this->report->count('accounts', ImportReport::UNCHANGED);

            return $existing;
        }

        $account ??= new Account;

        // Usernames are set once; a taken username is left empty rather than renamed.
        $username = $attributes['username'] ?? null;
        unset($attributes['username']);
        if ($account->exists) {
            $username = $account->username;
        } elseif ($username !== null && Account::withTrashed()->where('username', $username)->exists()) {
            $this->report->anomaly('username_taken', ['username' => $username, 'source' => $sourceTable, 'uuid' => $sourceUuid]);
            $username = null;
        }

        $account->forceFill(array_merge($attributes, ['source_uuid' => $sourceUuid, 'username' => $username]));

        // Bypass the `hashed` cast: the hash is copied as-is and its cost may exceed BCRYPT_ROUNDS.
        $account->setRawAttributes(array_merge($account->getAttributes(), ['password' => $passwordHash ?: null]));

        return $this->persist($account, 'accounts');
    }

    protected function membership(Organization $organization, Account $account, string $role, string $sourceUuid): void
    {
        $membership = Membership::query()
            ->where('organization_uuid', $organization->uuid)
            ->where('account_uuid', $account->uuid)
            ->first();

        if ($membership && $membership->source_uuid === null) {
            // Created in the service; leave it alone.
            $this->report->count('memberships', ImportReport::UNCHANGED);

            return;
        }

        $membership ??= new Membership;
        $membership->forceFill([
            'organization_uuid' => $organization->uuid,
            'account_uuid' => $account->uuid,
            'role' => $role,
            'source_uuid' => $sourceUuid,
        ]);

        $this->persist($membership, 'memberships');
    }

    // ---------------------------------------------------------------------
    // Extensions, prices, packages, icons
    // ---------------------------------------------------------------------

    protected function importExtensions(): void
    {
        $claimed = [ExtensionPackage::KIND_NPM => [], ExtensionPackage::KIND_COMPOSER => []];

        // Live and published listings first, so they win package names and slugs.
        $ordered = $this->extensions->sortBy(fn ($e) => [
            $this->date($e->deleted_at ?? null) ? 1 : 0,
            ($e->status ?? null) === 'published' ? 0 : 1,
            (string) ($e->created_at ?? ''),
            (int) ($e->id ?? 0),
        ]);

        foreach ($ordered as $row) {
            $organization = $this->publisherOrganization($row);
            if (! $organization) {
                $this->report->anomaly('missing_reference', ['table' => 'registry_extensions', 'uuid' => $row->uuid, 'name' => $row->name, 'missing' => 'publisher', 'reference' => $row->publisher_uuid ?? $row->company_uuid ?? null]);
                $this->report->count('extensions', ImportReport::SKIPPED);

                continue;
            }

            $extensionBundles = $this->bundles->where('extension_uuid', $row->uuid);
            $packages = $this->packageNames($row, $extensionBundles);

            $status = $this->mapExtensionStatus($row);
            if ($extensionBundles->isEmpty() && $packages === []) {
                $status = Extension::STATUS_DRAFT;
            }
            if ($packages === []) {
                $this->report->anomaly('extension_without_packages', [
                    'uuid' => $row->uuid, 'name' => $row->name, 'bundles' => $extensionBundles->count(), 'imported_status' => $status,
                ]);
            }

            if ($this->bool($row->subscription_required ?? false)) {
                $this->report->anomaly('subscription', ['table' => 'registry_extensions', 'uuid' => $row->uuid, 'name' => $row->name]);
            }

            $extension = Extension::withTrashed()->where('source_uuid', $row->uuid)->first() ?? new Extension;
            $slug = $extension->slug;
            if ($slug === null) {
                $base = Str::slug($row->slug ?: $row->name) ?: 'extension';
                $slug = $this->uniqueSlug(Extension::class, $base);
                if ($slug !== $base) {
                    $this->report->anomaly('slug_renamed', ['table' => 'extensions', 'uuid' => $row->uuid, 'from' => $base, 'to' => $slug]);
                }
            }

            $extension = $this->save($extension, [
                'source_uuid' => $row->uuid,
                'organization_uuid' => $organization->uuid,
                'category_uuid' => $this->categoryMap[$row->category_uuid ?? ''] ?? null,
                'created_by_account_uuid' => ($this->userAccounts[$row->created_by_uuid ?? ''] ?? null)?->uuid,
                'slug' => $slug,
                'name' => $row->name,
                'subtitle' => $row->subtitle ?? null,
                'description' => $row->description ?? null,
                'promotional_text' => $row->promotional_text ?? null,
                'tags' => $this->json($row->tags ?? null),
                'languages' => $this->json($row->languages ?? null),
                'hosting' => $this->bool($row->self_managed ?? false) ? Extension::HOSTING_SELF_HOSTED : Extension::HOSTING_BOTH,
                'status' => $status,
                'website_url' => $row->website_url ?? null,
                'repo_url' => $row->repo_url ?? null,
                'support_url' => $row->support_url ?? null,
                'privacy_url' => $row->privacy_policy_url ?? null,
                'tos_url' => $row->tos_url ?? null,
                'copyright' => $row->copyright ?? null,
                'fa_icon' => $row->fa_icon ?? null,
                'published_at' => $this->date($row->published_at ?? null),
                'created_at' => $this->date($row->created_at ?? null),
                'deleted_at' => $this->date($row->deleted_at ?? null),
                'meta' => array_filter([
                    'source' => array_filter([
                        'public_id' => $row->public_id ?? null,
                        'status' => $row->status ?? null,
                        'core_extension' => $this->bool($row->core_extension ?? false) ?: null,
                        'primary_language' => $row->primary_language ?? null,
                    ]),
                ]),
            ], 'extensions');

            $this->extensionMap[$row->uuid] = $extension;

            $this->importPrice($row, $extension);
            $this->importPackages($row, $extension, $packages, $claimed);
            $this->importIcon($row, $extension);
            $this->importScreenshots($row, $extension);
        }
    }

    protected function publisherOrganization(object $row): ?Organization
    {
        if (($row->publisher_type ?? 'cloud') === 'developer') {
            return $this->developerOrganizations[$row->publisher_uuid ?? ''] ?? null;
        }

        return $this->companyOrganizations[($row->publisher_uuid ?? null) ?: ($row->company_uuid ?? '')] ?? null;
    }

    /**
     * npm and Composer names from the current bundle's package.json / composer.json, then
     * from the newest bundle that has them, then from the Phase 0 `package_name` column.
     *
     * @return array<string, string> kind => name
     */
    protected function packageNames(object $row, Collection $bundles): array
    {
        $candidates = collect([$bundles->get($row->current_bundle_uuid ?? '')])
            ->merge($bundles->sortByDesc(fn ($b) => [(string) ($b->created_at ?? ''), (int) ($b->id ?? 0)]))
            ->filter();

        $names = [];
        foreach ($candidates as $bundle) {
            $meta = $this->json($bundle->meta ?? null) ?? [];
            $names[ExtensionPackage::KIND_NPM] ??= $this->bundleFile($meta, 'package.json', 'name') ?: null;
            $names[ExtensionPackage::KIND_COMPOSER] ??= $this->bundleFile($meta, 'composer.json', 'name') ?: null;
        }

        $legacy = trim((string) ($row->package_name ?? ''));
        if ($legacy !== '') {
            $kind = str_starts_with($legacy, '@') || ! str_contains($legacy, '/') ? ExtensionPackage::KIND_NPM : ExtensionPackage::KIND_COMPOSER;
            $names[$kind] ??= $legacy;
        }

        return array_filter($names);
    }

    protected function mapExtensionStatus(object $row): string
    {
        $status = match ($row->status ?? null) {
            'pending' => Extension::STATUS_DRAFT,
            'awaiting_review', 'in_review' => Extension::STATUS_IN_REVIEW,
            'approved' => Extension::STATUS_APPROVED,
            'rejected' => Extension::STATUS_REJECTED,
            'published' => Extension::STATUS_PUBLISHED,
            'unpublished' => Extension::STATUS_UNPUBLISHED,
            default => null,
        };

        if ($status === null) {
            $this->report->anomaly('unknown_status', ['table' => 'registry_extensions', 'uuid' => $row->uuid, 'status' => $row->status ?? null, 'mapped_to' => Extension::STATUS_DRAFT]);
        }

        return $status ?? Extension::STATUS_DRAFT;
    }

    protected function importPrice(object $row, Extension $extension): void
    {
        $amount = (int) ($row->price ?? 0);
        $paid = $this->bool($row->payment_required ?? false) && $amount > 0;
        $price = Price::query()->where('extension_uuid', $extension->uuid)->first() ?? new Price;

        $this->save($price, [
            'source_uuid' => $row->uuid,
            'extension_uuid' => $extension->uuid,
            'model' => $paid ? Price::MODEL_ONE_TIME : Price::MODEL_FREE,
            'amount' => $paid ? $amount : 0,
            'currency' => Str::upper($row->currency ?? '') ?: 'USD',
            'sale_amount' => ($row->sale_price ?? null) !== null ? (int) $row->sale_price : null,
            'on_sale' => $this->bool($row->on_sale ?? false),
            'stripe_product_id' => $row->stripe_product_id ?? null,
        ], 'prices');
    }

    /**
     * @param  array<string, string>  $packages
     * @param  array<string, array<string, string>>  $claimed
     */
    protected function importPackages(object $row, Extension $extension, array $packages, array &$claimed): void
    {
        foreach ($packages as $kind => $name) {
            $owner = $claimed[$kind][$name] ?? null;
            $existing = ExtensionPackage::query()->where('kind', $kind)->where('name', $name)->first();

            if (($owner !== null && $owner !== $extension->uuid) || ($existing && $existing->extension_uuid !== $extension->uuid)) {
                $this->report->anomaly('duplicate_package_name', [
                    'kind' => $kind, 'name' => $name, 'extension' => $row->name, 'uuid' => $row->uuid,
                    'kept_by' => Extension::withTrashed()->find($owner ?? $existing?->extension_uuid)?->slug,
                ]);
                $this->report->count('extension_packages', ImportReport::SKIPPED);

                continue;
            }

            $claimed[$kind][$name] = $extension->uuid;

            $this->save($existing ?? new ExtensionPackage, [
                'source_uuid' => $row->uuid,
                'extension_uuid' => $extension->uuid,
                'kind' => $kind,
                'name' => $name,
            ], 'extension_packages');
        }
    }

    protected function importIcon(object $row, Extension $extension): void
    {
        if (blank($row->icon_uuid ?? null)) {
            return;
        }

        $file = $this->copyFile($this->files->get($row->icon_uuid), File::TYPE_ICON, 'icons', $extension);
        if (! $file) {
            $this->report->anomaly('icon_missing', ['extension' => $row->name, 'uuid' => $row->uuid, 'file' => $row->icon_uuid]);

            return;
        }

        if ($extension->icon_file_uuid !== $file->uuid) {
            $extension->forceFill(['icon_file_uuid' => $file->uuid])->save();
        }
    }

    protected function importScreenshots(object $row, Extension $extension): void
    {
        foreach ($this->screenshots->get($row->uuid, collect()) as $screenshot) {
            if (! $this->copyFile($screenshot, File::TYPE_SCREENSHOT, 'screenshots', $extension)) {
                $this->report->anomaly('icon_missing', ['extension' => $row->name, 'uuid' => $row->uuid, 'file' => $screenshot->uuid]);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Releases and tarballs
    // ---------------------------------------------------------------------

    protected function importReleases(): void
    {
        foreach ($this->bundles->groupBy('extension_uuid') as $extensionUuid => $bundles) {
            $extension = $this->extensionMap[$extensionUuid] ?? null;
            $sourceExtension = $this->extensions->get($extensionUuid);

            if (! $extension) {
                foreach ($bundles as $bundle) {
                    if (! $sourceExtension) {
                        $this->report->anomaly('missing_reference', ['table' => 'registry_extension_bundles', 'uuid' => $bundle->uuid, 'missing' => 'registry_extensions', 'reference' => $extensionUuid]);
                    }
                    $this->report->count('releases', ImportReport::SKIPPED);
                }

                continue;
            }

            // The current bundle, then the newest, keep a version that appears twice.
            $ordered = $bundles->sortBy(fn ($b) => [
                $b->uuid === ($sourceExtension->current_bundle_uuid ?? null) ? 0 : 1,
                -strtotime((string) ($b->created_at ?? '1970-01-01')),
                -(int) ($b->id ?? 0),
            ]);

            $seen = [];
            foreach ($ordered as $bundle) {
                $meta = $this->json($bundle->meta ?? null) ?? [];
                $version = ltrim((string) ($this->bundleFile($meta, 'extension.json', 'version') ?: ($bundle->version ?? null) ?: $this->bundleFile($meta, 'package.json', 'version') ?: ''), 'vV');

                if ($version === '') {
                    $this->report->anomaly('bundle_without_version', ['extension' => $extension->slug, 'bundle' => $bundle->bundle_id ?? $bundle->uuid]);
                    $this->report->count('releases', ImportReport::SKIPPED);

                    continue;
                }

                if (isset($seen[$version])) {
                    $this->report->anomaly('duplicate_version', ['extension' => $extension->slug, 'version' => $version, 'bundle' => $bundle->bundle_id ?? $bundle->uuid, 'kept' => $seen[$version]]);
                    $this->report->count('releases', ImportReport::SKIPPED);

                    continue;
                }
                $seen[$version] = $bundle->bundle_id ?? $bundle->uuid;

                $this->importRelease($bundle, $meta, $version, $extension, $sourceExtension);
            }
        }
    }

    protected function importRelease(object $bundle, array $meta, string $version, Extension $extension, object $sourceExtension): void
    {
        $release = Release::withTrashed()->where('source_uuid', $bundle->uuid)->first();

        $conflict = Release::withTrashed()
            ->where('extension_uuid', $extension->uuid)
            ->where('version', $version)
            ->when($release, fn ($q) => $q->whereKeyNot($release->getKey()))
            ->first();
        if ($conflict) {
            $this->report->anomaly('conflict', ['table' => 'releases', 'extension' => $extension->slug, 'version' => $version, 'bundle' => $bundle->bundle_id ?? $bundle->uuid]);
            $this->report->count('releases', ImportReport::SKIPPED);

            return;
        }

        $release ??= new Release;
        $status = $this->mapReleaseStatus($bundle);
        $isCurrent = $bundle->uuid === ($sourceExtension->current_bundle_uuid ?? null);

        $publishedAt = null;
        if ($status === Release::STATUS_PUBLISHED) {
            $publishedAt = ($isCurrent ? $this->date($sourceExtension->published_at ?? null) : null)
                ?? $this->date($bundle->updated_at ?? null)
                ?? $this->date($bundle->created_at ?? null);
        }

        // Save first so the tarball file can point at the release as its subject.
        $attributes = [
            'source_uuid' => $bundle->uuid,
            'extension_uuid' => $extension->uuid,
            'created_by_account_uuid' => ($this->userAccounts[$bundle->created_by_uuid ?? ''] ?? null)?->uuid,
            'version' => $version,
            'channel' => Semver::normalize($version) !== null && str_contains($version, '-') ? Release::CHANNEL_BETA : Release::CHANNEL_STABLE,
            'manifest' => array_merge($this->bundleFile($meta, 'extension.json') ?: [], array_filter(['bundle_number' => $bundle->bundle_number ?? null])),
            'compat' => $this->compat($meta),
            'published_at' => $publishedAt,
            'created_at' => $this->date($bundle->created_at ?? null),
            'deleted_at' => $this->date($bundle->deleted_at ?? null),
        ];

        $tarball = $this->files->get($bundle->bundle_uuid ?? '');
        $reason = match (true) {
            blank($bundle->bundle_uuid ?? null) => 'bundle has no file',
            $tarball === null => 'file row not found',
            blank($tarball->path ?? null) => 'file has no path',
            ! $this->sourceExists($tarball->path) => 'object not found on source disk',
            default => null,
        };

        $release->forceFill($attributes);
        if (! $release->exists) {
            $release->forceFill(['status' => $status])->save();
        }

        $file = $reason === null ? $this->copyFile($tarball, File::TYPE_SOURCE_BUNDLE, 'tarballs', $release) : null;
        if ($reason === null && ! $file) {
            $reason = 'copy failed';
        }

        $releaseMeta = array_filter([
            'source' => array_filter([
                'bundle_id' => $bundle->bundle_id ?? null,
                'public_id' => $bundle->public_id ?? null,
                'bundle_number' => $bundle->bundle_number ?? null,
                'status' => $bundle->status ?? null,
            ]),
            'package_json' => $this->bundleFile($meta, 'package.json'),
            'composer_json' => $this->bundleFile($meta, 'composer.json'),
            'source_bundle_file_uuid' => $file?->uuid,
            'import_yank_reason' => $reason,
        ]);

        if ($reason !== null) {
            $this->report->anomaly('tarball_missing', [
                'extension' => $extension->slug, 'version' => $version, 'bundle' => $bundle->bundle_id ?? $bundle->uuid,
                'path' => $tarball->path ?? null, 'reason' => $reason,
            ]);
        }

        $release->forceFill([
            'status' => $reason !== null ? Release::STATUS_YANKED : $status,
            'yanked_at' => $reason !== null ? ($release->yanked_at ?? now()) : null,
            'size_bytes' => $file?->size ?? (int) ($tarball->file_size ?? 0),
            'checksum' => $file?->checksum,
            'meta' => $releaseMeta,
        ]);

        $this->releaseMap[$bundle->uuid] = $this->persist($release, 'releases', $release->wasRecentlyCreated);
    }

    protected function mapReleaseStatus(object $bundle): string
    {
        $status = match ($bundle->status ?? null) {
            'pending' => Release::STATUS_UPLOADED,
            'awaiting_review', 'in_review' => Release::STATUS_IN_REVIEW,
            'approved' => Release::STATUS_APPROVED,
            'published' => Release::STATUS_PUBLISHED,
            'rejected' => Release::STATUS_REJECTED,
            default => null,
        };

        if ($status === null) {
            $this->report->anomaly('unknown_status', ['table' => 'registry_extension_bundles', 'uuid' => $bundle->uuid, 'status' => $bundle->status ?? null, 'mapped_to' => Release::STATUS_UPLOADED]);
        }

        return $status ?? Release::STATUS_UPLOADED;
    }

    /**
     * `compat` from the manifest when it declares console or API ranges.
     */
    protected function compat(array $meta): ?array
    {
        $compat = $this->bundleFile($meta, 'extension.json', 'compat') ?? $this->bundleFile($meta, 'extension.json', 'fleetbase');
        if (! is_array($compat)) {
            return null;
        }

        $compat = array_filter(['console' => $compat['console'] ?? null, 'api' => $compat['api'] ?? null]);

        return $compat ?: null;
    }

    // ---------------------------------------------------------------------
    // Purchases and licences
    // ---------------------------------------------------------------------

    protected function importPurchases(): void
    {
        $seen = [];

        foreach ($this->purchases as $row) {
            $extension = $this->extensionMap[$row->extension_uuid ?? ''] ?? null;
            $buyer = $this->buyerOrganization($row);

            if (! $extension || ! $buyer) {
                $this->report->anomaly('missing_reference', [
                    'table' => 'registry_extension_purchases', 'uuid' => $row->uuid,
                    'missing' => $extension ? 'buyer' : 'extension',
                    'reference' => $extension ? (($row->purchaser_uuid ?? null) ?: ($row->company_uuid ?? null)) : ($row->extension_uuid ?? null),
                ]);
                $this->report->count('purchases', ImportReport::SKIPPED);

                continue;
            }

            if ($this->bool($row->is_subcription ?? false)) {
                $this->report->anomaly('subscription', ['table' => 'registry_extension_purchases', 'uuid' => $row->uuid, 'extension' => $extension->slug]);
            }

            $key = $extension->uuid.'|'.$buyer->uuid;
            if (isset($seen[$key])) {
                $this->report->anomaly('duplicate_purchase', ['uuid' => $row->uuid, 'extension' => $extension->slug, 'buyer' => $buyer->slug]);
            }
            $seen[$key] = true;

            $sessionId = $row->stripe_checkout_session_id ?? null;
            if ($sessionId && Purchase::withTrashed()->where('stripe_checkout_session_id', $sessionId)->where(fn ($q) => $q->whereNull('source_uuid')->orWhere('source_uuid', '!=', $row->uuid))->exists()) {
                $this->report->anomaly('conflict', ['table' => 'purchases', 'uuid' => $row->uuid, 'stripe_checkout_session_id' => $sessionId]);
                $sessionId = null;
            }

            $extension->loadMissing('price');
            $amount = ($row->locked_price ?? null) !== null ? (int) $row->locked_price : (int) ($extension->price?->amount ?? 0);
            $currency = $extension->price?->currency ?? 'USD';
            $deletedAt = $this->date($row->deleted_at ?? null);

            $purchase = $this->save(Purchase::withTrashed()->where('source_uuid', $row->uuid)->first() ?? new Purchase, [
                'source_uuid' => $row->uuid,
                'extension_uuid' => $extension->uuid,
                'buyer_organization_uuid' => $buyer->uuid,
                'price_snapshot' => ['model' => Price::MODEL_ONE_TIME, 'amount' => $amount, 'currency' => $currency, 'source' => 'import'],
                'amount_total' => $amount,
                'fee_amount' => 0,
                'net_amount' => 0,
                'currency' => $currency,
                'stripe_checkout_session_id' => $sessionId,
                'stripe_payment_intent_id' => $row->stripe_payment_intent_id ?? null,
                'status' => Purchase::STATUS_PAID,
                'created_at' => $this->date($row->created_at ?? null),
                'deleted_at' => $deletedAt,
                'meta' => ['source' => 'registry_extension_purchases', 'fees_unknown' => true],
            ], 'purchases');

            $licence = Licence::withTrashed()->where('source_uuid', $row->uuid)->first() ?? new Licence;
            $this->save($licence, [
                'source_uuid' => $row->uuid,
                'extension_uuid' => $extension->uuid,
                'organization_uuid' => $buyer->uuid,
                'purchase_uuid' => $purchase->uuid,
                'kind' => Licence::KIND_PAID,
                // Seat counts are set once; staff may raise them in the service later.
                'max_activations' => $licence->exists ? $licence->max_activations : self::DEFAULT_PAID_SEATS,
                'status' => $deletedAt ? Licence::STATUS_REVOKED : Licence::STATUS_ACTIVE,
                'created_at' => $this->date($row->created_at ?? null),
            ], 'licences');
        }
    }

    protected function buyerOrganization(object $row): ?Organization
    {
        return match ($this->purchaserKind($row)) {
            'developer' => $this->developerOrganizations[$row->purchaser_uuid ?? ''] ?? null,
            'company' => $this->companyOrganizations[$row->purchaser_uuid ?? ''] ?? null,
            default => $this->companyOrganizations[$row->company_uuid ?? ''] ?? null,
        };
    }

    protected function purchaserKind(object $row): ?string
    {
        $type = (string) ($row->purchaser_type ?? '');
        if ($type === '' || blank($row->purchaser_uuid ?? null)) {
            return null;
        }

        return str_contains($type, 'Developer') ? 'developer' : 'company';
    }

    // ---------------------------------------------------------------------
    // Instances, installs, activations
    // ---------------------------------------------------------------------

    /**
     * One synthetic cloud instance per company; soft-deleted installs become
     * `uninstalled` rows. Live installs claim a licence seat on the company's
     * instance (the paid licence from the purchase, or an automatic free licence).
     */
    protected function importInstalls(): void
    {
        $activate = app(ActivateLicence::class);
        $deactivate = app(DeactivateLicence::class);

        $groups = $this->installs->groupBy(fn ($i) => ($i->company_uuid ?? '').'|'.($i->extension_uuid ?? ''));

        foreach ($groups as $rows) {
            $first = $rows->first();
            $extension = $this->extensionMap[$first->extension_uuid ?? ''] ?? null;
            $instance = $this->syntheticInstance($first->company_uuid ?? null);

            if (! $extension || ! $instance) {
                foreach ($rows as $row) {
                    $this->report->anomaly('missing_reference', [
                        'table' => 'registry_extension_installs', 'uuid' => $row->uuid,
                        'missing' => $extension ? 'companies' : 'extension',
                        'reference' => $extension ? ($row->company_uuid ?? null) : ($row->extension_uuid ?? null),
                    ]);
                    $this->report->count('installs', ImportReport::SKIPPED);
                }

                continue;
            }

            $release = $this->installedRelease($first->extension_uuid, $extension);
            $live = $rows->filter(fn ($r) => ! $this->date($r->deleted_at ?? null))
                ->sortByDesc(fn ($r) => [(string) ($r->created_at ?? ''), (int) ($r->id ?? 0)]);
            $current = $live->first();

            if ($live->count() > 1) {
                $this->report->anomaly('duplicate_install', ['extension' => $extension->slug, 'company' => $first->company_uuid, 'live_rows' => $live->count()]);
            }

            $currentInstall = null;
            foreach ($rows as $row) {
                $installed = $current !== null && $row->uuid === $current->uuid;
                $createdAt = $this->date($row->created_at ?? null);

                $install = $this->save(Install::query()->where('source_uuid', $row->uuid)->first() ?? new Install, [
                    'source_uuid' => $row->uuid,
                    'instance_uuid' => $instance->uuid,
                    'extension_uuid' => $extension->uuid,
                    'release_uuid' => $release?->uuid,
                    'status' => $installed ? Install::STATUS_INSTALLED : Install::STATUS_UNINSTALLED,
                    'installed_by' => 'cloud-import',
                    'started_at' => $createdAt,
                    'finished_at' => $installed ? $createdAt : ($this->date($row->deleted_at ?? null) ?? $this->date($row->updated_at ?? null) ?? $createdAt),
                    'installed_at' => $createdAt,
                    'uninstalled_at' => $installed ? null : ($this->date($row->deleted_at ?? null) ?? $this->date($row->updated_at ?? null) ?? $createdAt),
                    'created_at' => $createdAt,
                ], 'installs');

                if ($installed) {
                    $currentInstall = $install;
                }
            }

            if (! $currentInstall) {
                $closed = $deactivate($instance, $extension);
                if ($closed > 0) {
                    $this->report->count('licence_activations', ImportReport::UPDATED, $closed);
                }

                continue;
            }

            $licencesBefore = Licence::query()->where('extension_uuid', $extension->uuid)->where('organization_uuid', $instance->organization_uuid)->count();

            try {
                $activation = $activate($instance, $extension, $release);
            } catch (LicenceRequiredException $e) {
                $this->report->anomaly('install_without_licence', ['extension' => $extension->slug, 'company' => $first->company_uuid, 'reason' => $e->getMessage()]);

                continue;
            }

            if (Licence::query()->where('extension_uuid', $extension->uuid)->where('organization_uuid', $instance->organization_uuid)->count() > $licencesBefore) {
                $this->report->count('licences', ImportReport::CREATED);
            }

            if ($activation->wasRecentlyCreated) {
                $activation->forceFill(['source_uuid' => $current->uuid])->save();
                $this->report->count('licence_activations', ImportReport::CREATED);
            } else {
                $this->report->count('licence_activations', ImportReport::UNCHANGED);
            }
        }
    }

    protected function syntheticInstance(?string $companyUuid): ?Instance
    {
        if ($companyUuid === null || $companyUuid === '') {
            return null;
        }

        if (isset($this->instances[$companyUuid])) {
            return $this->instances[$companyUuid];
        }

        $organization = $this->companyOrganizations[$companyUuid] ?? null;
        if (! $organization) {
            return null;
        }

        $company = $this->companies->get($companyUuid);
        $instance = Instance::withTrashed()->where('source_uuid', $companyUuid)->where('kind', Instance::KIND_CLOUD)->first() ?? new Instance;

        return $this->instances[$companyUuid] = $this->save($instance, [
            'source_uuid' => $companyUuid,
            'organization_uuid' => $organization->uuid,
            'name' => ($company->name ?? $organization->name).' (Fleetbase Cloud)',
            'kind' => Instance::KIND_CLOUD,
            'status' => Instance::STATUS_ACTIVE,
            'meta' => array_filter(['synthetic' => true, 'source' => 'companies', 'company_public_id' => $company->public_id ?? null]),
        ], 'instances');
    }

    /**
     * The release an imported install points at: the extension's current bundle, else
     * its newest published release.
     */
    protected function installedRelease(string $sourceExtensionUuid, Extension $extension): ?Release
    {
        $current = $this->extensions->get($sourceExtensionUuid)->current_bundle_uuid ?? null;
        if ($current && isset($this->releaseMap[$current])) {
            return $this->releaseMap[$current];
        }

        return Compat::latest(collect($this->releaseMap)->filter(fn (Release $r) => $r->extension_uuid === $extension->uuid && $r->isPublished()));
    }

    // ---------------------------------------------------------------------
    // Files
    // ---------------------------------------------------------------------

    protected function sourceExists(string $path): bool
    {
        try {
            return Storage::disk($this->sourceDisk)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Copy a cloud `files` object into service storage with a SHA-256 checksum. Re-runs
     * skip objects already copied with a checksum. A dry run records the file row (rolled
     * back) but copies nothing.
     */
    protected function copyFile(?object $source, string $type, string $prefix, EloquentModel $subject): ?File
    {
        if (! $source || blank($source->path ?? null) || ! $this->sourceExists($source->path)) {
            return null;
        }

        $file = File::withTrashed()->where('source_uuid', $source->uuid)->first() ?? new File;
        $target = trim(config("registry.storage.prefixes.{$prefix}", $prefix), '/').'/imported/'.$source->uuid.'/'.basename($source->path);
        $attributes = [
            'source_uuid' => $source->uuid,
            'subject_type' => $subject->getMorphClass(),
            'subject_uuid' => $subject->getKey(),
            'type' => $type,
            'disk' => $this->targetDisk,
            'path' => $target,
            'name' => basename($source->path),
            'original_filename' => $source->original_filename ?? basename($source->path),
            'mime' => $source->content_type ?? null,
        ];

        $copied = $file->exists && $file->checksum && $file->disk === $this->targetDisk && $file->path === $target
            && Storage::disk($this->targetDisk)->exists($target);

        if ($copied || $this->dryRun) {
            $attributes['size'] = $copied ? $file->size : (int) ($source->file_size ?? 0);

            return $this->save($file, $attributes, 'files');
        }

        [$size, $checksum] = $this->streamCopy($source->path, $target);

        return $this->save($file, array_merge($attributes, ['size' => $size, 'checksum' => 'sha256:'.$checksum]), 'files');
    }

    /**
     * @return array{0: int, 1: string} size in bytes and hex SHA-256
     */
    protected function streamCopy(string $from, string $to): array
    {
        $in = Storage::disk($this->sourceDisk)->readStream($from);
        $buffer = tmpfile();

        try {
            stream_copy_to_stream($in, $buffer);
            $path = stream_get_meta_data($buffer)['uri'];
            $checksum = hash_file('sha256', $path);
            $size = filesize($path);

            rewind($buffer);
            Storage::disk($this->targetDisk)->writeStream($to, $buffer);

            return [(int) $size, $checksum];
        } finally {
            is_resource($in) && fclose($in);
            is_resource($buffer) && fclose($buffer);
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Fill and persist a model, counting the outcome under `$table`.
     *
     * @template T of EloquentModel
     *
     * @param  T  $model
     * @return T
     */
    protected function save(EloquentModel $model, array $attributes, string $table): EloquentModel
    {
        $model->forceFill($attributes);

        return $this->persist($model, $table);
    }

    /**
     * @template T of EloquentModel
     *
     * @param  T  $model
     * @return T
     */
    protected function persist(EloquentModel $model, string $table, bool $created = false): EloquentModel
    {
        $outcome = match (true) {
            $created || ! $model->exists => ImportReport::CREATED,
            $model->isDirty() => ImportReport::UPDATED,
            default => ImportReport::UNCHANGED,
        };

        if ($model->isDirty() || ! $model->exists) {
            $model->save();
        }

        $this->report->count($table, $outcome);

        return $model;
    }

    /**
     * A slug unique among the model's rows (including soft-deleted ones).
     *
     * @param  class-string<Model>  $model
     */
    protected function uniqueSlug(string $model, string $base): string
    {
        $base = Str::slug($base) ?: Str::lower(Str::random(8));
        $candidate = $base;
        $n = 2;

        $query = fn () => in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model::withTrashed() : $model::query();
        while ($query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$n++;
        }

        if ($candidate !== $base && $model === Organization::class) {
            $this->report->anomaly('slug_renamed', ['table' => 'organizations', 'from' => $base, 'to' => $candidate]);
        }

        return $candidate;
    }

    /**
     * A value from one of the files a bundle's `meta` holds, keyed by file name
     * (`extension.json`, `package.json`, `composer.json`). Not `data_get`: the dots in
     * those keys are part of the name.
     */
    protected function bundleFile(array $meta, string $file, ?string $path = null): mixed
    {
        $contents = $meta[$file] ?? null;

        return $path === null ? $contents : data_get($contents, $path);
    }

    protected function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }

    protected function json(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return json_decode(json_encode($value), true);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        // Double-encoded JSON (a JSON string holding JSON) appears in older rows.
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    protected function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
