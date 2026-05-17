<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => fake()->randomElement(['employee', 'manager', 'hr_admin', 'finance_officer']),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * After-create hook: if the RBAC schema is in place AND a Role row exists
     * for the user's primary role slug, attach it via the user_roles pivot.
     * This mirrors RolePermissionSeeder step 3 so freshly-factoried test users
     * get the DB-backed permission set automatically — otherwise the Policy
     * layer 403s every test that depends on a new permission slug introduced
     * after the legacy User::ROLE_PERMISSIONS map.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if (! $user->role) return;
            if (! Schema::hasTable('roles') || ! Schema::hasTable('user_roles')) return;

            $slug = is_object($user->role) ? $user->role->value : $user->role;
            $role = Role::query()->where('slug', $slug)->first();
            if ($role) {
                $user->roles()->syncWithoutDetaching([$role->id => ['department_id' => null]]);
            }
        });
    }
}
