<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidReimbursementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
