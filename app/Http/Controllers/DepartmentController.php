<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Models\Department;
use App\Models\Division;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;

class DepartmentController extends Controller
{
    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        $department = Department::create([
            'name' => $request->validated('name'),
            'division_id' => $this->resolveDivisionId($request->validated('division')),
        ]);

        return redirect()->route('users.index')->with('status', "Department \"{$department->name}\" added.");
    }

    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        $department->update([
            'name' => $request->validated('name'),
            'division_id' => $this->resolveDivisionId($request->validated('division')),
        ]);

        return redirect()->route('users.index')->with('status', "Department \"{$department->name}\" updated.");
    }

    /**
     * Safe to delete even if members are assigned to it: members.department_id
     * is a nullOnDelete foreign key (see create_members_table), so those
     * members are unassigned, not deleted. The confirm dialog in the view
     * warns the admin about this before they click through.
     */
    public function destroy(Department $department): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $name = $department->name;
        $department->delete();

        return redirect()->route('users.index')->with('status', "Department \"{$name}\" deleted.");
    }

    /**
     * The Add/Edit Department form takes a division NAME (plain text, with
     * a datalist of existing names for convenience), not a division_id
     * select — there's no dedicated Division management screen yet.
     *
     * An existing division is matched case-insensitively and reused. A name
     * that doesn't match anything creates a new Division rather than
     * rejecting the department form — but since this text field can't
     * specify member_type (Divisions require one: Employee/Agent), a newly
     * created division always defaults to Employee (0). If you need an
     * Agent-only division, it needs to exist beforehand (e.g. via a
     * seeder/tinker) until a real Division management UI exists.
     */
    private function resolveDivisionId(?string $name): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $division = Division::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

        if ($division) {
            return $division->id;
        }

        return Division::create([
            'name' => $name,
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
        ])->id;
    }
}
