<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Self-service profile page for the currently logged-in user (any role).
     * Mirrors the "single record + activity feed" layout used on the
     * member show page, scoped to the user's own account.
     */
    public function edit(): View
    {
        $user = auth()->user();

        return view('profile.edit', [
            'user' => $user,
            'activityFeed' => $user->activities()->with('causer')->latest()->limit(15)->get(),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->fill([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
        ]);

        if ($request->boolean('remove_photo') && $user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->profile_photo_path = null;
        }

        if ($request->hasFile('photo')) {
            if ($user->profile_photo_path) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }

            $user->profile_photo_path = $request->file('photo')->store('profile-photos', 'public');
        }

        $user->save();

        return redirect()->route('profile.edit')->with('status', 'Profile updated.');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        // Matches the existing auth-event logging convention in
        // AuthenticatedSessionController (login/logout) — password changes
        // are security-relevant but excluded from User::logOnly(), so this
        // is the only record of when it happened.
        activity('auth')
            ->causedBy($request->user())
            ->withProperties(['ip' => $request->ip()])
            ->log('Changed password');

        return redirect()->route('profile.edit')->with('status', 'Password updated.');
    }
}
