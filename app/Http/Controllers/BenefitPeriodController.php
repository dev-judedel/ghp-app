<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBenefitPeriodRequest;
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
