<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $member = $this->route('member');

        // Only required when this request is about to DEACTIVATE a
        // currently-active member. Reactivating never needs a date (and
        // MemberController::updateStatus() clears it regardless of what's
        // submitted, so there's nothing to validate on that path).
        $deactivating = $member !== null && $member->is_active;

        return [
            'resignation_date' => $deactivating
                ? ['required', 'date']
                : ['nullable', 'date'],
        ];
    }
}
