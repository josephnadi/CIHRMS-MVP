<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
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
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Privileged roles always require 2FA — operator cannot un-tick on save.
        $privileged = in_array($data['role'], ['super_admin', 'ceo', 'hr_admin', 'finance_officer'], true);

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

        return back()->with('success', "User \"{$user->name}\" ({$user->staff_id}) created.");
    }
}
