<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;

class DepartmentController extends Controller
{
    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        $department = Department::create($request->validated());

        return redirect()->route('users.index')->with('status', "Department \"{$department->name}\" added.");
    }

    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        $department->update($request->validated());

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
}
