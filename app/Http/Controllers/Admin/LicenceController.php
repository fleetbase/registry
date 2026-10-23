<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LicenceResource;
use App\Models\Event;
use App\Models\Extension;
use App\Models\Licence;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staff licence management: find licences, revoke one (new installs and updates stop; running
 * instances keep their seat), reinstate one (a won dispute, a mistaken revoke), and change its
 * seats. Refunds go through Stripe; the `charge.refunded` webhook revokes the licence.
 */
class LicenceController extends Controller
{
    /**
     * GET /v1/admin/licences?organization=&extension=&status=&kind= — newest first, 100 at most.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:'.Licence::STATUS_ACTIVE.','.Licence::STATUS_REVOKED],
            'kind' => ['nullable', 'in:'.Licence::KIND_FREE.','.Licence::KIND_PAID],
        ]);

        $licences = Licence::query()
            ->with(['extension', 'organization', 'purchase'])
            ->withCount('activeActivations')
            ->when($request->input('organization'), fn (Builder $q, $id) => $q->whereIn('organization_uuid', Organization::query()->where('slug', $id)->orWhere('public_id', $id)->select('uuid')))
            ->when($request->input('extension'), fn (Builder $q, $id) => $q->whereIn('extension_uuid', Extension::query()->where('slug', $id)->orWhere('public_id', $id)->select('uuid')))
            ->when($request->input('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->input('kind'), fn (Builder $q, $kind) => $q->where('kind', $kind))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(LicenceResource::collection($licences)->resolve($request));
    }

    /**
     * POST /v1/admin/licences/{id}/revoke — `reason` required.
     */
    public function revoke(Request $request, string $licence): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $model = $this->find($licence);

        if ($model->status === Licence::STATUS_REVOKED) {
            throw new ConflictHttpException('This licence is already revoked.');
        }

        $model->forceFill([
            'status' => Licence::STATUS_REVOKED,
            'meta' => array_merge((array) $model->meta, [
                'revoked_reason' => 'staff',
                'revoked_note' => $request->input('reason'),
                'revoked_at' => now()->toIso8601String(),
            ]),
        ])->save();

        Event::record('licence.revoked', $model, $request->user(), ['reason' => $request->input('reason')], $request->ip());

        return $this->present($request, $model);
    }

    /**
     * POST /v1/admin/licences/{id}/reinstate — optional `reason`.
     */
    public function reinstate(Request $request, string $licence): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $model = $this->find($licence);

        if ($model->status === Licence::STATUS_ACTIVE) {
            throw new ConflictHttpException('This licence is already active.');
        }

        $from = $model->meta['revoked_reason'] ?? null;
        $model->forceFill([
            'status' => Licence::STATUS_ACTIVE,
            'meta' => array_merge((array) $model->meta, [
                'revoked_reason' => null,
                'reinstated_at' => now()->toIso8601String(),
                'reinstated_note' => $request->input('reason'),
            ]),
        ])->save();

        Event::record('licence.reinstated', $model, $request->user(), ['was' => $from, 'reason' => $request->input('reason')], $request->ip());

        return $this->present($request, $model);
    }

    /**
     * PATCH /v1/admin/licences/{id} — `max_activations` (seats; at least the seats in use).
     */
    public function update(Request $request, string $licence): JsonResponse
    {
        $model = $this->find($licence);
        $request->validate(['max_activations' => ['required', 'integer', 'min:'.max(1, $model->seatsUsed()), 'max:1000']]);

        $from = $model->max_activations;
        $model->forceFill(['max_activations' => (int) $request->input('max_activations')])->save();

        Event::record('licence.seats_changed', $model, $request->user(), ['from' => $from, 'to' => $model->max_activations], $request->ip());

        return $this->present($request, $model);
    }

    protected function find(string $id): Licence
    {
        return Licence::findById($id) ?? throw new NotFoundHttpException('Licence not found.');
    }

    protected function present(Request $request, Licence $licence): JsonResponse
    {
        $licence->load(['extension', 'organization', 'purchase'])->loadCount('activeActivations');

        return response()->json((new LicenceResource($licence))->resolve($request));
    }
}
