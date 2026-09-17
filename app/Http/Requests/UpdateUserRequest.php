<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasEmailRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    use HasEmailRule;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'max:255', $this->emailRule(), Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'in:admin,user'],
        ];
        // Status (Active/Inactive) is intentionally not part of this form —
        // it has its own confirmation dialog and route (users.update-status)
        // per the User Management spec, kept separate from name/email/role
        // edits the same way amount adjustments are kept separate from
        // regular member edits.
    }
}
