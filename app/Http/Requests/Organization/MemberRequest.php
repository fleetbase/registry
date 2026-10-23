<?php

namespace App\Http\Requests\Organization;

use App\Models\Membership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a member (`email`, `role`) or change a member's `role`.
 */
class MemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(
            $this->routeIs('v1.organizations.members.store') ? ['email' => ['required', 'string', 'email:rfc', 'max:191']] : [],
            ['role' => ['required', 'string', Rule::in(Membership::ROLES)]],
        );
    }
}
