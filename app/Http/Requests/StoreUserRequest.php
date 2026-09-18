<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasEmailRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    use HasEmailRule;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'max:255', $this->emailRule(), 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:admin,user'],
        ];
        // Note: no 'user_code' or 'is_active' here on purpose — the code is
        // always system-generated (User::generateUniqueUserCode()) and new
        // accounts always start Active, per the User Management spec.
    }
}
