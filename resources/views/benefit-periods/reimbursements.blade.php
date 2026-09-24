@extends('layouts.app')

@section('title', 'Reimbursements — '.$benefitPeriod->from_date->format('M Y').' to '.$benefitPeriod->to_date->format('M Y'))

@section('content')
    <div style="margin-bottom: 16px;">
        <a href="{{ route('members.show', $member) }}">&larr; Back to {{ $member->full_name }}</a>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow code">{{ $member->code }}</span>
                <h2>{{ $benefitPeriod->from_date->format('M d, Y') }} &ndash; {{ $benefitPeriod->to_date->format('M d, Y') }}</h2>
            </div>
            <div style="display: flex; gap: 8px;">
                <a href="{{ route('members.benefit-periods.reimbursements.pdf', ['member' => $member, 'benefitPeriod' => $benefitPeriod]) }}" class="btn btn-ghost" target="_blank">Print</a>
                <a href="{{ route('members.benefit-periods.reimbursements.pdf', ['member' => $member, 'benefitPeriod' => $benefitPeriod, 'download' => 1]) }}" class="btn btn-primary">Download PDF</a>
            </div>
        </div>

        <div class="ledger-strip">
            <div class="ledger-row">
                <span class="label">Member</span>
                <span class="amount ledger">{{ $member->full_name }}</span>
            </div>
            <div class="ledger-row">
                <span class="label">GHP amount (this period)</span>
                <span class="amount ledger">&#8369;{{ number_format($benefitPeriod->ghp_amount, 2) }}</span>
            </div>
            <div class="ledger-row total">
                <span class="label">Total reimbursed (this period)</span>
                <span class="amount ledger">&#8369;{{ number_format($total, 2) }}</span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">{{ $reimbursements->count() }} record(s)</span>
                <h2>Reimbursements</h2>
            </div>
        </div>

        @if ($reimbursements->isEmpty())
            <div class="empty-state">
                <h3>No reimbursement records found for:</h3>
                <p>{{ $benefitPeriod->from_date->format('M d, Y') }} &ndash; {{ $benefitPeriod->to_date->format('M d, Y') }}</p>
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Reimbursement period</th>
                        <th>OR date</th>
                        <th>OR no.</th>
                        <th>Hospital</th>
                        <th class="num">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reimbursements as $reimbursement)
                        <tr style="{{ $reimbursement->is_voided ? 'opacity: 0.55;' : '' }}">
                            <td>{{ $reimbursement->or_date->format('F Y') }}</td>
                            <td>{{ $reimbursement->or_date->format('M d, Y') }}</td>
                            <td class="code">{{ $reimbursement->or_no ?: '—' }}</td>
                            <td>{{ $reimbursement->hospital_name ?: '—' }}</td>
                            <td class="num amount" style="{{ $reimbursement->is_voided ? 'text-decoration: line-through;' : '' }}">&#8369;{{ number_format($reimbursement->or_amount, 2) }}</td>
                            <td>
                                <span class="badge {{ $reimbursement->is_voided ? 'badge-warn' : 'badge-ok' }}">
                                    {{ $reimbursement->is_voided ? 'Voided' : 'Active' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="ledger-strip" style="margin-top: 14px;">
                <div class="ledger-row">
                    <span class="label">Total reimbursement records</span>
                    <span class="amount ledger">{{ $reimbursements->count() }}@if($voidedCount) &nbsp;({{ $voidedCount }} voided, excluded from total)@endif</span>
                </div>
                <div class="ledger-row total">
                    <span class="label">Total reimbursement amount</span>
                    <span class="amount ledger">&#8369;{{ number_format($total, 2) }}</span>
                </div>
            </div>
        @endif
    </div>
@endsection
