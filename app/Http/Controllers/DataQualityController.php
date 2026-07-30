<?php

namespace App\Http\Controllers;

use App\Models\BenefitPeriod;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DataQualityController extends Controller
{
    public function index(): View
    {
        return view('data-quality.index', [
            'badDatePeriods' => $this->findCorruptedDatePeriods(),
            'zeroAmountActive' => $this->findZeroAmountActiveMembers(),
            'missingPeriods' => $this->findMembersMissingCurrentPeriod(),
            'legacyOrphans' => $this->tryLegacyOrphanCounts(),
        ]);
    }

    /**
     * Flags benefit periods whose from_date doesn't match the expected
     * coverage-year start for the member's type (Apr 1 for Employees, Jun 1
     * for Agents), or whose year is implausible. This is exactly the pattern
     * found during the original import — e.g. a period dated 1943-09-10 —
     * where the member existed so the row got imported, but the date itself
     * is corrupted legacy data. See migration analysis doc for the original
     * finding; this report surfaces any such rows currently in the live DB
     * (whether from that import or introduced later) for manual correction.
     */
    private function findCorruptedDatePeriods()
    {
        $minYear = 2005;
        $maxYear = now()->year + 1;

        return BenefitPeriod::query()
            ->with('member')
            ->get()
            ->filter(function (BenefitPeriod $period) use ($minYear, $maxYear) {
                if ($period->from_date->year < $minYear || $period->from_date->year > $maxYear) {
                    return true;
                }

                $expectedMonth = $period->member_type === Member::MEMBER_TYPE_AGENT ? 6 : 4;

                return $period->from_date->month !== $expectedMonth || $period->from_date->day !== 1;
            })
            ->sortBy('from_date')
            ->values();
    }

    /**
     * Active members with a ₱0 GHP amount are almost certainly members who
     * should have been marked Inactive (this matched the "many c_ghp_amt = 0
     * rows, likely terminated" pattern noted in the original data review) —
     * flagged here rather than auto-corrected, since it's a status change
     * that should be a deliberate decision, not an inference.
     */
    private function findZeroAmountActiveMembers()
    {
        return Member::active()
            ->where('ghp_amount', 0)
            ->orderBy('last_name')
            ->get();
    }

    /**
     * Active members with a deduction start date in the past but no benefit
     * period on record at all — these were likely never generated (e.g.
     * added before the auto-generation job existed, or manually skipped).
     */
    private function findMembersMissingCurrentPeriod()
    {
        return Member::active()
            ->whereNotNull('deduction_start_date')
            ->whereDoesntHave('benefitPeriods')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * Best-effort: if the 'legacy' staging DB (see ghp:import-legacy) is
     * still reachable, report how many rows per table were skipped during
     * import because their member code had no matching member — informational
     * only. Recovering these requires a business decision (should that
     * member be reconstructed?), not something this report does automatically.
     * Returns null if the legacy connection isn't available (e.g. the
     * staging DB was cleaned up after the import — that's fine, this is a
     * one-time historical reference, not an ongoing dependency).
     */
    private function tryLegacyOrphanCounts(): ?array
    {
        try {
            DB::connection('legacy')->getPdo();
        } catch (\Throwable) {
            return null;
        }

        $memberCodes = Member::pluck('code')->all();

        $counts = [];

        foreach ([
            't_dependents' => 'c_code',
            't_process' => 'c_code',
            't_avail_used_ghp' => 'c_code',
            't_reimbursement' => 'c_code',
        ] as $table => $codeColumn) {
            $counts[$table] = DB::connection('legacy')->table($table)
                ->whereNotNull($codeColumn)
                ->where($codeColumn, '!=', '')
                ->whereNotIn($codeColumn, $memberCodes)
                ->count();
        }

        return $counts;
    }
}
