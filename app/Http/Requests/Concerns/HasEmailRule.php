<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Email;

trait HasEmailRule
{
    /**
     * RFC format check always applies. The MX/DNS lookup is real network
     * I/O — a practical check that the domain can receive mail, without
     * claiming to confirm the inbox itself exists — so it's skipped in the
     * testing environment to keep tests fast and independent of outbound
     * network access. Production behavior is unaffected.
     */
    protected function emailRule(): Email
    {
        $rule = Rule::email()->rfcCompliant();

        if (! app()->environment('testing')) {
            $rule->validateMxRecord();
        }

        return $rule;
    }
}
