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
            'division_id' => $this->resolveDivisionId($request->validated('division_id'), $request->validated('division')),
        ]);

        return redirect()->route('users.index')->with('status', "Department \"{$department->name}\" added.");
    }

    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        $department->update([
            'name' => $request->validated('name'),
            'division_id' => $this->resolveDivisionId($request->validated('division_id'), $request->validated('division')),
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
     * The Add/Edit Department form now picks a division by its ID (a select),
     * because division names are not unique (task.md §2.36) and a name alone
     * can't say which of two same-named divisions is meant. A submitted
     * division_id (validated to exist) is used as-is; a blank one means "no
     * division".
     *
     * The older free-text division NAME is still honored when no division_id
     * is sent at all (older callers, tests): it is matched case-insensitively
     * and, if several divisions share that name, the LOWEST id is used so the
     * result is deterministic; a name that matches nothing creates a new
     * Employee Division (Divisions can be managed on the Users page now, so
     * that is only a convenience).
     */
    private function resolveDivisionId(mixed $divisionId, ?string $name): ?int
    {
        if ($divisionId !== null && $divisionId !== '') {
            return (int) $divisionId;
        }

        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $division = Division::whereRaw('LOWER(name) = ?', [strtolower($name)])->orderBy('id')->first();

        if ($division) {
            return $division->id;
        }

        return Division::create([
            'name' => $name,
            'member_type' => Member::MEMBER_TYPE_EMPLOYEE,
        ])->id;
    }
}
