<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\BenefitAccrualService;
use Illuminate\Console\Command;

/**
 * Daily housekeeping: ensures every active member has an up-to-date benefit
 * period for the coverage year containing today's date.
 *
 * Rule: for each active member with a deduction_start_date, call the same
 * accrual logic as the manual "Generate benefit period" button. This is
 * intentionally NOT hardcoded to "run only on Apr 1 / Jun 1" — asking
 * "does a period exist for today's coverage year?" naturally handles both
 * Employee (Apr 1) and Agent (Jun 1) cutover dates, new hires added
 * mid-year, members reactivated after being inactive, and days the
 * scheduler didn't run (e.g. server was off on the actual cutover date).
 *
 * Safe to run more than once a day, or re-run manually — accrue() uses
 * updateOrCreate keyed on (member_id, from_date, to_date), so it never
 * creates duplicates and never touches a PAST coverage period.
 */
class AutoGenerateBenefitPeriods extends Command
{
    protected $signature = 'ghp:auto-generate-benefit-periods';

    protected $description = "Ensure every active member has an up-to-date benefit period for the current coverage year";

    public function handle(BenefitAccrualService $accrualService): int
    {
        $members = Member::active()
            ->whereNotNull('deduction_start_date')
            ->with(['dependents', 'reimbursements', 'benefitPeriods'])
            ->get();

        $created = 0;
        $refreshed = 0;

        foreach ($members as $member) {
            [$from, $to] = $accrualService->coveragePeriod($member->member_type, now());

            $alreadyExisted = $member->benefitPeriods->contains(
                fn ($period) => $period->from_date->isSameDay($from) && $period->to_date->isSameDay($to)
            );

            $accrualService->accrue($member);

            $alreadyExisted ? $refreshed++ : $created++;
        }

        $summary = "Benefit periods: {$created} newly created, {$refreshed} refreshed, ".
            ($members->count() - $created - $refreshed).' unchanged, out of '.$members->count().' active member(s) checked.';

        $this->info($summary);

        // Visible in the Activity Log page alongside member-level changes,
        // with no causer (this runs unattended, not triggered by a user).
        activity('benefit_period')->log($summary);

        return self::SUCCESS;
    }
}
