<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Requests\UpdateMemberStatusRequest;
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

        $members = Member::filtered([
            'search' => $search,
            'type' => $type,
            'department' => $departmentId,
            'division' => $divisionId,
            'status' => $status,
        ])
            ->with(['division', 'department'])
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
        // Built as a mutable array (not $validated + [...]) on purpose:
        // 'code' is now a validated key too, and array union (+) keeps the
        // LEFT side's value on collisions — that would silently ignore this
        // override whenever the admin left it blank.
        $data = $request->validated();
        $data['code'] = $request->filled('code') ? $data['code'] : Member::generateUniqueCode();
        $data['is_active'] = $request->boolean('is_active', true);

        $member = Member::create($data);

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
            'departments' => Department::orderBy('name')->get(),
            'divisions' => Division::orderBy('member_type')->orderBy('name')->get(),
            'activityFeed' => $this->buildMemberActivityFeed($member),
        ]);
    }

    /**
     * Combines this member's own activity log with activity on their
     * dependents and reimbursements — those are logged on their own
     * models (see Dependent/Reimbursement::getActivitylogOptions), so a
     * change to "Juan's dependent" wouldn't otherwise show up when looking
     * at Juan's page.
     */
    private function buildMemberActivityFeed(Member $member): \Illuminate\Support\Collection
    {
        $activities = $member->activities()->with('causer')->get();

        foreach ($member->dependents as $dependent) {
            $activities = $activities->merge($dependent->activities()->with('causer')->get());
        }

        foreach ($member->reimbursements as $reimbursement) {
            $activities = $activities->merge($reimbursement->activities()->with('causer')->get());
        }

        return $activities->sortByDesc('created_at')->take(30)->values();
    }

    public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
    {
        $member->update($request->validated() + [
            'is_active' => $request->boolean('is_active', false),
        ]);

        return redirect()
            ->route('members.show', $member)
            ->with('status', "Member {$member->code} updated.");
    }

    /**
     * Toggles Active <-> Inactive for a single member — the per-row Action
     * column on the table. Separate from the bulk activate/deactivate in
     * MemberBulkActionController, which acts on multiple checked members
     * at once; this always affects exactly the one $member passed in.
     *
     * Deactivating requires a resignation_date (validated server-side by
     * UpdateMemberStatusRequest — the date shown to the admin in the modal
     * is never trusted as-is without that validation). Reactivating always
     * clears it back to NULL, regardless of what (if anything) was submitted.
     */
    public function updateStatus(UpdateMemberStatusRequest $request, Member $member): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $activating = ! $member->is_active;

        $member->update([
            'is_active' => $activating,
            'resignation_date' => $activating ? null : $request->validated('resignation_date'),
        ]);

        return redirect()
            ->route('members.index', $request->only(['search', 'type', 'department', 'division', 'status']))
            ->with('status', 'Member '.$member->code.' '.($activating ? 'reactivated.' : 'deactivated.'));
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
