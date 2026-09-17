<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Catches a session that's still logged in after an admin deactivates
     * that account. EnsureUserIsAdmin only gates admin-only routes, but a
     * deactivated user must be blocked from *every* authenticated route
     * immediately — not just on their next login attempt — and direct URLs
     * must not bypass this, so this runs on the whole 'auth' route group
     * (see routes/web.php).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // withErrors (not session('status')) so this reuses the same
            // $errors->first() block the login page already renders for
            // "Those credentials don't match our records." — no view
            // changes needed to display it.
            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been deactivated. Please contact the system administrator.']);
        }

        return $next($request);
    }
}
