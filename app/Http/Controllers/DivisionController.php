<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDivisionRequest;
use App\Http\Requests\UpdateDivisionRequest;
use App\Models\Division;
use Illuminate\Http\RedirectResponse;

/**
 * Division management (Users page). Division names are NOT unique
 * (task.md §2.36): every operation here identifies a division by its own id
 * (route model binding), never by name, so a duplicate name can't make an edit
 * or delete touch a different record.
 */
class DivisionController extends Controller
{
    public function store(StoreDivisionRequest $request): RedirectResponse
    {
        $division = Division::create([
            'name' => $request->validated('name'),
            'member_type' => (int) $request->validated('member_type'),
        ]);

        return redirect()->route('users.index')->with('status', "Division \"{$division->name}\" added (ID {$division->id}).");
    }

    public function update(UpdateDivisionRequest $request, Division $division): RedirectResponse
    {
        $division->update([
            'name' => $request->validated('name'),
            'member_type' => (int) $request->validated('member_type'),
        ]);

        return redirect()->route('users.index')->with('status', "Division \"{$division->name}\" (ID {$division->id}) updated.");
    }

    /**
     * Safe to delete even if departments or members point at it:
     * departments.division_id and members.division_id are nullOnDelete
     * foreign keys, so they are unassigned, not deleted. The confirm dialog in
     * the view warns the admin first. Only THIS record is removed — other
     * divisions with the same name are left alone.
     */
    public function destroy(Division $division): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $name = $division->name;
        $id = $division->id;
        $division->delete();

        return redirect()->route('users.index')->with('status', "Division \"{$name}\" (ID {$id}) deleted.");
    }
}
