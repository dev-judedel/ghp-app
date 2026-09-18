<?php

namespace App\Http\Requests\Concerns;

/**
 * Shared email format rule for member and user request classes. Kept as a
 * single source of truth so the format check (RFC-compliant, no DNS lookup —
 * this app runs on-prem without guaranteed outbound DNS, and tests run
 * fully offline) can't drift between the two areas of the app that collect
 * emails.
 */
trait HasEmailRule
{
    protected function emailRule(): string
    {
        return 'email:rfc';
    }
}
