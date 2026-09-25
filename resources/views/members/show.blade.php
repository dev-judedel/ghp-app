@extends('layouts.app')

@section('title', 'Member')

@php
    $hasReimbursementErrors = $errors->any() && $errors->has('or_amount');
    $hasDependentErrors = $errors->any() && ($errors->has('name') || $errors->has('relation'));
    $hasMemberEditErrors = $errors->any() && ($errors->has('code') || $errors->has('email') || $errors->has('spouse_name') || $errors->has('spouse_birthdate')) && old('_form') === 'edit_member';
    // Relation is free text — same case-insensitive spouse match DependentEligibilityService::hasSpouse() uses.
    $memberHasSpouse = $member->dependents->contains(fn ($d) => strtolower(trim($d->relation)) === 'spouse');
    $hasVoidErrors = $errors->any() && $errors->has('reason');
@endphp

@section('content')
    <div style="margin-bottom: 16px;">
        <a href="{{ route('members.index') }}">&larr; Back to members</a>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow code">{{ $member->code }}</span>
                <h2>{{ $member->full_name }}</h2>
            </div>
            <span class="badge {{ $member->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'badge-agent' : 'badge-employee' }}">
                {{ $member->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }}
            </span>
            <span class="badge {{ $member->is_active ? 'badge-ok' : 'badge-warn' }}" style="margin-left: 6px;">
                {{ $member->is_active ? 'Active' : 'Inactive' }}
            </span>
            <a href="{{ route('members.mdr', $member) }}" class="btn btn-ghost" style="margin-left: auto;" target="_blank">Print MDR</a>
            @if (auth()->user()->isAdmin())
                <button type="button" class="btn btn-ghost" onclick="editMemberModal.showModal()">Edit member</button>
            @endif
        </div>

        <table>
            <tbody>
                <tr>
                    <th style="width: 180px;">Division</th>
                    <td>{{ $member->division->name ?? '—' }}</td>
                    <th style="width: 180px;">Department</th>
                    <td>{{ $member->department->name ?? '—' }}</td>
                </tr>
                <tr>
                    <th>Email</th>
                    <td>{{ $member->email ?? '—' }}</td>
                    <th>Old code</th>
                    <td class="code">{{ $member->old_code ?? '—' }}</td>
                </tr>
                <tr>
                    <th>Birthdate</th>
                    <td>{{ optional($member->birthdate)->format('M d, Y') ?? '—' }} @if($member->age) ({{ $member->age }} yrs) @endif</td>
                    <th>Civil status</th>
                    <td>{{ $member->civil_status === 1 ? 'Married' : 'Single' }}</td>
                </tr>
                <tr>
                    <th>Deduction start</th>
                    <td>{{ optional($member->deduction_start_date)->format('M d, Y') ?? '—' }}</td>
                    @if (! $member->is_active && $member->resignation_date)
                        <th>Resignation date</th>
                        <td>{{ $member->resignation_date->format('F d, Y') }}</td>
                    @else
                        <th></th>
                        <td></td>
                    @endif
                </tr>
                @if ($member->address)
                    <tr>
                        <th>Address</th>
                        <td colspan="3">{{ $member->address }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">Current coverage year</span>
                <h2>Benefit balance</h2>
            </div>
            <div style="display: flex; gap: 8px;">
                @if (auth()->user()->isAdmin())
                    @if ($member->ghp_amount_is_manual)
                        <span class="badge badge-agent" style="align-self: center;">Manual override</span>
                        <form method="POST" action="{{ route('members.amount-adjustments.revert-to-automatic', $member) }}" onsubmit="return confirm('Revert {{ $member->code }} to the automatic GHP amount based on current dependents?');">
                            @csrf
                            <button type="submit" class="btn btn-ghost">Revert to automatic</button>
                        </form>
                    @endif
                    <button type="button" class="btn btn-ghost" onclick="adjustAmountModal.showModal()">Adjust GHP amount</button>
                @endif
                @if ($currentCyclePeriod)
                    <button type="button" class="btn btn-ghost" disabled
                        title="Benefit period already generated for the current GHP cycle ({{ $currentCycleStart->format('M d, Y') }} – {{ $currentCycleEnd->format('M d, Y') }}). Available again after {{ $currentCycleEnd->format('M d, Y') }}.">
                        Generate this year's benefit period
                    </button>
                @elseif (auth()->user()->isAdmin())
                    <button type="button" class="btn btn-primary" id="generateBenefitPeriodButton" onclick="generateBenefitPeriodModal.showModal()">Generate this year's benefit period</button>
                @else
                    {{-- The generate route is admin-only; don't offer a click that can only 403. --}}
                    <button type="button" class="btn btn-ghost" disabled title="Only an administrator can generate a benefit period.">Generate this year's benefit period</button>
                @endif
            </div>
        </div>

        @if ($currentBenefitPeriod)
            <div class="ledger-strip">
                <div class="ledger-row">
                    <span class="label">Coverage period</span>
                    <span class="amount ledger">{{ $currentBenefitPeriod->from_date->format('M d, Y') }} &ndash; {{ $currentBenefitPeriod->to_date->format('M d, Y') }}</span>
                </div>
                <div class="ledger-row">
                    <span class="label">GHP amount</span>
                    {{-- When a period already exists for THIS cycle, show the live
                         required rate (always current — reflects a dependent that
                         just became eligible, normally or via Immediate Eligibility)
                         instead of the persisted snapshot, which is deliberately NOT
                         refreshed just because a dependent changed (see
                         DependentEligibilityService::syncGhpAmount()). Used/Available
                         below are untouched — they still come from the persisted
                         period, exactly as generated/refreshed. When no period exists
                         yet for this cycle, $currentBenefitPeriod is really last
                         cycle's row, so this keeps showing ITS own amount rather than
                         mixing this cycle's live rate with last cycle's balance. --}}
                    <span class="amount ledger">&#8369;{{ number_format(($currentCyclePeriod && $requiredGhp) ? $requiredGhp['ghp_amount'] : $currentBenefitPeriod->ghp_amount, 2) }}</span>
                </div>
                <div class="ledger-row">
                    <span class="label">Used</span>
                    <span class="amount ledger">&#8369;{{ number_format($currentBenefitPeriod->ghp_used, 2) }}</span>
                </div>
                <div class="ledger-row total">
                    <span class="label">Available</span>
                    <span class="amount ledger">&#8369;{{ number_format($currentBenefitPeriod->ghp_available, 2) }}</span>
                </div>
            </div>
        @else
            <div class="empty-state">
                <h3>No benefit period on record</h3>
                <p>This member has no imported or generated coverage period yet.</p>
            </div>
        @endif

        @if ($requiredGhp)
            <div class="ledger-strip" style="margin-top: 10px;">
                <div class="ledger-row">
                    <span class="label">GHP monthly amount</span>
                    <span class="amount ledger">&#8369;{{ number_format($requiredGhp['monthly_rate'], 2) }}</span>
                </div>
                <div class="ledger-row">
                    <span class="label">Applicable months (this cycle)</span>
                    <span class="amount ledger">{{ $requiredGhp['applicable_months'] }}</span>
                </div>
                <div class="ledger-row total">
                    <span class="label">Required GHP amount</span>
                    <span class="amount ledger">&#8369;{{ number_format($requiredGhp['required_amount'], 2) }}</span>
                </div>
            </div>
            <p class="hint" style="margin-top: 8px; margin-bottom: 0;">
                Apply date: {{ optional($member->apply_date)->format('F Y') ?? '—' }}
                &nbsp;&middot;&nbsp;
                Start date: {{ optional($member->start_date)->format('F Y') ?? '—' }}
                &nbsp;&middot;&nbsp;
                First deduction: {{ optional($member->deduction_start_date)->format('F Y') ?? '—' }}
            </p>
        @endif

        @if ($currentCyclePeriod)
            <p class="hint" style="margin-top: 10px; margin-bottom: 0;">
                Benefit period already generated for the current GHP cycle ({{ $currentCycleStart->format('M d, Y') }} – {{ $currentCycleEnd->format('M d, Y') }}). The Generate button becomes available again after {{ $currentCycleEnd->format('M d, Y') }}.
            </p>
        @endif
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">{{ $member->dependents->count() }} on file</span>
                <h2>Dependents</h2>
            </div>
            @if (auth()->user()->isAdmin())
                <button type="button" class="btn btn-primary" onclick="openAddDependent()">+ Add dependent</button>
            @endif
        </div>

        @if ($member->dependents->isEmpty())
            <div class="empty-state"><p>No dependents on file.</p></div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Name</th><th>Relation</th><th>Birthdate</th><th>Age</th><th>Date added</th><th>Status</th><th>Eligible period</th>
                        @if (auth()->user()->isAdmin())
                            <th></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($member->dependents as $dependent)
                        @php
                            $statusLabels = ['eligible' => 'Eligible', 'immediate' => 'Immediate Eligible', 'pending' => 'Pending Eligibility', 'not_eligible' => 'Not eligible'];
                            $statusBadge = ['eligible' => 'badge-ok', 'immediate' => 'badge-ok', 'pending' => 'badge-agent', 'not_eligible' => 'badge-warn'];
                        @endphp
                        <tr>
                            <td>{{ $dependent->name }}</td>
                            <td>{{ $dependent->relation }}</td>
                            <td>{{ optional($dependent->birthdate)->format('M d, Y') ?? '—' }}</td>
                            <td class="num ledger">{{ $dependent->age ?? '—' }}</td>
                            <td>{{ optional($dependent->date_added)->format('M d, Y') ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $statusBadge[$dependent->eligibility_status] }}">
                                    {{ $statusLabels[$dependent->eligibility_status] }}
                                </span>
                            </td>
                            <td>{{ optional($dependent->eligibility_date)->format('F Y') ?? '—' }}</td>
                            @if (auth()->user()->isAdmin())
                                <td style="white-space: nowrap;">
                                    {{-- Only while still waiting on the normal Benefit Period rule; the backend re-checks this, the button is just a convenience. --}}
                                    @if ($dependent->canBeMadeImmediatelyEligible())
                                        <button type="button" class="btn btn-primary" style="padding: 4px 10px; font-size: 12px;"
                                            onclick="openImmediateEligibility({{ $dependent->id }}, {{ json_encode($dependent->name) }}, {{ json_encode($dependent->relation) }}, {{ json_encode(optional($dependent->eligibility_date)->format('F d, Y')) }})">
                                            Immediate Eligibility
                                        </button>
                                    @endif
                                    <button type="button" class="btn btn-ghost" style="padding: 4px 10px; font-size: 12px;"
                                        onclick="openEditDependent({{ $dependent->id }}, {{ json_encode($dependent->name) }}, {{ json_encode($dependent->relation) }}, {{ json_encode(optional($dependent->birthdate)->toDateString()) }})">
                                        Edit
                                    </button>
                                    <form method="POST" action="{{ route('members.dependents.destroy', [$member, $dependent]) }}" style="display: inline;" onsubmit="return confirm('Remove {{ $dependent->name }} as a dependent?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-ghost" style="padding: 4px 10px; font-size: 12px; color: var(--danger);">Remove</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">Coverage year history</span>
                <h2>Benefit periods</h2>
            </div>
        </div>

        @if ($member->benefitPeriods->isEmpty())
            <div class="empty-state"><p>No benefit period history.</p></div>
        @else
            <p class="hint" style="margin-top: -8px; margin-bottom: 10px;">Click a period to view its reimbursement records.</p>
            <table>
                <thead>
                    <tr><th>Period</th><th class="num">GHP amount</th><th class="num">Used</th><th class="num">Available</th></tr>
                </thead>
                <tbody>
                    @foreach ($member->benefitPeriods as $period)
                        <tr>
                            <td>
                                <a href="{{ route('members.benefit-periods.reimbursements', ['member' => $member, 'benefitPeriod' => $period]) }}">
                                    {{ $period->from_date->format('M Y') }} &ndash; {{ $period->to_date->format('M Y') }}
                                </a>
                            </td>
                            <td class="num amount">&#8369;{{ number_format($period->ghp_amount, 2) }}</td>
                            <td class="num amount">&#8369;{{ number_format($period->ghp_used, 2) }}</td>
                            <td class="num amount">&#8369;{{ number_format($period->ghp_available, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">Most recent 20</span>
                <h2>Reimbursements</h2>
            </div>
            @if (auth()->user()->isAdmin())
                <button type="button" class="btn btn-primary" onclick="openFileReimbursement()">+ File reimbursement</button>
            @endif
        </div>

        @if ($member->reimbursements->isEmpty())
            <div class="empty-state"><p>No reimbursements on file.</p></div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>OR date</th><th>OR no.</th><th>Hospital</th><th class="num">Amount</th>
                        @if (auth()->user()->isAdmin())
                            <th></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($member->reimbursements as $reimbursement)
                        <tr style="{{ $reimbursement->is_voided ? 'opacity: 0.55;' : '' }}">
                            <td>{{ $reimbursement->or_date->format('M d, Y') }}</td>
                            <td class="code">
                                {{ $reimbursement->or_no ?: '—' }}
                                @if ($reimbursement->is_voided)
                                    <span class="badge badge-warn" style="margin-left: 6px;">Voided</span>
                                @endif
                            </td>
                            <td>{{ $reimbursement->hospital_name ?: '—' }}</td>
                            <td class="num amount" style="{{ $reimbursement->is_voided ? 'text-decoration: line-through;' : '' }}">&#8369;{{ number_format($reimbursement->or_amount, 2) }}</td>
                            @if (auth()->user()->isAdmin())
                                <td style="white-space: nowrap;">
                                    @if ($reimbursement->is_voided)
                                        <form method="POST" action="{{ route('members.reimbursements.unvoid', [$member, $reimbursement]) }}" style="display: inline;" onsubmit="return confirm('Un-void this reimbursement? It will count against the GHP fund again.');">
                                            @csrf
                                            <button type="submit" class="btn btn-ghost" style="padding: 4px 10px; font-size: 12px;">Unvoid</button>
                                        </form>
                                    @else
                                        <button type="button" class="btn btn-ghost" style="padding: 4px 10px; font-size: 12px;"
                                            onclick="openEditReimbursement({{ $reimbursement->id }}, {{ json_encode($reimbursement->or_no) }}, {{ json_encode($reimbursement->or_date->toDateString()) }}, {{ $reimbursement->or_amount }}, {{ json_encode($reimbursement->hospital_name) }}, {{ json_encode($reimbursement->description) }}, {{ json_encode($reimbursement->remarks) }})">
                                            Edit
                                        </button>
                                        <button type="button" class="btn btn-ghost" style="padding: 4px 10px; font-size: 12px; color: var(--danger);"
                                            onclick="openVoidReimbursement({{ $reimbursement->id }}, {{ json_encode($reimbursement->or_no ?: $reimbursement->or_date->format('M d, Y')) }})">
                                            Void
                                        </button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($member->amountAdjustments->isNotEmpty())
        <div class="card">
            <div class="card-head">
                <div>
                    <span class="eyebrow">{{ $member->amountAdjustments->count() }} on record</span>
                    <h2>Amount adjustment history</h2>
                </div>
            </div>
            <div class="scroll-panel" tabindex="0" role="region" aria-label="Amount adjustment history (scrollable)">
                <table>
                    <thead>
                        <tr><th>Date</th><th class="num">Old amount</th><th class="num">New amount</th><th>Reason</th><th>Reference</th><th>Recorded by</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($member->amountAdjustments as $adjustment)
                            <tr>
                                <td>{{ optional($adjustment->requested_at)->format('M d, Y') ?? '—' }}</td>
                                <td class="num amount">&#8369;{{ number_format($adjustment->old_amount, 2) }}</td>
                                <td class="num amount">&#8369;{{ number_format($adjustment->new_amount, 2) }}</td>
                                <td>{{ $adjustment->reason }}</td>
                                <td>{{ $adjustment->request_reference ?? '—' }}</td>
                                <td>{{ $adjustment->recorded_by ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($activityFeed->isNotEmpty())
        <div class="card">
            <div class="card-head">
                <div>
                    <span class="eyebrow">Last 30 changes</span>
                    <h2>Recent activity</h2>
                </div>
            </div>
            <div class="scroll-panel scroll-panel-tall" tabindex="0" role="region" aria-label="Recent activity (scrollable)">
                <table>
                    <thead>
                        <tr><th style="width: 150px;">When</th><th style="width: 130px;">By</th><th>What changed</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($activityFeed as $activity)
                            <tr>
                                <td>{{ $activity->created_at->format('M d, Y g:i A') }}</td>
                                <td>{{ $activity->causer->name ?? 'System' }}</td>
                                <td>
                                    <div>{{ $activity->description }}</div>
                                    @if ($activity->properties->has('attributes'))
                                        <ul style="margin: 4px 0 0; padding-left: 16px; font-size: 12px; color: var(--ink-muted);">
                                            @foreach ($activity->properties->get('attributes') as $field => $newValue)
                                                @php $oldValue = $activity->properties->get('old')[$field] ?? null; @endphp
                                                <li>
                                                    <strong>{{ $field }}</strong>:
                                                    @if ($activity->properties->has('old'))
                                                        {{ $oldValue ?? '—' }} &rarr; {{ $newValue ?? '—' }}
                                                    @else
                                                        {{ $newValue ?? '—' }}
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Generate benefit period confirmation. Only rendered while the current cycle has NO period yet (the button that opens it is only enabled then). --}}
    @if (auth()->user()->isAdmin() && ! $currentCyclePeriod)
        <dialog id="generateBenefitPeriodModal" class="modal">
            <form method="POST" action="{{ route('members.generate-benefit-period', $member) }}" id="generateBenefitPeriodForm" onsubmit="return lockGenerateBenefitPeriodSubmit();">
                @csrf

                <div class="modal-head">
                    <h2>Generate benefit period</h2>
                    <button type="button" class="modal-close" onclick="generateBenefitPeriodModal.close()" aria-label="Close">&times;</button>
                </div>

                <div class="modal-body">
                    <p style="margin-bottom: 12px;">Generate the benefit period for <strong>{{ $currentCycleStart->format('F Y') }} &ndash; {{ $currentCycleEnd->format('F Y') }}</strong> for {{ $member->code }}?</p>
                    <p class="hint" style="margin: 0;">Only one benefit period can exist per GHP cycle. Once generated it can't be voided, deleted or regenerated, and this button stays disabled until the next cycle begins.</p>
                </div>

                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="generateBenefitPeriodModal.close()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="generateBenefitPeriodSubmit">Generate benefit period</button>
                </div>
            </form>
        </dialog>

        <script>
            // Double-click protection: the first submit locks the button and
            // shows a loading label; further submits are ignored. The page then
            // reloads and the button state comes from the database (disabled if
            // the period was created, still enabled if the request failed).
            // Safety nets so a failed/aborted request never leaves it stuck:
            // restore on bfcache return (Back button) and after a timeout.
            let generateBenefitPeriodSubmitting = false;

            function resetGenerateBenefitPeriodSubmit() {
                const submit = document.getElementById('generateBenefitPeriodSubmit');

                generateBenefitPeriodSubmitting = false;

                if (submit) {
                    submit.disabled = false;
                    submit.textContent = 'Generate benefit period';
                }
            }

            function lockGenerateBenefitPeriodSubmit() {
                if (generateBenefitPeriodSubmitting) {
                    return false;
                }

                generateBenefitPeriodSubmitting = true;

                const submit = document.getElementById('generateBenefitPeriodSubmit');
                submit.disabled = true;
                submit.textContent = 'Generating\u2026';

                setTimeout(resetGenerateBenefitPeriodSubmit, 20000);

                return true;
            }

            window.addEventListener('pageshow', resetGenerateBenefitPeriodSubmit);
        </script>
    @endif

    {{-- Adjust GHP amount modal --}}
    @if (auth()->user()->isAdmin())
        <dialog id="adjustAmountModal" class="modal">
            <form method="POST" action="{{ route('members.amount-adjustments.store', $member) }}">
                @csrf

                <div class="modal-head">
                    <h2>Adjust GHP amount</h2>
                    <button type="button" class="modal-close" onclick="adjustAmountModal.close()" aria-label="Close">&times;</button>
                </div>

                <div class="modal-body">
                    @if ($errors->any() && $errors->has('new_amount'))
                        <div class="field">
                            <p class="error" style="font-weight: 600;">Please fix the following:</p>
                            <ul style="margin: 4px 0 0; padding-left: 18px; color: var(--danger); font-size: 13px;">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <p style="margin-bottom: 12px;">Current GHP amount: <strong>&#8369;{{ number_format($member->ghp_amount, 2) }}</strong> ({{ $member->ghp_amount_is_manual ? 'manual override' : 'automatic, based on dependents' }})</p>

                    <div class="field">
                        <label for="new_amount">New GHP amount</label>
                        <input type="number" id="new_amount" name="new_amount" step="0.01" min="0" required>
                        <p class="hint">This overrides the automatic 3,600/4,200 calculation and sticks until reverted — it will NOT be recalculated when dependents change.</p>
                    </div>

                    <div class="field">
                        <label for="reason">Reason</label>
                        <textarea id="reason" name="reason" rows="3" required placeholder="e.g. Approved salary-grade adjustment per IT request form"></textarea>
                    </div>

                    <div style="display: flex; gap: 12px;">
                        <div class="field" style="flex: 1;">
                            <label for="request_reference">Reference (optional)</label>
                            <input type="text" id="request_reference" name="request_reference" placeholder="e.g. IT Request Form #123">
                        </div>
                        <div class="field" style="flex: 1; margin-bottom: 0;">
                            <label for="requested_at">Date (optional)</label>
                            <input type="date" id="requested_at" name="requested_at" value="{{ now()->toDateString() }}">
                        </div>
                    </div>
                </div>

                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="adjustAmountModal.close()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save adjustment</button>
                </div>
            </form>
        </dialog>

        @if ($errors->any() && $errors->has('new_amount'))
            <script>adjustAmountModal.showModal();</script>
        @endif
    @endif

    {{-- Add/Edit reimbursement modal (shared) --}}
    @if (auth()->user()->isAdmin())
        <dialog id="fileReimbursementModal" class="modal">
            <form method="POST" action="{{ route('members.reimbursements.store', $member) }}" id="reimbursementForm">
                @csrf
                <input type="hidden" name="_method" id="reimbursementFormMethod" value="POST">

                <div class="modal-head">
                    <h2 id="reimbursementModalTitle">File reimbursement</h2>
                    <button type="button" class="modal-close" onclick="fileReimbursementModal.close()" aria-label="Close">&times;</button>
                </div>

                <div class="modal-body">
                    @if ($hasReimbursementErrors)
                        <div class="field">
                            <p class="error" style="font-weight: 600;">Please fix the following:</p>
                            <ul style="margin: 4px 0 0; padding-left: 18px; color: var(--danger); font-size: 13px;">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="ledger-strip" style="margin-bottom: 14px;">
                        <div class="ledger-row">
                            <span class="label">Available GHP amount</span>
                            <span class="amount ledger" id="reimbursementAvailable">&#8369;{{ number_format($currentBenefitPeriod->ghp_available ?? 0, 2) }}</span>
                        </div>
                        <div class="ledger-row">
                            <span class="label">Requested reimbursement</span>
                            <span class="amount ledger" id="reimbursementRequested">&#8369;0.00</span>
                        </div>
                        <div class="ledger-row total">
                            <span class="label">Remaining after reimbursement</span>
                            <span class="amount ledger" id="reimbursementRemaining">&#8369;{{ number_format($currentBenefitPeriod->ghp_available ?? 0, 2) }}</span>
                        </div>
                    </div>
                    <p class="error" id="reimbursementBalanceWarning" style="display: none; margin: -6px 0 14px;"></p>

                    <div style="display: flex; gap: 12px;">
                        <div class="field" style="flex: 1;">
                            <label for="or_no">OR number</label>
                            <input type="text" id="or_no" name="or_no" value="{{ old('or_no') }}">
                        </div>
                        <div class="field" style="flex: 1;">
                            <label for="or_date">OR date</label>
                            <input type="date" id="or_date" name="or_date" value="{{ old('or_date', now()->toDateString()) }}" required>
                        </div>
                    </div>

                    <div class="field">
                        <label for="or_amount">Amount</label>
                        <input type="number" id="or_amount" name="or_amount" value="{{ old('or_amount') }}" step="0.01" min="0.01" required oninput="updateReimbursementPreview()">
                        <p class="hint">Cannot exceed the available GHP amount shown above — the balance shown is for the coverage period matching the OR date above (usually the current one).</p>
                    </div>

                    <div class="field">
                        <label for="hospital_name">Hospital / clinic</label>
                        <input type="text" id="hospital_name" name="hospital_name" value="{{ old('hospital_name') }}">
                    </div>

                    <div class="field">
                        <label for="description">Description</label>
                        <input type="text" id="description" name="description" value="{{ old('description') }}">
                    </div>

                    <div class="field" style="margin-bottom: 0;">
                        <label for="remarks">Remarks</label>
                        <textarea id="remarks" name="remarks" rows="2">{{ old('remarks') }}</textarea>
                    </div>
                </div>

                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="fileReimbursementModal.close()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="reimbursementFormSubmit" onclick="return updateReimbursementPreview();">Record reimbursement</button>
                </div>
            </form>
        </dialog>

        {{-- Void reimbursement modal --}}
        <dialog id="voidReimbursementModal" class="modal">
            <form method="POST" id="voidReimbursementForm">
                @csrf

                <div class="modal-head">
                    <h2>Void reimbursement</h2>
                    <button type="button" class="modal-close" onclick="voidReimbursementModal.close()" aria-label="Close">&times;</button>
                </div>

                <div class="modal-body">
                    @if ($hasVoidErrors)
                        <div class="field">
                            <p class="error" style="font-weight: 600;">Please fix the following:</p>
                            <ul style="margin: 4px 0 0; padding-left: 18px; color: var(--danger); font-size: 13px;">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <p style="margin-bottom: 12px;">Voiding <strong id="voidReimbursementLabel"></strong> keeps it on record but excludes it from the GHP fund's used/available balance. This can be undone later with "Unvoid".</p>

                    <div class="field" style="margin-bottom: 0;">
                        <label for="void_reason">Reason for voiding</label>
                        <textarea id="void_reason" name="reason" rows="3" required>{{ old('reason') }}</textarea>
                    </div>
                </div>

                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="voidReimbursementModal.close()">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: var(--danger);">Void reimbursement</button>
                </div>
            </form>
        </dialog>

        <script>
            const reimbursementStoreUrl = '{{ route('members.reimbursements.store', $member) }}';
            const reimbursementUpdateUrlBase = '{{ url('members/'.$member->id.'/reimbursements') }}';
            const reimbursementAvailableBase = {{ (float) ($currentBenefitPeriod->ghp_available ?? 0) }};
            let reimbursementCreditBack = 0;

            function formatPeso(value) {
                return '\u20b1' + value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            // Client-side preview only, for immediate feedback — the server
            // re-checks this exact rule under a row lock before saving
            // anything (see ReimbursementController::assertWithinBalance()),
            // since this can be bypassed and is never trusted on its own.
            function updateReimbursementPreview() {
                const amount = parseFloat(document.getElementById('or_amount').value) || 0;
                const available = reimbursementAvailableBase + reimbursementCreditBack;
                const remaining = available - amount;

                document.getElementById('reimbursementAvailable').textContent = formatPeso(available);
                document.getElementById('reimbursementRequested').textContent = formatPeso(amount);
                document.getElementById('reimbursementRemaining').textContent = formatPeso(Math.max(remaining, 0));

                const warning = document.getElementById('reimbursementBalanceWarning');
                const submitBtn = document.getElementById('reimbursementFormSubmit');
                const insufficient = amount > available;

                warning.style.display = insufficient ? 'block' : 'none';
                warning.textContent = insufficient
                    ? 'Insufficient GHP balance. Maximum reimbursement available: ' + formatPeso(available)
                    : '';
                submitBtn.disabled = insufficient || amount <= 0;

                return ! submitBtn.disabled;
            }

            function openFileReimbursement() {
                document.getElementById('reimbursementModalTitle').textContent = 'File reimbursement';
                document.getElementById('reimbursementForm').action = reimbursementStoreUrl;
                document.getElementById('reimbursementFormMethod').value = 'POST';
                document.getElementById('reimbursementFormSubmit').textContent = 'Record reimbursement';
                document.getElementById('or_no').value = '';
                document.getElementById('or_date').value = '{{ now()->toDateString() }}';
                document.getElementById('or_amount').value = '';
                document.getElementById('hospital_name').value = '';
                document.getElementById('description').value = '';
                document.getElementById('remarks').value = '';
                reimbursementCreditBack = 0;
                updateReimbursementPreview();
                fileReimbursementModal.showModal();
            }

            function openEditReimbursement(id, orNo, orDate, orAmount, hospital, description, remarks) {
                document.getElementById('reimbursementModalTitle').textContent = 'Edit reimbursement';
                document.getElementById('reimbursementForm').action = reimbursementUpdateUrlBase + '/' + id;
                document.getElementById('reimbursementFormMethod').value = 'PUT';
                document.getElementById('reimbursementFormSubmit').textContent = 'Update reimbursement';
                document.getElementById('or_no').value = orNo || '';
                document.getElementById('or_date').value = orDate;
                document.getElementById('or_amount').value = orAmount;
                document.getElementById('hospital_name').value = hospital || '';
                document.getElementById('description').value = description || '';
                document.getElementById('remarks').value = remarks || '';
                // This reimbursement's own current amount is still baked into
                // the available balance shown above (server-computed from
                // the CURRENT period) — credit it back client-side too so the
                // preview doesn't falsely warn about a claim that's just
                // being re-saved unchanged. Approximate on purpose (assumes
                // the OR date stays in the current period); the server's own
                // check in update() applies this precisely, per period.
                reimbursementCreditBack = parseFloat(orAmount) || 0;
                updateReimbursementPreview();
                fileReimbursementModal.showModal();
            }

            function openVoidReimbursement(id, label) {
                document.getElementById('voidReimbursementLabel').textContent = label;
                document.getElementById('voidReimbursementForm').action = reimbursementUpdateUrlBase + '/' + id + '/void';
                document.getElementById('void_reason').value = '';
                voidReimbursementModal.showModal();
            }

            @if ($hasReimbursementErrors)
                reimbursementCreditBack = 0;
                updateReimbursementPreview();
                fileReimbursementModal.showModal();
            @endif

            @if ($hasVoidErrors)
                voidReimbursementModal.showModal();
            @endif
        </script>
    @endif

    {{-- Add/Edit dependent modal (shared) --}}
    @if (auth()->user()->isAdmin())
        <dialog id="dependentModal" class="modal">
            <form method="POST" action="{{ route('members.dependents.store', $member) }}" id="dependentForm">
                @csrf
                <input type="hidden" name="_method" id="dependentFormMethod" value="POST">

                <div class="modal-head">
                    <h2 id="dependentModalTitle">Add dependent</h2>
                    <button type="button" class="modal-close" onclick="dependentModal.close()" aria-label="Close">&times;</button>
                </div>

                <div class="modal-body">
                    @if ($hasDependentErrors)
                        <div class="field">
                            <p class="error" style="font-weight: 600;">Please fix the following:</p>
                            <ul style="margin: 4px 0 0; padding-left: 18px; color: var(--danger); font-size: 13px;">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="field">
                        <label for="dependent_name">Name</label>
                        <input type="text" id="dependent_name" name="name" value="{{ old('name') }}" required>
                    </div>

                    <div class="field">
                        <label for="dependent_relation">Relation</label>
                        <input type="text" id="dependent_relation" name="relation" value="{{ old('relation') }}" list="relation-options" required>
                        <datalist id="relation-options">
                            <option value="Spouse">
                            <option value="Son">
                            <option value="Daughter">
                            <option value="Child">
                        </datalist>
                        <p class="hint">"Son"/"Daughter"/"Child" are eligible only under 21. Spouse and other relations are always eligible.</p>
                    </div>

                    <p class="hint" style="margin-top: -6px;">Adding a dependent now will not raise the GHP amount for the coverage period already in progress — the higher amount applies starting the next coverage cycle.</p>

                    <div class="field" style="margin-bottom: 0;">
                        <label for="dependent_birthdate">Birthdate</label>
                        <input type="date" id="dependent_birthdate" name="birthdate" value="{{ old('birthdate') }}">
                    </div>
                </div>

                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="dependentModal.close()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="dependentFormSubmit">Save dependent</button>
                </div>
            </form>
        </dialog>

        <script>
            const dependentStoreUrl = '{{ route('members.dependents.store', $member) }}';
            const dependentUpdateUrlBase = '{{ url('members/'.$member->id.'/dependents') }}';

            function openAddDependent() {
                document.getElementById('dependentModalTitle').textContent = 'Add dependent';
                document.getElementById('dependentForm').action = dependentStoreUrl;
                document.getElementById('dependentFormMethod').value = 'POST';
                document.getElementById('dependentFormSubmit').textContent = 'Save dependent';
                document.getElementById('dependent_name').value = '';
                document.getElementById('dependent_relation').value = '';
                document.getElementById('dependent_birthdate').value = '';
                dependentModal.showModal();
            }

            function openEditDependent(id, name, relation, birthdate) {
                document.getElementById('dependentModalTitle').textContent = 'Edit dependent';
                document.getElementById('dependentForm').action = dependentUpdateUrlBase + '/' + id;
                document.getElementById('dependentFormMethod').value = 'PUT';
                document.getElementById('dependentFormSubmit').textContent = 'Update dependent';
                document.getElementById('dependent_name').value = name;
                document.getElementById('dependent_relation').value = relation;
                document.getElementById('dependent_birthdate').value = birthdate || '';
                dependentModal.showModal();
            }

            @if ($hasDependentErrors)
                dependentModal.showModal();
            @endif
        </script>
    @endif
    {{-- Immediate Eligibility confirmation (administrator-controlled exception to the normal Benefit Period waiting rule) --}}
    @if (auth()->user()->isAdmin())
        <dialog id="immediateEligibilityModal" class="modal">
            <form method="POST" id="immediateEligibilityForm" action="">
                @csrf

                <div class="modal-head">
                    <h2>Immediate Dependent Eligibility</h2>
                    <button type="button" class="modal-close" onclick="immediateEligibilityModal.close()" aria-label="Close">&times;</button>
                </div>

                <div class="modal-body">
                    <p style="margin-bottom: 12px;">You are about to make this dependent immediately eligible.</p>

                    <table>
                        <tbody>
                            <tr><th style="width: 200px;">Dependent</th><td id="immediateEligibilityName"></td></tr>
                            <tr><th>Relationship</th><td id="immediateEligibilityRelation"></td></tr>
                            <tr><th>Current benefit period</th><td>{{ $currentCycleStart->format('F Y') }} &ndash; {{ $currentCycleEnd->format('F Y') }}</td></tr>
                            <tr><th>Normal eligibility</th><td>After the current benefit period ends<span id="immediateEligibilityNormalDate"></span>.</td></tr>
                            <tr><th>Immediate eligibility</th><td>Eligible immediately.</td></tr>
                        </tbody>
                    </table>

                    <p class="hint" style="margin-top: 12px; margin-bottom: 0;">
                        This bypasses the normal waiting rule for this dependent only, and is recorded in the activity log. It does not generate, change or reopen any benefit period.
                    </p>
                    <p style="margin: 12px 0 0;"><strong>Are you sure you want to continue?</strong></p>
                </div>

                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="immediateEligibilityModal.close()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm Immediate Eligibility</button>
                </div>
            </form>
        </dialog>

        <script>
            const immediateEligibilityUrlBase = '{{ url('members/'.$member->id.'/dependents') }}';

            // Only fills in and opens the confirmation — nothing changes until
            // the admin submits it, and the server re-validates everything.
            function openImmediateEligibility(id, name, relation, normalDate) {
                document.getElementById('immediateEligibilityForm').action = immediateEligibilityUrlBase + '/' + id + '/immediate-eligibility';
                document.getElementById('immediateEligibilityName').textContent = name;
                document.getElementById('immediateEligibilityRelation').textContent = relation;
                document.getElementById('immediateEligibilityNormalDate').textContent = normalDate ? ' (from ' + normalDate + ')' : '';
                immediateEligibilityModal.showModal();
            }
        </script>
    @endif

    {{-- Edit member modal — same field structure as the Add Member modal on the list page, for consistency --}}
    @if (auth()->user()->isAdmin())
        <dialog id="editMemberModal" class="modal" style="max-width: 640px;">
            <form method="POST" action="{{ route('members.update', $member) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="_form" value="edit_member">

                <div class="modal-head">
                    <h2>Edit member</h2>
                    <button type="button" class="modal-close" onclick="editMemberModal.close()" aria-label="Close">&times;</button>
                </div>

                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    @if ($hasMemberEditErrors)
                        <div class="field">
                            <p class="error" style="font-weight: 600;">Please fix the following:</p>
                            <ul style="margin: 4px 0 0; padding-left: 18px; color: var(--danger); font-size: 13px;">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <h3 style="margin-bottom: 10px;">Identity</h3>
                    <div style="display: flex; gap: 12px;">
                        <div class="field" style="flex: 1;">
                            <label for="edit_code">Member code</label>
                            <input type="text" id="edit_code" name="code" value="{{ old('code', $member->code) }}" required>
                        </div>
                        <div class="field" style="flex: 1;">
                            <label for="edit_email">Email account</label>
                            <input type="email" id="edit_email" name="email" value="{{ old('email', $member->email) }}">
                        </div>
                        <div class="field" style="flex: 1;">
                            <label for="edit_old_code">Old code (optional)</label>
                            <input type="text" id="edit_old_code" name="old_code" value="{{ old('old_code', $member->old_code) }}">
                        </div>
                    </div>

                    <div class="field">
                        <label>Member type</label>
                        <div class="radio-group" style="flex-direction: row; gap: 20px;">
                            <label><input type="radio" name="member_type" value="0" @checked(old('member_type', $member->member_type) == 0)> Employee</label>
                            <label><input type="radio" name="member_type" value="1" @checked(old('member_type', $member->member_type) == 1)> Agent</label>
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px;">
                        <div class="field" style="flex: 1;">
                            <label for="edit_last_name">Last name</label>
                            <input type="text" id="edit_last_name" name="last_name" value="{{ old('last_name', $member->last_name) }}" required>
                        </div>
                        <div class="field" style="flex: 1;">
                            <label for="edit_first_name">First name</label>
                            <input type="text" id="edit_first_name" name="first_name" value="{{ old('first_name', $member->first_name) }}" required>
                        </div>
                        <div class="field" style="flex: 1;">
                            <label for="edit_middle_name">Middle name</label>
                            <input type="text" id="edit_middle_name" name="middle_name" value="{{ old('middle_name', $member->middle_name) }}">
                        </div>
                    </div>

                    <div class="field">
                        <label for="edit_address">Address</label>
                        <input type="text" id="edit_address" name="address" value="{{ old('address', $member->address) }}">
                    </div>

                    <div style="display: flex; gap: 12px;">
                        <div class="field" style="flex: 1;">
                            <label for="edit_birthdate">Birthdate</label>
                            <input type="date" id="edit_birthdate" name="birthdate" value="{{ old('birthdate', optional($member->birthdate)->toDateString()) }}">
                        </div>
                        <div class="field" style="flex: 1;">
                            <label>Civil status</label>
                            <div class="radio-group" style="flex-direction: row; gap: 20px; padding-top: 9px;">
                                <label><input type="radio" name="civil_status" value="0" @checked(old('civil_status', $member->civil_status) == 0) onchange="toggleSpouseSection()"> Single</label>
                                <label><input type="radio" name="civil_status" value="1" @checked(old('civil_status', $member->civil_status) == 1) onchange="toggleSpouseSection()"> Married</label>
                            </div>
                        </div>
                    </div>

                    {{-- Dependent Spouse: only shown while Civil status = Married. Saved as an ordinary dependent (relation = Spouse). --}}
                    <div id="spouseSection" style="display: none; border: 1px solid var(--border, #d9d9d9); border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;">
                        <h3 style="margin-bottom: 8px;">Dependent Spouse</h3>

                        @if ($memberHasSpouse)
                            <p class="hint" style="margin: 0;">This member already has a spouse dependent. Please review the existing dependent record in the Dependents table — no second spouse will be created.</p>
                        @else
                            <div style="display: flex; gap: 12px;">
                                <div class="field" style="flex: 1;">
                                    <label for="spouse_name">Spouse name <span class="error">*</span></label>
                                    <input type="text" id="spouse_name" name="spouse_name" value="{{ old('spouse_name') }}" disabled>
                                    @error('spouse_name') <p class="error">{{ $message }}</p> @enderror
                                </div>
                                <div class="field" style="flex: 1;">
                                    <label for="spouse_birthdate">Birthday <span class="error">*</span></label>
                                    <input type="date" id="spouse_birthdate" name="spouse_birthdate" value="{{ old('spouse_birthdate') }}" max="{{ now()->toDateString() }}" disabled>
                                    @error('spouse_birthdate') <p class="error">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <div style="display: flex; gap: 12px;">
                                <div class="field" style="flex: 1; margin-bottom: 0;">
                                    <label>Relationship</label>
                                    <div style="padding-top: 9px;">Spouse</div>
                                </div>
                                <div class="field" style="flex: 1; margin-bottom: 0;">
                                    <label for="spouse_eligibility">Eligibility</label>
                                    <select id="spouse_eligibility" name="spouse_eligibility" disabled>
                                        <option value="normal" @selected(old('spouse_eligibility', 'normal') === 'normal')>Normal (wait for the benefit period)</option>
                                        <option value="immediate" @selected(old('spouse_eligibility') === 'immediate')>Immediate</option>
                                    </select>
                                </div>
                            </div>
                            <p class="hint" style="margin: 8px 0 0;">Marking a member Married does not by itself make the spouse immediately eligible — leave this on Normal unless a rush is needed. You can also use "Immediate Eligibility" on the Dependents table later.</p>
                        @endif
                    </div>

                    <h3 style="margin: 18px 0 10px;">Assignment</h3>
                    <div style="display: flex; gap: 12px;">
                        <div class="field" style="flex: 1;">
                            <label for="edit_division_id">Division</label>
                            <select id="edit_division_id" name="division_id">
                                <option value="">— None —</option>
                                @foreach ($divisions as $division)
                                    <option value="{{ $division->id }}" @selected(old('division_id', $member->division_id) == $division->id)>
                                        {{ $division->name }} ({{ $division->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="flex: 1;">
                            <label for="edit_department_id">Department</label>
                            <select id="edit_department_id" name="department_id">
                                <option value="">— None —</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}" @selected(old('department_id', $member->department_id) == $department->id)>
                                        {{ $department->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $member->is_active)) style="width: auto; margin-right: 6px;"> Active</label>
                    </div>

                    <h3 style="margin: 18px 0 10px;">Benefit setup</h3>
                    <div style="display: flex; gap: 12px;">
                        <div class="field" style="flex: 1;">
                            <label for="edit_apply_date">GHP apply date <span class="error">*</span></label>
                            <input type="date" id="edit_apply_date" name="apply_date" value="{{ old('apply_date', optional($member->apply_date)->toDateString()) }}" required>
                        </div>
                        <div class="field" style="flex: 1;">
                            <label for="edit_start_date">Member start date <span class="error">*</span></label>
                            <input type="date" id="edit_start_date" name="start_date" value="{{ old('start_date', optional($member->start_date)->toDateString()) }}" required oninput="updateEditDeductionPreview()">
                        </div>
                    </div>
                    <p class="hint" style="margin-top: -8px; margin-bottom: 12px;">
                        First deduction date: <strong id="edit_deduction_preview">{{ optional($member->deduction_start_date)->format('F d, Y') ?? '—' }}</strong>
                        (auto-calculated: the 1st of the month after Start Date, never the start month itself — not directly editable)
                        &nbsp;&middot;&nbsp;
                        GHP cycle: <strong>{{ $currentCycleStart->format('M d, Y') }} &ndash; {{ $currentCycleEnd->format('M d, Y') }}</strong>
                        <br>
                        GHP amount (currently &#8369;{{ number_format($member->ghp_amount, 2) }}{{ $member->ghp_amount_is_manual ? ', manual override' : ', automatic' }}) isn't edited here — use "Adjust GHP amount" on the Benefit balance card above.
                    </p>

                    <div class="field" style="margin-bottom: 0;">
                        <label for="edit_remarks">Remarks</label>
                        <textarea id="edit_remarks" name="remarks" rows="2">{{ old('remarks', $member->remarks) }}</textarea>
                    </div>
                </div>

                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="editMemberModal.close()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </dialog>

        @if ($hasMemberEditErrors)
            <script>editMemberModal.showModal();</script>
        @endif

        <script>
            // Show the spouse section only for Civil status = Married. The
            // inputs are disabled while hidden so they are never submitted,
            // and get `required` only when the member is being CHANGED to
            // Married (an already-Married member isn't forced to add one).
            // Display only — UpdateMemberRequest is the real validation.
            const memberWasMarried = {{ $member->civil_status === \App\Models\Member::CIVIL_STATUS_MARRIED ? 'true' : 'false' }};

            function toggleSpouseSection() {
                const married = document.querySelector('#editMemberModal input[name="civil_status"]:checked')?.value === '1';
                const section = document.getElementById('spouseSection');

                if (! section) {
                    return;
                }

                section.style.display = married ? 'block' : 'none';

                ['spouse_name', 'spouse_birthdate', 'spouse_eligibility'].forEach(function (id) {
                    const el = document.getElementById(id);

                    if (! el) {
                        return;
                    }

                    el.disabled = ! married;

                    if (id !== 'spouse_eligibility') {
                        el.required = married && ! memberWasMarried;
                    }
                });
            }

            toggleSpouseSection();

            // Same live-preview mirror as the Add Member modal (see
            // members/index.blade.php) — display only, server is authoritative.
            function updateEditDeductionPreview() {
                const startInput = document.getElementById('edit_start_date');
                const preview = document.getElementById('edit_deduction_preview');

                if (! startInput || ! startInput.value || ! preview) {
                    return;
                }

                const start = new Date(startInput.value + 'T00:00:00');
                const deduction = new Date(start.getFullYear(), start.getMonth() + 1, 1);

                preview.textContent = deduction.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            }
        </script>
    @endif
@endsection
