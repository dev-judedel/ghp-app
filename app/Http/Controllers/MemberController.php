<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMemberRequest;
use App\Models\Department;
use App\Models\Division;
use App\Models\Member;
use App\Services\BenefitAccrualService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $type = $request->query('type');           // 'employee' | 'agent' | null
        $departmentId = $request->query('department'); // department id | null
        $divisionId = $request->query('division');   // division id | null
        $status = $request->query('status', 'active'); // 'active' (default) | 'inactive' | 'all'

        $members = Member::query()
            ->with(['division', 'department'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhere('first_name', 'ilike', "%{$search}%");
                });
            })
            ->when($type === 'employee', fn ($query) => $query->employees())
            ->when($type === 'agent', fn ($query) => $query->agents())
            ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
            ->when($divisionId, fn ($query) => $query->where('division_id', $divisionId))
            ->when($status === 'active', fn ($query) => $query->active())
            ->when($status === 'inactive', fn ($query) => $query->inactive())
            // $status === 'all' -> no filter applied, shows both
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25)
            ->withQueryString();

        $viewData = [
            'members' => $members,
            'search' => $search,
            'type' => $type,
            'departmentId' => $departmentId,
            'divisionId' => $divisionId,
            'status' => $status,
            'departments' => Department::orderBy('name')->get(),
            'divisions' => Division::orderBy('member_type')->orderBy('name')->get(),
        ];

        // Live search: the search box fires these requests as the user types
        // (see the script in members/index.blade.php). Return just the
        // results partial so we're not re-sending the whole page/modals on
        // every keystroke.
        if ($request->ajax()) {
            return view('members._results', $viewData);
        }

        return view('members.index', $viewData);
    }

    public function store(StoreMemberRequest $request): RedirectResponse
    {
        $member = Member::create($request->validated() + [
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()
            ->route('members.show', $member)
            ->with('status', "Member {$member->code} created.");
    }

    public function show(Member $member): View
    {
        $member->load([
            'division',
            'department',
            'dependents',
            'benefitPeriods' => fn ($q) => $q->orderByDesc('from_date'),
            'reimbursements' => fn ($q) => $q->orderByDesc('or_date')->limit(20),
            'amountAdjustments' => fn ($q) => $q->orderByDesc('requested_at'),
        ]);

        $currentBenefitPeriod = $member->benefitPeriods->first();

        return view('members.show', [
            'member' => $member,
            'currentBenefitPeriod' => $currentBenefitPeriod,
        ]);
    }

    public function generateBenefitPeriod(Member $member, BenefitAccrualService $accrualService): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        if (! $member->is_active) {
            return redirect()->route('members.show', $member)
                ->with('status', "Can't generate a benefit period — {$member->code} is marked Inactive.");
        }

        if ($member->deduction_start_date === null) {
            return redirect()->route('members.show', $member)
                ->with('status', "Can't generate a benefit period — {$member->code} has no deduction start date on file.");
        }

        $member->loadMissing('dependents', 'reimbursements', 'benefitPeriods');
        $period = $accrualService->accrue($member);

        return redirect()->route('members.show', $member)
            ->with('status', sprintf(
                'Benefit period %s – %s generated: ₱%s available (₱%s used of ₱%s).',
                $period->from_date->format('M d, Y'),
                $period->to_date->format('M d, Y'),
                number_format($period->ghp_available, 2),
                number_format($period->ghp_used, 2),
                number_format($period->ghp_amount, 2),
            ));
    }
}
