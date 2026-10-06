<?php

namespace App\Services;

use App\Models\Member;

/**
 * The single place a brand-new Member row gets built from Add-Member-shaped
 * data (the same shape StoreMemberRequest validates: code, email,
 * member_type, last_name, first_name, ..., ghp_amount, is_active, ...).
 *
 * Used by BOTH MemberController::store() (the manual "Add member" form) and
 * MemberCsvImportService (the CSV bulk importer), so the two can never again
 * drift apart on code generation, the Start Date (deduction) fallback, or
 * how a non-standard GHP amount gets flagged manual — which is exactly what
 * had happened to the bulk importer before this class existed (see
 * task.md §2.26).
 */
class MemberCreationService
{
    public function __construct(private readonly BenefitAccrualService $accrualService)
    {
    }

    /**
     * @param  array<string, mixed>  $data  Validated fields shaped like
     *                                      StoreMemberRequest's rules.
     *                                      'is_active' must already be a
     *                                      resolved bool — each caller
     *                                      derives it its own way (the web
     *                                      form via $request->boolean(),
     *                                      the CSV importer via its own
     *                                      friendly-text column), since
     *                                      "is this field present at all"
     *                                      means something different for a
     *                                      submitted HTML checkbox than for
     *                                      a CSV cell. This method doesn't
     *                                      re-interpret that, only fills in
     *                                      the parts common to both: code,
     *                                      the deduction start date
     *                                      fallback, and the manual-amount
     *                                      flag.
     */
    public function create(array $data): Member
    {
        $data['code'] = filled($data['code'] ?? null) ? $data['code'] : Member::generateUniqueCode();

        // "Start Date" (the first deduction) is an editable input: use it
        // when set. Left blank, it is derived from start_date — the 1st of
        // the following month, never the start month itself.
        $data['deduction_start_date'] = filled($data['deduction_start_date'] ?? null)
            ? $data['deduction_start_date']
            : $this->accrualService->resolveDeductionStartDate($data['start_date']);

        // The engine only honors members.ghp_amount when it is flagged
        // manual (otherwise it recomputes 3,600 / 4,200 from dependents). So
        // an amount that differs from the standard one is registered as a
        // manual override — the same mechanism "Adjust GHP amount" uses —
        // and the standard amount stays automatic (dependents still raise it).
        $data['ghp_amount_is_manual'] = abs((float) $data['ghp_amount'] - $this->accrualService->defaultGhpAmount()) > 0.004;

        return Member::create($data);
    }
}
