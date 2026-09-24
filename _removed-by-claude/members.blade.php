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
    </style>
</head>
<body>
    <h1>Member Directory</h1>
    <div class="subtitle">
        Generated {{ $generatedAt->format('M d, Y g:i A') }}
        &nbsp;&middot;&nbsp; {{ $members->count() }} member(s)
        @if ($filters['type'] ?? null) &middot; {{ ucfirst($filters['type']) }}s only @endif
        @if (($filters['status'] ?? 'active') !== 'all') &middot; {{ ucfirst($filters['status'] ?? 'active') }} only @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Email</th>
                <th>Type</th>
                <th>Division</th>
                <th>Department</th>
                <th>Status</th>
                <th>Date created</th>
                <th class="num">GHP amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($members as $member)
                <tr>
                    <td>{{ $member->code }}</td>
                    <td>{{ $member->full_name }}</td>
                    <td>{{ $member->email ?? '—' }}</td>
                    <td>{{ $member->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }}</td>
                    <td>{{ $member->division->name ?? '—' }}</td>
                    <td>{{ $member->department->name ?? '—' }}</td>
                    <td class="{{ $member->is_active ? 'badge-active' : 'badge-inactive' }}">
                        {{ $member->is_active ? 'Active' : 'Inactive' }}
                    </td>
                    <td>{{ $member->created_at->format('Y-m-d') }}</td>
                    <td class="num">&#8369;{{ number_format($member->ghp_amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">Group Hospitalization Plan &mdash; internal system. Generated {{ $generatedAt->format('Y-m-d H:i') }}. Contains no authentication credentials.</div>
</body>
</html>
