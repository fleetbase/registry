<?php

namespace App\Releases;

use App\Models\Extension;

/**
 * Server-side port of registry-bridge's `RegistryExtension::isExtensionReadyForSubmission`:
 * what a listing needs before its first release can be submitted. Listings that are
 * already published are not re-checked, so imported listings keep releasing.
 */
class ListingReadiness
{
    /**
     * @return list<string> what is missing; empty when ready
     */
    public function errors(Extension $extension): array
    {
        if ($extension->status === Extension::STATUS_PUBLISHED) {
            return [];
        }

        $errors = [];
        $url = fn ($value) => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value) === 1;

        if (mb_strlen(trim((string) $extension->name)) <= 3) {
            $errors[] = 'The listing needs a name longer than 3 characters.';
        }
        if (mb_strlen(trim((string) $extension->subtitle)) === 0) {
            $errors[] = 'The listing needs a subtitle.';
        }
        if (mb_strlen(trim((string) $extension->description)) <= 12) {
            $errors[] = 'The listing needs a description longer than 12 characters.';
        }
        if (mb_strlen(trim((string) $extension->promotional_text)) === 0) {
            $errors[] = 'The listing needs promotional text.';
        }
        if (empty($extension->tags)) {
            $errors[] = 'The listing needs at least one tag.';
        }
        if (mb_strlen(trim((string) $extension->copyright)) === 0) {
            $errors[] = 'The listing needs a copyright notice.';
        }
        foreach (['website_url' => 'website', 'support_url' => 'support', 'privacy_url' => 'privacy policy'] as $field => $label) {
            if (! $url($extension->{$field})) {
                $errors[] = "The listing needs a valid {$label} URL.";
            }
        }
        if (empty($extension->icon_file_uuid) && empty($extension->fa_icon)) {
            $errors[] = 'The listing needs an icon.';
        }
        if (empty($extension->category_uuid)) {
            $errors[] = 'The listing needs a category.';
        }

        return $errors;
    }
}
