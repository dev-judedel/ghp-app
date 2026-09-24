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
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(Request $request, BenefitAccrualService $accrualService): View
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

        // Default Apply Date for the Add Member modal: the CURRENT GHP
        // cycle's own start date, computed dynamically (never hard-coded to
        // one year) via the same coveragePeriod() logic everything else in
        // this app uses. Employee cycle (Apr–Mar) is used as the default
        // regardless of which member type ends up selected in the form —
        // it's just a suggested starting value, and stays fully editable.
        [$currentCycleStart, $currentCycleEnd] = $accrualService->coveragePeriod(Member::MEMBER_TYPE_EMPLOYEE, now());

        $viewData = [
            'members' => $members,
            'search' => $search,
            'type' => $type,
            'departmentId' => $departmentId,
            'divisionId' => $divisionId,
            'status' => $status,
            'departments' => Department::orderBy('name')->get(),
            'divisions' => Division::orderBy('member_type')->orderBy('name')->get(),
            'defaultApplyDate' => $currentCycleStart,
            'currentCycleStart' => $currentCycleStart,
            'currentCycleEnd' => $currentCycleEnd,
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

    public function store(StoreMemberRequest $request, BenefitAccrualService $accrualService): RedirectResponse
    {
        // Built as a mutable array (not $validated + [...]) on purpose:
        // 'code' is now a validated key too, and array union (+) keeps the
        // LEFT side's value on collisions — that would silently ignore this
        // override whenever the admin left it blank.
        $data = $request->validated();
        $data['code'] = $request->filled('code') ? $data['code'] : Member::generateUniqueCode();
        $data['is_active'] = $request->boolean('is_active', true);
        // deduction_start_date is never accepted from the request (see
        // StoreMemberRequest) — always derived from start_date, so it can
        // never drift from the business rule regardless of what a client
        // might try to submit.
        $data['deduction_start_date'] = $accrualService->resolveDeductionStartDate($data['start_date']);

        $member = Member::create($data);

        return redirect()
            ->route('members.show', $member)
            ->with('status', "Member {$member->code} created.");
    }

    public function show(Member $member, BenefitAccrualService $accrualService): View
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

        [$currentCycleStart, $currentCycleEnd] = $accrualService->coveragePeriod($member->member_type, now());

        // Distinct from $currentBenefitPeriod above: that's just the MOST
        // RECENT period on record (still shown as-is on the Benefit balance
        // card, even if stale from last cycle). This is specifically
        // "does a period for THIS cycle's exact date range already exist" —
        // what gates the Generate button (see generateBenefitPeriod()).
        $currentCyclePeriod = $member->benefitPeriods->first(
            fn ($period) => $period->from_date->isSameDay($currentCycleStart) && $period->to_date->isSameDay($currentCycleEnd)
        );

        return view('members.show', [
            'member' => $member,
            'currentBenefitPeriod' => $currentBenefitPeriod,
            'currentCyclePeriod' => $currentCyclePeriod,
            'departments' => Department::orderBy('name')->get(),
            'divisions' => Division::orderBy('member_type')->orderBy('name')->get(),
            'activityFeed' => $this->buildMemberActivityFeed($member),
            'currentCycleStart' => $currentCycleStart,
            'currentCycleEnd' => $currentCycleEnd,
            'requiredGhp' => $member->deduction_start_date ? $accrualService->requiredAmountForCycle($member) : null,
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

    public function update(UpdateMemberRequest $request, Member $member, BenefitAccrualService $accrualService): RedirectResponse
    {
        $member->update($request->validated() + [
            'is_active' => $request->boolean('is_active', false),
            'deduction_start_date' => $accrualService->resolveDeductionStartDate($request->validated('start_date')),
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

    /**
     * ================================================================
     * ONE GHP CYCLE -> ONE BENEFIT PERIOD.
     * ================================================================
     * Generates the CURRENT cycle's period exactly once. If one already
     * exists for this member's current cycle (Employees Apr–Mar, Agents
     * Jun–May — see BenefitAccrualService::coveragePeriod()), the request
     * is rejected rather than silently refreshing it again — this is the
     * backend half of the Generate button's "disabled until the cycle
     * ends" behavior (members/show.blade.php computes the same
     * check to grey out the button; this is the authority, not that).
     *
     * Wrapped in a locked transaction, same pattern as
     * ReimbursementController::assertWithinBalance() — makes the "does it
     * already exist" check and the insert atomic, so two nearly-simultaneous
     * clicks (or a duplicated manual request) can't both pass the check and
     * create two rows. The unique index on
     * benefit_periods(member_id, from_date, to_date) is the last-resort
     * backstop under that.
     */
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

        [$cycleStart, $cycleEnd] = $accrualService->coveragePeriod($member->member_type, now());

        $result = DB::transaction(function () use ($member, $accrualService, $cycleStart, $cycleEnd) {
            $lockedMember = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();

            $existing = $lockedMember->benefitPeriods()
                ->whereDate('from_date', $cycleStart->toDateString())
                ->whereDate('to_date', $cycleEnd->toDateString())
                ->first();

            if ($existing) {
                return ['created' => false, 'period' => $existing];
            }

            $lockedMember->loadMissing('dependents', 'reimbursements', 'benefitPeriods');

            return ['created' => true, 'period' => $accrualService->accrue($lockedMember)];
        });

        $period = $result['period'];

        if (! $result['created']) {
            return redirect()->route('members.show', $member)
                ->with('status', sprintf(
                    'Benefit period already generated for the current GHP cycle (%s – %s) — the Generate button stays disabled until this cycle ends on %s.',
                    $cycleStart->format('M d, Y'),
                    $cycleEnd->format('M d, Y'),
                    $cycleEnd->format('M d, Y'),
                ));
        }

        return redirect()->route('members.show', $member)
            ->with('status', sprintf(
                'Benefit period generated successfully: %s – %s. ₱%s available (₱%s used of ₱%s).',
                $period->from_date->format('M d, Y'),
                $period->to_date->format('M d, Y'),
                number_format($period->ghp_available, 2),
                number_format($period->ghp_used, 2),
                number_format($period->ghp_amount, 2),
            ));
    }
}
