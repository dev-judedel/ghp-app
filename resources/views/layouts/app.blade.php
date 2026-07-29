<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') — GHP</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <div class="shell">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <strong>GHP</strong>
                <span>Benefit Fund</span>
            </div>
            <nav class="sidebar-nav">
                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
                <a href="{{ route('members.index') }}" class="{{ request()->routeIs('members.*') ? 'active' : '' }}">Members</a>
                <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">Reports</a>
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">Users</a>
                    <a href="{{ route('activity.index') }}" class="{{ request()->routeIs('activity.*') ? 'active' : '' }}">Activity Log</a>
                @endif
            </nav>
            <div class="sidebar-foot">
                Group Hospitalization Plan<br>internal system
            </div>
        </aside>

        <div class="main">
            <header class="topbar">
                <h1>@yield('title', 'Dashboard')</h1>
                <div class="topbar-user">
                    <span>{{ auth()->user()->name }} &middot; {{ auth()->user()->role === 'admin' ? 'Admin' : 'Staff' }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit">Log out</button>
                    </form>
                </div>
            </header>

            <main class="content">
                @if (session('status'))
                    <div class="status-banner">{{ session('status') }}</div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
