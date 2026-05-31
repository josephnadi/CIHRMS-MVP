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
use Illuminate\Http\JsonResponse;
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
                'staff_id'              => $this->resolveStaffId($data['staff_id'] ?? null, $data['department_id'] ?? null),
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
                'employee_no'   => $this->resolveEmployeeNo($data['employee_no'] ?? null),
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
     * Live preview for the New User form. Returns the staff_id and
     * employee_no that would be allocated right now, given the selected
     * department. Non-mutating — uses SequenceService::peek so opening
     * the form doesn't burn sequence numbers.
     *
     * GET /admin/users/preview-ids?department_id=5
     */
    public function previewIds(Request $request): JsonResponse
    {
        // Same perm gate as the form itself.
        abort_unless($request->user()->hasPermission('users.manage'), 403);

        $deptId = $request->integer('department_id') ?: null;

        return response()->json([
            'staff_id'    => $this->previewStaffId($deptId),
            'employee_no' => sprintf('CIHRM-%04d', $this->sequences->peek('employee_no')),
        ]);
    }

    /**
     * Return a usable employee_no. If the operator typed one and it's free,
     * honour it. Otherwise (or on collision with the supplied value) burn
     * sequence numbers until we land on one that isn't already taken — the
     * row-locked counter handles concurrent inserts, the existence check
     * defensively covers seed/import values that pre-populated slots.
     * Operators never see "already exists" for a stale preview.
     */
    private function resolveEmployeeNo(?string $supplied): string
    {
        if ($supplied !== null && $supplied !== '' && ! Employee::where('employee_no', $supplied)->exists()) {
            return $supplied;
        }

        do {
            $candidate = sprintf('CIHRM-%04d', $this->sequences->next('employee_no'));
        } while (Employee::where('employee_no', $candidate)->exists());

        return $candidate;
    }

    /**
     * Same silent-recovery contract as resolveEmployeeNo: honour an operator
     * value when it's free, otherwise bump SequenceService::next() until we
     * find an unused slot. Department-scoped sequence so HR-0001 / FIN-0001
     * coexist.
     */
    private function resolveStaffId(?string $supplied, ?int $departmentId): string
    {
        if ($supplied !== null && $supplied !== '' && ! User::where('staff_id', $supplied)->exists()) {
            return $supplied;
        }

        [$key, $prefix] = $this->staffIdSequenceFor($departmentId);
        do {
            $candidate = sprintf('%s-%04d', $prefix, $this->sequences->next($key));
        } while (User::where('staff_id', $candidate)->exists());

        return $candidate;
    }

    /**
     * Format the next staff_id given an optional department.
     *  - With department: `GH-{DEPT_CODE}-{####}` (e.g. GH-HR-0007)
     *  - Without:        `GH-{####}`
     * The sequence key is scoped by department code so each dept gets its
     * own counter — HR-0001 and FIN-0001 coexist.
     */
    public function nextStaffId(?int $departmentId): string
    {
        [$key, $prefix] = $this->staffIdSequenceFor($departmentId);
        return sprintf('%s-%04d', $prefix, $this->sequences->next($key));
    }

    private function previewStaffId(?int $departmentId): string
    {
        [$key, $prefix] = $this->staffIdSequenceFor($departmentId);
        return sprintf('%s-%04d', $prefix, $this->sequences->peek($key));
    }

    /** @return array{0:string, 1:string} [sequence_key, staff_id_prefix] */
    private function staffIdSequenceFor(?int $departmentId): array
    {
        if ($departmentId) {
            $code = strtoupper((string) Department::whereKey($departmentId)->value('code'));
            if ($code !== '') {
                return ["staff_id:GH:{$code}", "GH-{$code}"];
            }
        }
        return ['staff_id:GH', 'GH'];
    }
}
