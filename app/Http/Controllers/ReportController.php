<?php

namespace App\Http\Controllers;

use App\Models\BenefitPeriod;
use App\Models\Department;
use App\Models\Division;
use App\Models\Member;
use App\Models\Reimbursement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(): View
    {
        $coverageYears = BenefitPeriod::query()
            ->selectRaw('DISTINCT EXTRACT(YEAR FROM from_date)::int as year')
            ->orderByDesc('year')
            ->pluck('year');

        return view('reports.index', [
            'departments' => Department::orderBy('name')->get(),
            'divisions' => Division::orderBy('member_type')->orderBy('name')->get(),
            'coverageYears' => $coverageYears,
        ]);
    }

    /**
     * Annual GHP report: one row per member showing their benefit period
     * for the selected coverage year (or their latest on record if no year
     * is specified), filterable the same way the member list is.
     */
    public function annualGhp(Request $request): Response
    {
        $type = $request->query('type');
        $departmentId = $request->query('department');
        $divisionId = $request->query('division');
        $status = $request->query('status', 'active');
        $year = $request->query('year');

        $members = Member::query()
            ->with(['division', 'department'])
            ->with(['benefitPeriods' => function ($query) use ($year) {
                $query->orderByDesc('from_date');

                if ($year) {
                    $query->whereYear('from_date', $year);
                }
            }])
            ->when($type === 'employee', fn ($q) => $q->employees())
            ->when($type === 'agent', fn ($q) => $q->agents())
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($divisionId, fn ($q) => $q->where('division_id', $divisionId))
            ->when($status === 'active', fn ($q) => $q->active())
            ->when($status === 'inactive', fn ($q) => $q->inactive())
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn ($member) => (object) [
                'member' => $member,
                'period' => $member->benefitPeriods->first(),
            ])
            ->filter(fn ($row) => $row->period !== null)
            ->values();

        $pdf = Pdf::loadView('reports.pdf.annual-ghp', [
            'rows' => $members,
            'year' => $year,
            'generatedAt' => now(),
            'filters' => compact('type', 'departmentId', 'divisionId', 'status'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('annual-ghp-report-'.($year ?: 'latest').'.pdf');
    }

    /**
     * Reimbursement report: reimbursements within a date range, filterable
     * by member type/division/department.
     */
    public function reimbursements(Request $request): Response
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $type = $request->query('type');
        $departmentId = $request->query('department');
        $divisionId = $request->query('division');

        $reimbursements = Reimbursement::query()
            ->with(['member.division', 'member.department'])
            ->whereBetween('or_date', [$request->query('from'), $request->query('to')])
            ->whereHas('member', function ($query) use ($type, $departmentId, $divisionId) {
                $query
                    ->when($type === 'employee', fn ($q) => $q->employees())
                    ->when($type === 'agent', fn ($q) => $q->agents())
                    ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
                    ->when($divisionId, fn ($q) => $q->where('division_id', $divisionId));
            })
            ->orderBy('or_date')
            ->get();

        $pdf = Pdf::loadView('reports.pdf.reimbursements', [
            'reimbursements' => $reimbursements,
            'total' => $reimbursements->where('is_voided', false)->sum('or_amount'),
            'voidedCount' => $reimbursements->where('is_voided', true)->count(),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('reimbursement-report-'.$request->query('from').'-to-'.$request->query('to').'.pdf');
    }

    /**
     * Member Data Record (MDR): single-member profile + benefit + dependent
     * summary, matching the legacy system's per-member printout.
     */
    public function memberDataRecord(Member $member): Response
    {
        $member->load([
            'division',
            'department',
            'dependents',
            'benefitPeriods' => fn ($q) => $q->orderByDesc('from_date'),
            'reimbursements' => fn ($q) => $q->orderByDesc('or_date')->limit(10),
        ]);

        $pdf = Pdf::loadView('reports.pdf.mdr', [
            'member' => $member,
            'currentBenefitPeriod' => $member->benefitPeriods->first(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download("mdr-{$member->code}.pdf");
    }
}
