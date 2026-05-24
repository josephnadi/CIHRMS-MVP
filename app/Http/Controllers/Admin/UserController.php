<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\SequenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private readonly SequenceService $sequences) {}

    public function index(Request $request): Response
    {
        $users = User::query()
            ->with('employee:id,user_id,employee_no,position,department_id')
            ->select(['id', 'name', 'email', 'staff_id', 'role', 'two_factor_required', 'two_factor_confirmed_at', 'created_at'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'activeModule' => 'admin-users',
            'users'        => $users,
            'roles'        => collect(UserRole::cases())->map(fn ($r) => [
                'value' => $r->value,
                'label' => $r->label(),
            ])->all(),
            'departments'  => Department::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Privileged roles always require 2FA — operator cannot un-tick on save.
        $privileged = in_array($data['role'], ['super_admin', 'ceo', 'hr_admin', 'finance_officer'], true);

        $user = DB::transaction(function () use ($data, $privileged) {
            $user = User::create([
                'name'                  => $data['name'],
                'email'                 => $data['email'],
                'staff_id'              => $data['staff_id'],
                'role'                  => $data['role'],
                'password'              => Hash::make($data['password']),
                'permissions'           => User::ROLE_PERMISSIONS[$data['role']] ?? [],
                'two_factor_required'   => $privileged || (bool) ($data['two_factor_required'] ?? false),
                'password_must_change'  => true,
            ]);

            // Backfill DB-backed role pivot so the new account picks up the
            // canonical permission set without waiting for a seeder re-run.
            $role = Role::where('slug', $data['role'])->first();
            if ($role) {
                $user->roles()->syncWithoutDetaching([
                    $role->id => ['department_id' => null],
                ]);
            }

            // Provision an Employee row in the same transaction so HR-feature
            // pages ($user->employee reads) don't 404 on the new account.
            Employee::create([
                'user_id'       => $user->id,
                'department_id' => $data['department_id'],
                'employee_no'   => $data['employee_no'] ?? $this->nextEmployeeNo(),
                'position'      => $data['position'],
                'hire_date'     => $data['hire_date'],
                'phone'         => $data['phone'] ?? null,
                'status'        => EmployeeStatus::Active->value,
            ]);

            return $user;
        });

        return back()->with('success', "User \"{$user->name}\" ({$user->staff_id}) created with employee profile.");
    }

    /**
     * Generate a sequential employee_no like CIHRM-0042. SequenceService
     * row-locks the counter so concurrent inserts cannot collide on the
     * unique employee_no constraint. Employee numbers are lifetime-unique
     * (not yearly), so the key is unscoped by year.
     */
    private function nextEmployeeNo(): string
    {
        return sprintf('CIHRM-%04d', $this->sequences->next('employee_no'));
    }
}
