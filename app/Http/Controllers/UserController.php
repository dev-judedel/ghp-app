<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Department;
use App\Models\Division;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'users' => User::orderBy('name')->get(),
            'departments' => Department::with('division')->orderBy('name')->get(),
            'divisions' => Division::orderBy('member_type')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::create([
            'user_code' => User::generateUniqueUserCode(),
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
            'role' => $request->validated('role'),
            'is_active' => true,
        ]);

        return redirect()->route('users.index')->with('status', "User created — code {$user->user_code}.");
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        if ($user->id === auth()->id() && $request->validated('role') !== 'admin') {
            return redirect()->route('users.index')
                ->with('status', "Can't remove your own admin role — ask another admin to do it.");
        }

        if ($this->wouldRemoveLastAdmin($user, $request->validated('role'))) {
            return redirect()->route('users.index')
                ->with('status', "Can't change {$user->name} — they're the last remaining admin.");
        }

        $user->update([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'role' => $request->validated('role'),
            ...$request->validated('password') ? ['password' => Hash::make($request->validated('password'))] : [],
        ]);

        return redirect()->route('users.index')->with('status', "User {$user->name} updated.");
    }

    /**
     * Toggles Active <-> Inactive. Kept as its own route/confirmation flow
     * (rather than folded into update()) so it's a deliberate, single-purpose
     * action — matches how amount adjustments are separated from regular
     * member edits elsewhere in this app.
     */
    public function updateStatus(User $user): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        if ($user->id === auth()->id()) {
            return redirect()->route('users.index')
                ->with('status', "Can't deactivate your own account while logged in.");
        }

        $activating = ! $user->is_active;

        if (! $activating && $this->wouldRemoveLastActiveAdmin($user)) {
            return redirect()->route('users.index')
                ->with('status', "Can't deactivate {$user->name} — they're the last active admin.");
        }

        $user->update(['is_active' => $activating]);

        return redirect()->route('users.index')
            ->with('status', 'User '.$user->name.' '.($activating ? 'reactivated.' : 'deactivated.'));
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        if ($user->id === auth()->id()) {
            return redirect()->route('users.index')->with('status', "Can't delete your own account while logged in.");
        }

        if ($this->wouldRemoveLastAdmin($user, 'user')) {
            return redirect()->route('users.index')
                ->with('status', "Can't delete {$user->name} — they're the last remaining admin.");
        }

        $user->delete();

        return redirect()->route('users.index')->with('status', 'User deleted.');
    }

    /**
     * True if changing $user's role away from admin (to $newRole) would leave
     * zero admins in the system, e.g. deleting/demoting the only admin left.
     */
    private function wouldRemoveLastAdmin(User $user, string $newRole): bool
    {
        if ($user->role !== 'admin' || $newRole === 'admin') {
            return false;
        }

        return User::where('role', 'admin')->where('id', '!=', $user->id)->doesntExist();
    }

    /**
     * True if deactivating $user would leave zero *active* admins. Similar
     * to wouldRemoveLastAdmin() above, but checks is_active rather than
     * role — a deactivated admin can't log back in to undo a lockout, so
     * this has to be checked separately from role changes.
     */
    private function wouldRemoveLastActiveAdmin(User $user): bool
    {
        if ($user->role !== 'admin') {
            return false;
        }

        return User::where('role', 'admin')->where('is_active', true)->where('id', '!=', $user->id)->doesntExist();
    }
}
