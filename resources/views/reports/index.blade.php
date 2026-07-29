@extends('layouts.app')

@section('title', 'Reports')

@section('content')
    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">PDF export</span>
                <h2>Annual GHP report</h2>
            </div>
        </div>
        <p class="hint" style="margin-bottom: 14px;">One row per member showing their benefit period for the selected coverage year. Leave "Coverage year" blank to use each member's most recent period on record.</p>

        <form method="GET" action="{{ route('reports.annual-ghp') }}" target="_blank">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                <div class="field" style="width: 160px; margin-bottom: 0;">
                    <label for="ghp_year">Coverage year</label>
                    <select id="ghp_year" name="year">
                        <option value="">Latest on record</option>
                        @foreach ($coverageYears as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field" style="width: 160px; margin-bottom: 0;">
                    <label for="ghp_type">Type</label>
                    <select id="ghp_type" name="type">
                        <option value="">All</option>
                        <option value="employee">Employees</option>
                        <option value="agent">Agents</option>
                    </select>
                </div>

                <div class="field" style="width: 200px; margin-bottom: 0;">
                    <label for="ghp_division">Division</label>
                    <select id="ghp_division" name="division">
                        <option value="">All</option>
                        @foreach ($divisions as $division)
                            <option value="{{ $division->id }}">{{ $division->name }} ({{ $division->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="field" style="width: 200px; margin-bottom: 0;">
                    <label for="ghp_department">Department</label>
                    <select id="ghp_department" name="department">
                        <option value="">All</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field" style="width: 160px; margin-bottom: 0;">
                    <label for="ghp_status">Status</label>
                    <select id="ghp_status" name="status">
                        <option value="active">Active only</option>
                        <option value="inactive">Inactive only</option>
                        <option value="all">All</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Generate PDF</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">PDF export</span>
                <h2>Reimbursement report</h2>
            </div>
        </div>
        <p class="hint" style="margin-bottom: 14px;">All reimbursements filed within a date range, optionally filtered by member type, division, or department.</p>

        <form method="GET" action="{{ route('reports.reimbursements') }}" target="_blank">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                <div class="field" style="width: 160px; margin-bottom: 0;">
                    <label for="rb_from">From</label>
                    <input type="date" id="rb_from" name="from" value="{{ now()->startOfYear()->toDateString() }}" required>
                </div>
                <div class="field" style="width: 160px; margin-bottom: 0;">
                    <label for="rb_to">To</label>
                    <input type="date" id="rb_to" name="to" value="{{ now()->toDateString() }}" required>
                </div>

                <div class="field" style="width: 160px; margin-bottom: 0;">
                    <label for="rb_type">Type</label>
                    <select id="rb_type" name="type">
                        <option value="">All</option>
                        <option value="employee">Employees</option>
                        <option value="agent">Agents</option>
                    </select>
                </div>

                <div class="field" style="width: 200px; margin-bottom: 0;">
                    <label for="rb_division">Division</label>
                    <select id="rb_division" name="division">
                        <option value="">All</option>
                        @foreach ($divisions as $division)
                            <option value="{{ $division->id }}">{{ $division->name }} ({{ $division->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="field" style="width: 200px; margin-bottom: 0;">
                    <label for="rb_department">Department</label>
                    <select id="rb_department" name="department">
                        <option value="">All</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Generate PDF</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">PDF export</span>
                <h2>Member Data Record (MDR)</h2>
            </div>
        </div>
        <p class="hint">A single member's full profile, benefit balance, and history as a printable record. Open a member's page and use the "Print MDR" button there.</p>
        <a href="{{ route('members.index') }}" class="btn btn-ghost">Go to Members</a>
    </div>
@endsection
