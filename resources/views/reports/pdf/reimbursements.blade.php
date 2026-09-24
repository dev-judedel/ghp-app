<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px 28px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #16211D; }
        h1 { font-size: 16px; margin: 0 0 2px; color: #0A3F37; }
        .subtitle { font-size: 10px; color: #5B6B65; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #0F5C50; color: #ffffff; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.03em; }
        td { padding: 5px 8px; border-bottom: 1px solid #DCE3DF; font-size: 9.5px; }
        tr:nth-child(even) td { background: #F2F4F2; }
        .num { text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
        .total-row td { border-top: 2px solid #0F5C50; font-weight: bold; background: #E4EFEC; }
        .voided td { color: #A6402C; text-decoration: line-through; }
        .voided-label { text-decoration: none; font-size: 8px; text-transform: uppercase; font-weight: bold; }
        .footer { margin-top: 16px; font-size: 8px; color: #8FB3A9; }
        .report-title { text-align: center; font-size: 15px; font-weight: bold; color: #0A3F37; text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 2px; }
        hr.divider { border: none; border-top: 2px solid #0F5C50; margin: 0 0 14px; }
    </style>
</head>
<body>
    @include('reports.pdf.partials.company-header')

    <p class="report-title">Reimbursement Report</p>
    <hr class="divider">
    <div class="subtitle">
        {{ \Carbon\Carbon::parse($from)->format('M d, Y') }} &ndash; {{ \Carbon\Carbon::parse($to)->format('M d, Y') }}
        &nbsp;&middot;&nbsp; Generated {{ $generatedAt->format('M d, Y g:i A') }}
        &nbsp;&middot;&nbsp; {{ count($reimbursements) }} claim(s)
        @if ($voidedCount) &middot; {{ $voidedCount }} voided (excluded from total, shown struck through) @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>OR date</th>
                <th>Member code</th>
                <th>Member name</th>
                <th>Type</th>
                <th>Division</th>
                <th>OR no.</th>
                <th>Hospital</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($reimbursements as $reimbursement)
                <tr class="{{ $reimbursement->is_voided ? 'voided' : '' }}">
                    <td>{{ $reimbursement->or_date->format('M d, Y') }}</td>
                    <td>{{ $reimbursement->member->code }}</td>
                    <td>{{ $reimbursement->member->full_name }}</td>
                    <td>{{ $reimbursement->member->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }}</td>
                    <td>{{ $reimbursement->member->division->name ?? '—' }}</td>
                    <td>{{ $reimbursement->or_no ?: '—' }} @if($reimbursement->is_voided)<span class="voided-label"> VOID</span>@endif</td>
                    <td>{{ $reimbursement->hospital_name ?: '—' }}</td>
                    <td class="num">&#8369;{{ number_format($reimbursement->or_amount, 2) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="7">Total</td>
                <td class="num">₱{{ number_format($total, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">Group Hospitalization Plan &mdash; internal system. Generated {{ $generatedAt->format('Y-m-d H:i') }}.</div>
</body>
</html>
