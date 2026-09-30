<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Helvetica, Arial, sans-serif; font-size: 14px; color: #16211D; line-height: 1.5;">
    <p>Hello {{ $member->first_name }},</p>

    <p>
        Attached is your GHP reimbursement receipt for the coverage period
        <strong>{{ $benefitPeriod->from_date->format('M d, Y') }} &ndash; {{ $benefitPeriod->to_date->format('M d, Y') }}</strong>.
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; margin: 12px 0;">
        <tr>
            <td style="color: #5B6B65;">Member code</td>
            <td><strong>{{ $member->code }}</strong></td>
        </tr>
        <tr>
            <td style="color: #5B6B65;">Total reimbursed</td>
            <td><strong>&#8369;{{ number_format($total, 2) }}</strong></td>
        </tr>
        <tr>
            <td style="color: {{ $excessTotal > 0 ? '#C62828' : '#5B6B65' }};">Excess Deduction</td>
            <td><strong style="color: {{ $excessTotal > 0 ? '#C62828' : '#16211D' }};">&#8369;{{ number_format($excessTotal, 2) }}</strong></td>
        </tr>
    </table>

    @if ($reimbursements->isNotEmpty())
        <p style="margin-bottom: 4px;"><strong>Reimbursement details</strong></p>

        <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; margin: 0 0 12px; font-size: 13px;">
            <thead>
                <tr style="background: #E4EFEC; text-align: left;">
                    <th>OR date</th>
                    <th>OR no.</th>
                    <th style="text-align: right;">Reimbursement Amount</th>
                    <th style="text-align: right;">Available GHP</th>
                    <th style="text-align: right;">Excess Deduction</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($reimbursements as $reimbursement)
                    @php $red = $reimbursement->hasExcess(); @endphp
                    <tr style="border-bottom: 1px solid #DCE3DF; {{ $red ? 'color: #C62828;' : '' }} {{ $reimbursement->is_voided ? 'text-decoration: line-through;' : '' }}">
                        <td>{{ $reimbursement->or_date->format('M d, Y') }}</td>
                        <td>{{ $reimbursement->or_no ?: '—' }}{{ $reimbursement->is_voided ? ' (voided)' : '' }}</td>
                        <td style="text-align: right;">&#8369;{{ number_format($reimbursement->or_amount, 2) }}</td>
                        <td style="text-align: right;">{{ $reimbursement->available_ghp !== null ? '₱'.number_format($reimbursement->available_ghp, 2) : '—' }}</td>
                        <td style="text-align: right;{{ $red ? ' font-weight: bold;' : '' }}">&#8369;{{ number_format($reimbursement->excess_amount, 2) }}@if ($reimbursement->hasCoveredExcess())<br><span style="font-size: 11px; font-weight: normal;">remaining &middot; original &#8369;{{ number_format($reimbursement->original_excess_amount, 2) }}, covered &#8369;{{ number_format($reimbursement->excess_covered_amount, 2) }}</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($excessTotal > 0)
            <p style="color: #C62828; font-size: 12px; margin-top: 0;">
                Excess Deduction is the part of a reimbursement above the Available GHP at the time it was filed. It is recorded for tracking only and does not change your GHP usage or requirements.
            </p>
        @endif
    @endif

    @if (filled($note))
        <p style="padding: 10px 14px; background: #F2F4F2; border-left: 3px solid #0F5C50; white-space: pre-line;">{{ $note }}</p>
    @endif

    <p style="color: #5B6B65; font-size: 12px;">
        Sent by {{ $sentBy }} through the Group Hospitalization Plan system. The full receipt is in the attached PDF.
    </p>
</body>
</html>
