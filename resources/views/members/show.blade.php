@extends('layouts.app')

@section('title', 'Member')

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
@endsection
