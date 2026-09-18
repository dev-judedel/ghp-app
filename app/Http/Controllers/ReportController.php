<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsCsv;
use App\Models\BenefitPeriod;
use App\Models\Department;
use App\Models\Division;
use App\Models\Member;
use App\Models\Reimbursement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use StreamsCsv;

    public function index(): View
    {
        // Plain Eloquent instead of raw SQL on purpose: the previous version
        // used EXTRACT(YEAR FROM from_date)::int, which is PostgreSQL-only
        // syntax (the '::int' cast doesn't exist in MySQL/SQLite) — this app
        // runs on MySQL, so GET /reports has been throwing a SQL syntax
        // error on every load. Same class of bug as the earlier member
        // search 'ilike' issue. This version works on any driver.
        $coverageYears = BenefitPeriod::query()
            ->select('from_date')
            ->get()
            ->map(fn ($period) => $period->from_date->year)
            ->unique()
            ->sortDesc()
            ->values();

        return view('reports.index', [
            'departments' => Department::orderBy('name')->get(),
            'divisions' => Division::orderBy('member_type')->orderBy('name')->get(),
            'coverageYears' => $coverageYears,
        ]);
    }

    /**
     * Shared query logic for the Annual GHP report — one row per member
     * with their benefit period for the selected coverage year (or their
     * latest on record if no year given). Used by both the PDF and CSV
     * export so the two never drift apart in what they show.
     */
    private function buildAnnualGhpRows(Request $request): Collection
    {
        $type = $request->query('type');
        $departmentId = $request->query('department');
        $divisionId = $request->query('division');
        $status = $request->query('status', 'active');
        $year = $request->query('year');

        return Member::query()
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
    }

    public function annualGhp(Request $request): Response
    {
        $rows = $this->buildAnnualGhpRows($request);
        $year = $request->query('year');

        $pdf = Pdf::loadView('reports.pdf.annual-ghp', [
            'rows' => $rows,
            'year' => $year,
            'generatedAt' => now(),
            'filters' => $request->only(['type', 'department', 'division', 'status']),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('annual-ghp-report-'.($year ?: 'latest').'.pdf');
    }

    public function annualGhpCsv(Request $request): StreamedResponse
    {
        $rows = $this->buildAnnualGhpRows($request);
        $year = $request->query('year');

        return $this->streamCsv(
            'annual-ghp-report-'.($year ?: 'latest').'.csv',
            ['Code', 'Name', 'Type', 'Division', 'Department', 'Status', 'Coverage From', 'Coverage To', 'GHP Amount', 'Used', 'Available'],
            $rows->map(fn ($row) => [
                $row->member->code,
                $row->member->full_name,
                $row->member->member_type === Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee',
                $row->member->division->name ?? '',
                $row->member->department->name ?? '',
                $row->member->is_active ? 'Active' : 'Inactive',
                $row->period->from_date->format('Y-m-d'),
                $row->period->to_date->format('Y-m-d'),
                $row->period->ghp_amount,
                $row->period->ghp_used,
                $row->period->ghp_available,
            ])
        );
    }

    /**
     * Shared query logic for the Reimbursement report. Used by both the
     * PDF and CSV export.
     */
    private function buildReimbursementsQuery(Request $request)
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $type = $request->query('type');
        $departmentId = $request->query('department');
        $divisionId = $request->query('division');

        return Reimbursement::query()
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
    }

    public function reimbursements(Request $request): Response
    {
        $reimbursements = $this->buildReimbursementsQuery($request);

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

    public function reimbursementsCsv(Request $request): StreamedResponse
    {
        $reimbursements = $this->buildReimbursementsQuery($request);

        return $this->streamCsv(
            'reimbursement-report-'.$request->query('from').'-to-'.$request->query('to').'.csv',
            ['OR Date', 'Member Code', 'Member Name', 'Type', 'Division', 'OR No', 'Hospital', 'Amount', 'Voided', 'Voided Reason'],
            $reimbursements->map(fn ($r) => [
                $r->or_date->format('Y-m-d'),
                $r->member->code,
                $r->member->full_name,
                $r->member->member_type === Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee',
                $r->member->division->name ?? '',
                $r->or_no ?? '',
                $r->hospital_name ?? '',
                $r->or_amount,
                $r->is_voided ? 'Yes' : 'No',
                $r->voided_reason ?? '',
            ])
        );
    }

    /**
     * Member Data Record (MDR): single-member profile + benefit + dependent
     * summary, matching the legacy system's per-member printout. PDF only
     * — it's a formatted personal record, not a data export, so a CSV
     * version wouldn't make much sense.
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
