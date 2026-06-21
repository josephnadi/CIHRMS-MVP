<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\QueryException;

// The business-identifier unique indexes on soft-deleting tables are now partial
// (WHERE deleted_at IS NULL): a freed key can be reused, but it's still unique
// among live rows.

it('lets a soft-deleted employee_no be reused by a new employee', function () {
    $first = Employee::factory()->create(['employee_no' => 'EMP-REUSE-1']);
    $first->delete(); // soft delete

    // Re-create with the same number — must succeed now the tombstone is excluded.
    $second = Employee::factory()->create(['employee_no' => 'EMP-REUSE-1']);

    expect($second->exists)->toBeTrue()
        ->and(Employee::where('employee_no', 'EMP-REUSE-1')->count())->toBe(1);     // only the live one
    expect(Employee::withTrashed()->where('employee_no', 'EMP-REUSE-1')->count())->toBe(2); // both rows exist
});

it('still blocks two live employees sharing an employee_no', function () {
    Employee::factory()->create(['employee_no' => 'EMP-DUP-1']);

    expect(fn () => Employee::factory()->create(['employee_no' => 'EMP-DUP-1']))
        ->toThrow(QueryException::class);
});

it('applies the same partial-unique rule to department code', function () {
    $d = Department::factory()->create(['code' => 'FIN']);
    $d->delete();

    $new = Department::factory()->create(['code' => 'FIN']);
    expect($new->exists)->toBeTrue();
});
