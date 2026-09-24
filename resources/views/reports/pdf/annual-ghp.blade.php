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
        .footer { margin-top: 16px; font-size: 8px; color: #8FB3A9; }
        .badge-active { color: #2E7D4F; font-weight: bold; }
        .badge-inactive { color: #A6402C; font-weight: bold; }
        .report-title { text-align: center; font-size: 15px; font-weight: bold; color: #0A3F37; text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 2px; }
        hr.divider { border: none; border-top: 2px solid #0F5C50; margin: 0 0 14px; }
    </style>
</head>
<body>
    @include('reports.pdf.partials.company-header')

    <p class="report-title">Annual GHP Report</p>
    <hr class="divider">
    <div class="subtitle">
        Coverage year: {{ $year ?: 'Latest on record per member' }}
        &nbsp;&middot;&nbsp; Generated {{ $generatedAt->format('M d, Y g:i A') }}
        &nbsp;&middot;&nbsp; {{ count($rows) }} member(s)
        @if ($filters['type']) &middot; {{ ucfirst($filters['type']) }}s only @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Type</th>
                <th>Division</th>
                <th>Department</th>
                <th>Status</th>
                <th>Coverage period</th>
                <th class="num">GHP amount</th>
                <th class="num">Used</th>
                <th class="num">Available</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row->member->code }}</td>
                    <td>{{ $row->member->full_name }}</td>
                    <td>{{ $row->member->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }}</td>
                    <td>{{ $row->member->division->name ?? '—' }}</td>
                    <td>{{ $row->member->department->name ?? '—' }}</td>
                    <td class="{{ $row->member->is_active ? 'badge-active' : 'badge-inactive' }}">
                        {{ $row->member->is_active ? 'Active' : 'Inactive' }}
                    </td>
                    <td>{{ $row->period->from_date->format('M d, Y') }} &ndash; {{ $row->period->to_date->format('M d, Y') }}</td>
                    <td class="num">&#8369;{{ number_format($row->period->ghp_amount, 2) }}</td>
                    <td class="num">&#8369;{{ number_format($row->period->ghp_used, 2) }}</td>
                    <td class="num">&#8369;{{ number_format($row->period->ghp_available, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">Group Hospitalization Plan &mdash; internal system. Generated {{ $generatedAt->format('Y-m-d H:i') }}.</div>
</body>
</html>
