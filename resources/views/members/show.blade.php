@extends('layouts.app')

@section('title', 'Member')

@php
    $hasReimbursementErrors = $errors->any() && $errors->has('or_amount');
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
                    <th>Birthdate</th>
                    <td>{{ optional($member->birthdate)->format('M d, Y') ?? '—' }} @if($member->age) ({{ $member->age }} yrs) @endif</td>
                    <th>Civil status</th>
                    <td>{{ $member->civil_status === 1 ? 'Married' : 'Single' }}</td>
                </tr>
                <tr>
                    <th>Deduction start</th>
                    <td>{{ optional($member->deduction_start_date)->format('M d, Y') ?? '—' }}</td>
                    <th>Old code</th>
                    <td class="code">{{ $member->old_code ?? '—' }}</td>
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
            @if (auth()->user()->isAdmin())
                <form method="POST" action="{{ route('members.generate-benefit-period', $member) }}" onsubmit="return confirm('Generate/update this year\'s benefit period for {{ $member->code }}?');">
                    @csrf
                    <button type="submit" class="btn btn-primary">Generate this year's benefit period</button>
                </form>
            @endif
        </div>

        @if ($currentBenefitPeriod)
            <div class="ledger-strip">
                <div class="ledger-row">
                    <span class="label">Coverage period</span>
                    <span class="amount ledger">{{ $currentBenefitPeriod->from_date->format('M d, Y') }} &ndash; {{ $currentBenefitPeriod->to_date->format('M d, Y') }}</span>
                </div>
                <div class="ledger-row">
                    <span class="label">GHP amount</span>
                    <span class="amount ledger">&#8369;{{ number_format($currentBenefitPeriod->ghp_amount, 2) }}</span>
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
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">{{ $member->dependents->count() }} on file</span>
                <h2>Dependents</h2>
            </div>
        </div>

        @if ($member->dependents->isEmpty())
            <div class="empty-state"><p>No dependents on file.</p></div>
        @else
            <table>
                <thead>
                    <tr><th>Name</th><th>Relation</th><th>Birthdate</th><th>Age</th><th>Eligible</th></tr>
                </thead>
                <tbody>
                    @foreach ($member->dependents as $dependent)
                        <tr>
                            <td>{{ $dependent->name }}</td>
                            <td>{{ $dependent->relation }}</td>
                            <td>{{ optional($dependent->birthdate)->format('M d, Y') ?? '—' }}</td>
                            <td class="num ledger">{{ $dependent->age ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $dependent->is_eligible ? 'badge-ok' : 'badge-warn' }}">
                                    {{ $dependent->is_eligible ? 'Eligible' : 'Not eligible' }}
                                </span>
                            </td>
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
            <table>
                <thead>
                    <tr><th>Period</th><th class="num">GHP amount</th><th class="num">Used</th><th class="num">Available</th></tr>
                </thead>
                <tbody>
                    @foreach ($member->benefitPeriods as $period)
                        <tr>
                            <td>{{ $period->from_date->format('M Y') }} &ndash; {{ $period->to_date->format('M Y') }}</td>
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
                <button type="button" class="btn btn-primary" onclick="fileReimbursementModal.showModal()">+ File reimbursement</button>
            @endif
        </div>

        @if ($member->reimbursements->isEmpty())
            <div class="empty-state"><p>No reimbursements on file.</p></div>
        @else
            <table>
                <thead>
                    <tr><th>OR date</th><th>OR no.</th><th>Hospital</th><th class="num">Amount</th></tr>
                </thead>
                <tbody>
                    @foreach ($member->reimbursements as $reimbursement)
                        <tr>
                            <td>{{ $reimbursement->or_date->format('M d, Y') }}</td>
                            <td class="code">{{ $reimbursement->or_no ?: '—' }}</td>
                            <td>{{ $reimbursement->hospital_name ?: '—' }}</td>
                            <td class="num amount">&#8369;{{ number_format($reimbursement->or_amount, 2) }}</td>
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
                    <span class="eyebrow">Needs review — auto-extracted</span>
                    <h2>Amount adjustment history</h2>
                </div>
            </div>
            <table>
                <thead>
                    <tr><th>Date</th><th class="num">Old amount</th><th class="num">New amount</th><th>Reason</th></tr>
                </thead>
                <tbody>
                    @foreach ($member->amountAdjustments as $adjustment)
                        <tr>
                            <td>{{ optional($adjustment->requested_at)->format('M d, Y') ?? '—' }}</td>
                            <td class="num amount">&#8369;{{ number_format($adjustment->old_amount, 2) }}</td>
                            <td class="num amount">&#8369;{{ number_format($adjustment->new_amount, 2) }}</td>
                            <td>{{ $adjustment->reason }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- File reimbursement modal --}}
    @if (auth()->user()->isAdmin())
        <dialog id="fileReimbursementModal" class="modal">
            <form method="POST" action="{{ route('members.reimbursements.store', $member) }}">
                @csrf

                <div class="modal-head">
                    <h2>File reimbursement</h2>
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
                        <div class="ledger-row total">
                            <span class="label">Available balance</span>
                            <span class="amount ledger">&#8369;{{ number_format($currentBenefitPeriod->ghp_available ?? 0, 2) }}</span>
                        </div>
                    </div>

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
                        <input type="number" id="or_amount" name="or_amount" value="{{ old('or_amount') }}" step="0.01" min="0.01" required>
                        <p class="hint">Amounts above the available balance are still recorded in full — see the note after submitting.</p>
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
                    <button type="submit" class="btn btn-primary">Record reimbursement</button>
                </div>
            </form>
        </dialog>

        @if ($hasReimbursementErrors)
            <script>fileReimbursementModal.showModal();</script>
        @endif
    @endif
@endsection
