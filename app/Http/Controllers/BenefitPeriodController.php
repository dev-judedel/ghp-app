<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBenefitPeriodRequest;
use App\Http\Requests\VoidBenefitPeriodRequest;
use App\Models\BenefitPeriod;
use App\Models\Member;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class BenefitPeriodController extends Controller
{
    /**
     * Direct manual correction — used from the Data Quality Report to fix
     * corrupted historical rows (e.g. a period dated 1943-09-10). This does
     * NOT go through BenefitAccrualService, deliberately: it's fixing
     * broken data, not recalculating a live balance.
     */
    public function update(UpdateBenefitPeriodRequest $request, BenefitPeriod $benefitPeriod): RedirectResponse
    {
        $benefitPeriod->update($request->validated());

        return redirect()->route('data-quality.index')
            ->with('status', "Benefit period corrected for {$benefitPeriod->member->code}.");
    }

    /**
     * ================================================================
     * Void: corrects a mistakenly-generated Benefit Period.
     * ================================================================
     * Marks the period as voided rather than deleting it, so the record
     * stays on file for history/audit. A voided period is immediately
     * excluded from the "active period for this cycle" checks in
     * MemberController::generateBenefitPeriod() and
     * MemberController::show() (see $currentCyclePeriod/$currentBenefitPeriod
     * there), and from BenefitAccrualService::accrue()'s update-match and
     * calculate()'s carry-forward lookup — so "Generate this year's
     * benefit period" becomes available again right away, and the voided
     * row plays no further part in any balance math.
     *
     * No transaction/lock here (unlike ReimbursementController's balance
     * checks) — voiding doesn't create or move money, it only flags one
     * row the admin explicitly clicked, so the double-submit risk this
     * would guard against is negligible.
     */
    public function void(VoidBenefitPeriodRequest $request, Member $member, BenefitPeriod $benefitPeriod): RedirectResponse
    {
        abort_unless($benefitPeriod->member_id === $member->id, 404);

        if ($benefitPeriod->is_voided) {
            return redirect()->route('members.show', $member)->with('status', 'That benefit period is already voided.');
        }

        $benefitPeriod->void($request->validated('void_reason'), $request->user());

        return redirect()->route('members.show', $member)
            ->with('status', sprintf(
                'Benefit period %s – %s voided for %s. It no longer counts as this cycle\'s active period — "Generate this year\'s benefit period" is available again.',
                $benefitPeriod->from_date->format('M d, Y'),
                $benefitPeriod->to_date->format('M d, Y'),
                $member->code,
            ));
    }

    /**
     * ================================================================
     * Delete: permanently removes a VOIDED Benefit Period.
     * ================================================================
     * Only ever allowed for a period that is already voided — enforced
     * here server-side, not just by hiding the button for active periods
     * in the view. Safe to hard-delete: reimbursements.benefit_period_id
     * is nullOnDelete() (see migration
     * 2026_09_21_100000_add_benefit_period_id_to_reimbursements_table), so
     * any reimbursement that was linked to this period simply loses that
     * fast-path link and falls back to unlinked — the reimbursement row
     * itself, and every other financial record, is untouched. The
     * separate benefit_ledger table has no foreign key to benefit_periods
     * at all, so it isn't affected either.
     */
    public function destroy(Member $member, BenefitPeriod $benefitPeriod): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_unless($benefitPeriod->member_id === $member->id, 404);
        abort_unless($benefitPeriod->is_voided, 422, 'Only voided benefit periods can be deleted.');

        $label = $benefitPeriod->from_date->format('M d, Y').' – '.$benefitPeriod->to_date->format('M d, Y');

        $benefitPeriod->delete();

        return redirect()->route('members.show', $member)
            ->with('status', "Voided benefit period ({$label}) permanently deleted for {$member->code}.");
    }

    /**
     * Coverage Year History drill-down: shows only the reimbursement
     * records belonging to this one Benefit Period, via the
     * reimbursements.benefit_period_id link (see
     * ReimbursementController::linkToBenefitPeriod()) — filtered at the
     * database level, not in JavaScript. Reached by clicking a period row
     * on the member page's "Benefit periods" table.
     */
    public function reimbursements(Member $member, BenefitPeriod $benefitPeriod): View
    {
        abort_unless($benefitPeriod->member_id === $member->id, 404);

        $reimbursements = $benefitPeriod->reimbursements()
            ->orderBy('or_date')
            ->get();

        return view('benefit-periods.reimbursements', [
            'member' => $member,
            'benefitPeriod' => $benefitPeriod,
            'reimbursements' => $reimbursements,
            'total' => $reimbursements->where('is_voided', false)->sum('or_amount'),
            'voidedCount' => $reimbursements->where('is_voided', true)->count(),
        ]);
    }

    /**
     * The printable / downloadable company-letterhead version of the same
     * drill-down. Streams inline by default so the browser's own PDF
     * viewer (and its print button) opens straight away for "Print";
     * ?download=1 forces a "Save As" download instead.
     */
    public function reimbursementsPdf(Request $request, Member $member, BenefitPeriod $benefitPeriod): Response
    {
        abort_unless($benefitPeriod->member_id === $member->id, 404);

        $reimbursements = $benefitPeriod->reimbursements()
            ->orderBy('or_date')
            ->get();

        $pdf = Pdf::loadView('reports.pdf.reimbursement-receipt', [
            'member' => $member,
            'benefitPeriod' => $benefitPeriod,
            'reimbursements' => $reimbursements,
            'total' => $reimbursements->where('is_voided', false)->sum('or_amount'),
            'voidedCount' => $reimbursements->where('is_voided', true)->count(),
            'generatedAt' => now(),
            'generatedBy' => $request->user(),
        ])->setPaper('a4', 'portrait');

        $filename = sprintf(
            'reimbursement-receipt-%s-%s.pdf',
            $member->code,
            $benefitPeriod->from_date->format('Y-m')
        );

        return $request->boolean('download')
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }
}
