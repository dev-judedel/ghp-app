<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function create(): \Illuminate\Contracts\View\View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            activity('auth')
                ->withProperties(['email' => $credentials['email'], 'ip' => $request->ip()])
                ->log('Failed login attempt');

            throw ValidationException::withMessages([
                'email' => 'Those credentials don\'t match our records.',
            ]);
        }

        // Checked after credentials match (not before) so a deactivated
        // account doesn't get a different error than a wrong password would
        // — that would let someone confirm an email exists just by trying it.
        if (! Auth::user()->is_active) {
            Auth::logout();

            activity('auth')
                ->withProperties(['email' => $credentials['email'], 'ip' => $request->ip()])
                ->log('Blocked login attempt on deactivated account');

            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Please contact the system administrator.',
            ]);
        }

        $request->session()->regenerate();

        activity('auth')
            ->causedBy(Auth::user())
            ->withProperties(['ip' => $request->ip()])
            ->log('Logged in');

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        activity('auth')
            ->causedBy(Auth::user())
            ->withProperties(['ip' => $request->ip()])
            ->log('Logged out');

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
