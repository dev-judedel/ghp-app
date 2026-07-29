@extends('layouts.app')

@section('title', 'Activity Log')

@section('content')
    <div class="card">
        <form method="GET" action="{{ route('activity.index') }}" style="display: flex; gap: 12px; align-items: flex-end;">
            <div class="field" style="width: 220px; margin-bottom: 0;">
                <label for="log_name">Category</label>
                <select id="log_name" name="log_name" onchange="this.form.submit()">
                    <option value="">All</option>
                    @foreach ($logNames as $name)
                        <option value="{{ $name }}" @selected($logName === $name)>{{ ucfirst($name) }}</option>
                    @endforeach
                </select>
            </div>
            @if ($logName)
                <a href="{{ route('activity.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </form>
    </div>

    <div class="card">
        @if ($activities->isEmpty())
            <div class="empty-state">
                <h3>No activity recorded yet</h3>
                <p>Actions across the system will show up here as they happen.</p>
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th style="width: 150px;">When</th>
                        <th style="width: 130px;">By</th>
                        <th style="width: 100px;">Category</th>
                        <th>What changed</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($activities as $activity)
                        <tr>
                            <td>{{ $activity->created_at->format('M d, Y g:i A') }}</td>
                            <td>{{ $activity->causer->name ?? 'System' }}</td>
                            <td><span class="badge badge-employee">{{ $activity->log_name }}</span></td>
                            <td>
                                <div>{{ $activity->description }}</div>

                                @if ($activity->subject)
                                    <div class="hint" style="margin-top: 2px;">
                                        @if ($activity->subject instanceof \App\Models\Member)
                                            <a href="{{ route('members.show', $activity->subject) }}">{{ $activity->subject->code }} &middot; {{ $activity->subject->full_name }}</a>
                                        @elseif ($activity->subject instanceof \App\Models\Dependent && $activity->subject->member)
                                            Dependent of <a href="{{ route('members.show', $activity->subject->member) }}">{{ $activity->subject->member->code }} &middot; {{ $activity->subject->member->full_name }}</a>
                                        @elseif ($activity->subject instanceof \App\Models\Reimbursement && $activity->subject->member)
                                            Reimbursement for <a href="{{ route('members.show', $activity->subject->member) }}">{{ $activity->subject->member->code }} &middot; {{ $activity->subject->member->full_name }}</a>
                                        @elseif ($activity->subject instanceof \App\Models\User)
                                            User account: {{ $activity->subject->email }}
                                        @endif
                                    </div>
                                @endif

                                @if ($activity->properties->has('attributes'))
                                    <ul style="margin: 6px 0 0; padding-left: 16px; font-size: 12px; color: var(--ink-muted);">
                                        @foreach ($activity->properties->get('attributes') as $field => $newValue)
                                            @php
                                                $oldValue = $activity->properties->get('old')[$field] ?? null;
                                            @endphp
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

            <div class="pagination">
                {{ $activities->links() }}
            </div>
        @endif
    </div>
@endsection
