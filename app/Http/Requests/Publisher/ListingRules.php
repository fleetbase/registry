<?php

namespace App\Http\Requests\Publisher;

use App\Models\Extension;
use Illuminate\Validation\Rule;

/**
 * Listing fields shared by create and update. None of them needs review.
 */
trait ListingRules
{
    protected function listingRules(): array
    {
        return [
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'promotional_text' => ['nullable', 'string', 'max:20000'],
            'category' => ['nullable', 'string', 'max:191'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'min:1', 'max:64'],
            'languages' => ['nullable', 'array', 'max:50'],
            'languages.*' => ['string', 'max:16'],
            // "What you'll get" and "After you install": a few short lines each.
            'highlights' => ['nullable', 'array', 'max:'.Extension::MAX_LIST_ITEMS],
            'highlights.*' => ['required', 'string', 'max:'.Extension::MAX_LIST_ITEM_LENGTH],
            'setup_steps' => ['nullable', 'array', 'max:'.Extension::MAX_LIST_ITEMS],
            'setup_steps.*' => ['required', 'string', 'max:'.Extension::MAX_LIST_ITEM_LENGTH],
            'hosting' => ['nullable', Rule::in(Extension::HOSTINGS)],
            'website_url' => ['nullable', 'url:http,https', 'max:255'],
            'repo_url' => ['nullable', 'url:http,https', 'max:255'],
            'support_url' => ['nullable', 'url:http,https', 'max:255'],
            'privacy_url' => ['nullable', 'url:http,https', 'max:255'],
            'tos_url' => ['nullable', 'url:http,https', 'max:255'],
            'copyright' => ['nullable', 'string', 'max:255'],
            'fa_icon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
        ];
    }

    /**
     * The listing columns present in the request (category resolved by the controller).
     */
    public function listingAttributes(): array
    {
        return collect($this->validated())
            ->only(['name', 'subtitle', 'description', 'promotional_text', 'tags', 'languages', 'highlights', 'setup_steps', 'hosting', 'website_url', 'repo_url', 'support_url', 'privacy_url', 'tos_url', 'copyright', 'fa_icon'])
            ->map(fn ($value, string $key) => in_array($key, ['highlights', 'setup_steps'], true) ? (array_values((array) ($value ?? [])) ?: null) : $value)
            ->all();
    }
}
