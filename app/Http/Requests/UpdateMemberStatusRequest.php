<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * A resignation/deactivation date is only required when this request is
     * about to deactivate an active member — reactivating always clears the
     * field regardless of what's submitted (see MemberController::updateStatus()),
     * so there's nothing to validate on that path. Direction is derived from
     * the member's current state rather than any request input, since the
     * client never gets to declare "I am deactivating" — the server decides
     * that from $member->is_active, same as the controller does.
     */
    public function rules(): array
    {
        $member = $this->route('member');
        $deactivating = (bool) ($member?->is_active);

        return [
            'resignation_date' => [$deactivating ? 'required' : 'nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'resignation_date.required' => 'A resignation/deactivation date is required to deactivate a member.',
        ];
    }
}
