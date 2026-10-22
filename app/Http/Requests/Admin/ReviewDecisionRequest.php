<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve or reject. `items` are the review requirement results shown to the developer:
 * `[{code, passed, note}]` (codes such as R1–R6). Reject needs a reason and at least one item.
 */
class ReviewDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rejecting = $this->routeIs('v1.admin.releases.reject');

        return [
            'reason' => [$rejecting ? 'required' : 'nullable', 'string', 'min:3', 'max:5000'],
            'items' => [$rejecting ? 'required' : 'nullable', 'array', $rejecting ? 'min:1' : 'min:0', 'max:50'],
            'items.*' => ['array:code,passed,note'],
            'items.*.code' => ['required', 'string', 'max:32'],
            'items.*.passed' => ['required', 'boolean'],
            'items.*.note' => ['nullable', 'string', 'max:2000'],
            'publish' => ['nullable', 'boolean'],
        ];
    }

    public function items(): ?array
    {
        $items = $this->validated('items');

        return $items === null ? null : array_map(fn (array $item) => [
            'code' => $item['code'],
            'passed' => (bool) $item['passed'],
            'note' => $item['note'] ?? null,
        ], array_values($items));
    }
}
