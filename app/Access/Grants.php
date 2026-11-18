<?php

namespace App\Access;

use App\Models\Account;
use App\Models\Event;
use App\Models\Extension;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Access grants: `licences` of kind `grant`, given by an extension's owner (or staff) to another
 * organization at no charge. How a private extension reaches its clients; on a public paid
 * extension a grant is a complimentary licence.
 *
 * A grant may be limited to some of the organization's instances (`instance_uuids`); then only
 * those instance tokens can see, download and install the extension, and personal tokens of the
 * organization cannot. Revoking a grant takes effect on the next request (the licence gate and
 * catalog read the licence every time) and releases its seats.
 */
class Grants
{
    public const RELATIONS = ['organization', 'activeActivations.instance', 'activeActivations.release'];

    /**
     * @return Collection<int, Licence>
     */
    public function for(Extension $extension): Collection
    {
        $grants = $extension->grants()
            ->with(self::RELATIONS)
            ->withCount('activeActivations')
            ->orderByRaw('case when status = ? then 0 else 1 end', [Licence::STATUS_ACTIVE])
            ->orderByDesc('created_at')
            ->get();

        return $this->withInstances($grants);
    }

    /**
     * @param  array{instances?: ?list<string>, max_activations?: ?int, expires_at?: ?string, note?: ?string}  $input
     */
    public function grant(Extension $extension, Organization $organization, array $input, ?Account $actor): Licence
    {
        if ($organization->uuid === $extension->organization_uuid) {
            throw ValidationException::withMessages(['organization' => "{$organization->name} owns this extension; it needs no grant."]);
        }

        $instances = $this->instanceUuids($organization, $input['instances'] ?? null);

        $licence = DB::transaction(function () use ($extension, $organization, $input, $instances) {
            $existing = Licence::query()
                ->where('extension_uuid', $extension->uuid)
                ->where('organization_uuid', $organization->uuid)
                ->where('kind', Licence::KIND_GRANT)
                ->where('status', Licence::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new ConflictHttpException("{$organization->name} already has access; change or revoke that grant instead.");
            }

            return Licence::create([
                'extension_uuid' => $extension->uuid,
                'organization_uuid' => $organization->uuid,
                'kind' => Licence::KIND_GRANT,
                'max_activations' => $input['max_activations'] ?? null,
                'instance_uuids' => $instances,
                'status' => Licence::STATUS_ACTIVE,
                'expires_at' => isset($input['expires_at']) ? Carbon::parse($input['expires_at']) : null,
                'meta' => array_filter(['note' => $input['note'] ?? null]) ?: null,
            ]);
        });

        Event::record('grant.created', $licence, $actor, [
            'extension' => $extension->public_id,
            'organization' => $organization->public_id,
            'instances' => $instances,
        ]);

        return $this->present($licence);
    }

    /**
     * @param  array{instances?: ?list<string>, max_activations?: ?int, expires_at?: ?string, note?: ?string}  $input  only the keys given change
     */
    public function update(Licence $grant, array $input, ?Account $actor): Licence
    {
        $changes = [];

        if (array_key_exists('instances', $input)) {
            $changes['instance_uuids'] = $this->instanceUuids($grant->organization, $input['instances']);
        }
        if (array_key_exists('max_activations', $input)) {
            $seats = $input['max_activations'];
            if ($seats !== null && $seats < $grant->seatsUsed()) {
                throw ValidationException::withMessages(['max_activations' => "{$grant->seatsUsed()} instances hold a seat; uninstall it from some first or allow at least that many."]);
            }
            $changes['max_activations'] = $seats;
        }
        if (array_key_exists('expires_at', $input)) {
            $changes['expires_at'] = $input['expires_at'] === null ? null : Carbon::parse($input['expires_at']);
        }
        if (array_key_exists('note', $input)) {
            $changes['meta'] = array_merge((array) $grant->meta, ['note' => $input['note']]);
        }

        DB::transaction(function () use ($grant, $changes) {
            $grant->forceFill($changes)->save();

            // Instances the grant no longer covers lose their seat.
            if ($grant->isInstanceLimited()) {
                LicenceActivation::query()
                    ->where('licence_uuid', $grant->uuid)
                    ->whereNull('deactivated_at')
                    ->whereNotIn('instance_uuid', $grant->instance_uuids)
                    ->update(['deactivated_at' => now(), 'updated_at' => now()]);
            }
        });

        Event::record('grant.updated', $grant, $actor, ['fields' => array_keys($changes)]);

        return $this->present($grant);
    }

    public function revoke(Licence $grant, ?Account $actor, ?string $reason = null): Licence
    {
        if ($grant->status === Licence::STATUS_REVOKED) {
            throw new ConflictHttpException('This grant is already revoked.');
        }

        DB::transaction(function () use ($grant, $reason) {
            $grant->forceFill([
                'status' => Licence::STATUS_REVOKED,
                'meta' => array_merge((array) $grant->meta, array_filter([
                    'revoked_reason' => 'grant_revoked',
                    'revoked_note' => $reason,
                    'revoked_at' => now()->toIso8601String(),
                ])),
            ])->save();

            LicenceActivation::query()
                ->where('licence_uuid', $grant->uuid)
                ->whereNull('deactivated_at')
                ->update(['deactivated_at' => now(), 'updated_at' => now()]);
        });

        Event::record('grant.revoked', $grant, $actor, ['reason' => $reason]);

        return $this->present($grant);
    }

    public function find(Extension $extension, string $id): ?Licence
    {
        return $extension->grants()
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->first();
    }

    public function present(Licence $grant): Licence
    {
        $grant->refresh()->load(self::RELATIONS)->loadCount('activeActivations');

        return $this->withInstances(new Collection([$grant]))->first();
    }

    /**
     * Instance ids (public id or uuid) of the organization's active instances, as uuids; null
     * for none given (every instance).
     *
     * @return list<string>|null
     */
    protected function instanceUuids(Organization $organization, ?array $identifiers): ?array
    {
        if ($identifiers === null || $identifiers === []) {
            return null;
        }

        $instances = Instance::query()
            ->where('organization_uuid', $organization->uuid)
            ->where('status', Instance::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereIn('public_id', $identifiers)->orWhereIn('uuid', $identifiers))
            ->get(['uuid', 'public_id']);

        $errors = [];
        foreach (array_values($identifiers) as $index => $identifier) {
            if (! $instances->contains(fn (Instance $instance) => $instance->public_id === $identifier || $instance->uuid === $identifier)) {
                $errors["instances.{$index}"] = ["{$identifier} is not an active instance of {$organization->name}."];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $instances->pluck('uuid')->unique()->sort()->values()->all();
    }

    /**
     * Load the instances each grant is limited to (`allowedInstances` relation).
     *
     * @param  Collection<int, Licence>  $grants
     * @return Collection<int, Licence>
     */
    protected function withInstances(Collection $grants): Collection
    {
        $uuids = $grants->flatMap(fn (Licence $grant) => (array) $grant->instance_uuids)->unique()->values();
        $instances = $uuids->isEmpty() ? collect() : Instance::withTrashed()->whereIn('uuid', $uuids)->get()->keyBy('uuid');

        return $grants->each(fn (Licence $grant) => $grant->setRelation(
            'allowedInstances',
            new Collection(collect((array) $grant->instance_uuids)->map(fn ($uuid) => $instances->get($uuid))->filter()->values()->all()),
        ));
    }
}
