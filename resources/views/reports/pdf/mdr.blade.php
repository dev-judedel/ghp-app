<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 32px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #16211D; }
        h1 { font-size: 18px; margin: 0 0 2px; color: #0A3F37; }
        h2 { font-size: 12px; margin: 18px 0 6px; color: #0F5C50; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #0F5C50; padding-bottom: 4px; }
        .subtitle { font-size: 10px; color: #5B6B65; margin-bottom: 10px; }
        .infotable { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .infotable td { padding: 4px 6px; font-size: 10.5px; vertical-align: top; }
        .infotable td.label { color: #5B6B65; width: 130px; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.data th { background: #0F5C50; color: #fff; text-align: left; padding: 5px 7px; font-size: 8.5px; text-transform: uppercase; }
        table.data td { padding: 5px 7px; border-bottom: 1px solid #DCE3DF; font-size: 9.5px; }
        .num { text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
        .ledger { width: 60%; margin-top: 4px; }
        .ledger td { padding: 5px 0; border-bottom: 1px dashed #DCE3DF; font-size: 11px; }
        .ledger td.amt { text-align: right; font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; }
        .ledger tr.total td { font-size: 14px; color: #0F5C50; border-bottom: none; border-top: 2px solid #0F5C50; padding-top: 8px; }
        .footer { margin-top: 20px; font-size: 8px; color: #8FB3A9; }
        .report-title { text-align: center; font-size: 15px; font-weight: bold; color: #0A3F37; text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 2px; }
        .report-subtitle { text-align: center; font-size: 10px; color: #5B6B65; margin: 0 0 16px; }
        hr.divider { border: none; border-top: 2px solid #0F5C50; margin: 0 0 14px; }
    </style>
</head>
<body>
    @include('reports.pdf.partials.company-header')

    <p class="report-title">Member Data Record</p>
    <p class="report-subtitle">Generated {{ $generatedAt->format('M d, Y g:i A') }}</p>
    <hr class="divider">

    <h2>Member Profile</h2>
    <table class="infotable">
        <tr>
            <td class="label">Member code</td><td>{{ $member->code }}</td>
            <td class="label">Type</td><td>{{ $member->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }}</td>
        </tr>
        <tr>
            <td class="label">Name</td><td>{{ $member->full_name }}</td>
            <td class="label">Status</td><td>{{ $member->is_active ? 'Active' : 'Inactive' }}</td>
        </tr>
        <tr>
            <td class="label">Division</td><td>{{ $member->division->name ?? '—' }}</td>
            <td class="label">Department</td><td>{{ $member->department->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Birthdate</td><td>{{ optional($member->birthdate)->format('M d, Y') ?? '—' }} @if($member->age)({{ $member->age }} yrs)@endif</td>
            <td class="label">Civil status</td><td>{{ $member->civil_status === 1 ? 'Married' : 'Single' }}</td>
        </tr>
        <tr>
            <td class="label">Apply date</td><td>{{ optional($member->apply_date)->format('M d, Y') ?? '—' }}</td>
            <td class="label">Deduction start</td><td>{{ optional($member->deduction_start_date)->format('M d, Y') ?? '—' }}</td>
        </tr>
        @if ($member->address)
            <tr>
                <td class="label">Address</td><td colspan="3">{{ $member->address }}</td>
            </tr>
        @endif
    </table>

    <h2>Current Benefit Balance</h2>
    @if ($currentBenefitPeriod)
        <table class="ledger">
            <tr><td>Coverage period</td><td class="amt">{{ $currentBenefitPeriod->from_date->format('M d, Y') }} &ndash; {{ $currentBenefitPeriod->to_date->format('M d, Y') }}</td></tr>
            <tr><td>GHP amount</td><td class="amt">₱{{ number_format($currentBenefitPeriod->ghp_amount, 2) }}</td></tr>
            <tr><td>Used</td><td class="amt">₱{{ number_format($currentBenefitPeriod->ghp_used, 2) }}</td></tr>
            <tr class="total"><td>Available</td><td class="amt">₱{{ number_format($currentBenefitPeriod->ghp_available, 2) }}</td></tr>
        </table>
    @else
        <p>No benefit period on record.</p>
    @endif

    <h2>Dependents ({{ $member->dependents->count() }})</h2>
    @if ($member->dependents->isEmpty())
        <p>No dependents on file.</p>
    @else
        <table class="data">
            <thead><tr><th>Name</th><th>Relation</th><th>Birthdate</th><th>Eligible</th></tr></thead>
            <tbody>
                @foreach ($member->dependents as $dependent)
                    <tr>
                        <td>{{ $dependent->name }}</td>
                        <td>{{ $dependent->relation }}</td>
                        <td>{{ optional($dependent->birthdate)->format('M d, Y') ?? '—' }}</td>
                        <td>{{ $dependent->is_eligible ? 'Eligible' : 'Not eligible' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Recent Reimbursements</h2>
    @if ($member->reimbursements->isEmpty())
        <p>No reimbursements on file.</p>
    @else
        <table class="data">
            <thead><tr><th>OR date</th><th>OR no.</th><th>Hospital</th><th class="num">Amount</th></tr></thead>
            <tbody>
                @foreach ($member->reimbursements as $reimbursement)
                    <tr>
                        <td>{{ $reimbursement->or_date->format('M d, Y') }}</td>
                        <td>{{ $reimbursement->or_no ?: '—' }}</td>
                        <td>{{ $reimbursement->hospital_name ?: '—' }}</td>
                        <td class="num">&#8369;{{ number_format($reimbursement->or_amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">Group Hospitalization Plan &mdash; internal system. Generated {{ $generatedAt->format('Y-m-d H:i') }}.</div>
</body>
</html>
