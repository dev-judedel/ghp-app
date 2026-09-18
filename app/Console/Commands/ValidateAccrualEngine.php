<?php

namespace App\Console\Commands;

use App\Models\BenefitPeriod;
use App\Services\BenefitAccrualService;
use Illuminate\Console\Command;

/**
 * Compares the new BenefitAccrualService against every imported historical
 * benefit_periods row (was t_process). Does NOT modify any data — read-only
 * comparison, safe to run repeatedly.
 *
 * The comparison focuses on "accrued this period" (ghp_amount/12 * months),
 * since ghp_available in the legacy data also depends on carry-forward and
 * usage chains we're not attempting to replicate move-for-move — the goal
 * here is to validate the reinterpreted monthly-accrual rule specifically
 * (see BenefitAccrualService docblock), not to reproduce the legacy
 * available-balance number exactly.
 *
 * Usage:
 *   php artisan ghp:validate-accrual
 *   php artisan ghp:validate-accrual --tolerance=50   (peso tolerance, default 1.00)
 *   php artisan ghp:validate-accrual --limit=20        (show N worst mismatches)
 */
class ValidateAccrualEngine extends Command
{
    protected $signature = 'ghp:validate-accrual {--tolerance=1.00} {--limit=20}';

    protected $description = 'Compare the new accrual engine against real historical benefit_periods data (read-only)';

    public function handle(BenefitAccrualService $service): int
    {
        $tolerance = (float) $this->option('tolerance');
        $limit = (int) $this->option('limit');

        $periods = BenefitPeriod::with(['member.dependents', 'member.reimbursements'])
            ->orderBy('from_date')
            ->get();

        $this->info("Validating against {$periods->count()} historical benefit periods...");
        $bar = $this->output->createProgressBar($periods->count());

        $matches = 0;
        $mismatches = [];
        $skipped = 0;

        foreach ($periods as $period) {
            $member = $period->member;

            if ($member === null || $member->deduction_start_date === null) {
                $skipped++;
                $bar->advance();

                continue;
            }

            // Recompute using the period's own midpoint as "as of" so we're
            // comparing against the period's own accrual, not today's.
            $asOf = $period->to_date;

            $accruedThisPeriod = $this->recomputeAccruedOnly($service, $member, $period);

            // Legacy "accrued this period" isn't stored directly — reconstruct
            // it as ghp_available + ghp_used, which is what was actually
            // credited before any usage was subtracted, ignoring carry-forward
            // by comparing to (ghp_amount) as an approximation floor.
            $historicalAccrued = (float) $period->ghp_available + (float) $period->ghp_used;

            $diff = abs($accruedThisPeriod - $historicalAccrued);

            if ($diff <= $tolerance) {
                $matches++;
            } else {
                $mismatches[] = [
                    'code' => $member->code,
                    'from' => $period->from_date->toDateString(),
                    'to' => $period->to_date->toDateString(),
                    'computed' => $accruedThisPeriod,
                    'historical' => $historicalAccrued,
                    'diff' => $diff,
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $total = $matches + count($mismatches);
        $pct = $total > 0 ? round($matches / $total * 100, 1) : 0;

        $this->info("Matches (within ±{$tolerance}): {$matches} / {$total} ({$pct}%)");
        $this->line("Skipped (no deduction_start_date or orphaned): {$skipped}");

        if (count($mismatches) > 0) {
            $this->newLine();
            $this->warn('Worst mismatches (sorted by difference):');

            usort($mismatches, fn ($a, $b) => $b['diff'] <=> $a['diff']);

            $this->table(
                ['Code', 'From', 'To', 'Computed', 'Historical', 'Diff'],
                array_slice($mismatches, 0, $limit)
            );

            $this->newLine();
            $this->warn('A low match rate means the reinterpreted cutoff rule (see '
                .'BenefitAccrualService docblock) does not match actual historical '
                .'behavior and needs revisiting with the business owner before this '
                .'engine is trusted for production use.');
        }

        return self::SUCCESS;
    }

    /**
     * Recomputes just the accrued-this-period amount (monthly rate x months),
     * using the period's own from/to/ghp_amount rather than recalculating
     * ghp_amount from current dependents (dependents may have changed since
     * this historical period).
     */
    private function recomputeAccruedOnly(BenefitAccrualService $service, $member, BenefitPeriod $period): float
    {
        $dedStart = $member->deduction_start_date;
        $accrualStart = $dedStart->greaterThan($period->from_date) ? $dedStart : $period->from_date;

        $months = $service->countAccruedMonths($accrualStart, $period->to_date, $period->to_date);
        $monthlyRate = (float) $period->ghp_amount / 12;

        return round($monthlyRate * $months, 2);
    }
}
