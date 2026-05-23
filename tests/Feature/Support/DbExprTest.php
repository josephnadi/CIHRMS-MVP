<?php

declare(strict_types=1);

use App\Support\DbExpr;

/**
 * Pins the SQL fragments emitted by DbExpr for the SQLite test driver. The
 * pgsql / mysql branches are unreachable from the test runner (which uses
 * SQLite), but their literal correctness is enforced by the CI matrix
 * running the full Pest suite on a real Postgres container — any wrong
 * pgsql fragment surfaces as a query-execution error in that matrix
 * (specifically, the "emitted fragments execute against the live
 * connection" test below).
 *
 * The point of this test is to lock the SQLite output so refactors of
 * DbExpr can't silently change the column the rest of the codebase queries.
 */

/** SQLite-only literal pins — skipped under the pgsql/mysql CI matrix. */
$sqliteOnly = function () {
    if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'sqlite') {
        test()->markTestSkipped('Driver-specific literal pin: only run under SQLite.');
    }
};

it('yearMonth() emits strftime on SQLite', function () use ($sqliteOnly) {
    $sqliteOnly();
    expect(DbExpr::yearMonth('created_at'))->toBe("strftime('%Y-%m', created_at)");
});

it('isoDate() emits strftime YYYY-MM-DD on SQLite', function () use ($sqliteOnly) {
    $sqliteOnly();
    expect(DbExpr::isoDate('paid_at'))->toBe("strftime('%Y-%m-%d', paid_at)");
});

it('month() casts strftime month to integer on SQLite', function () use ($sqliteOnly) {
    $sqliteOnly();
    expect(DbExpr::month('start_date'))->toBe("CAST(strftime('%m', start_date) AS INTEGER)");
});

it('week() casts strftime week to integer on SQLite', function () use ($sqliteOnly) {
    $sqliteOnly();
    expect(DbExpr::week('created_at'))->toBe("CAST(strftime('%W', created_at) AS INTEGER)");
});

it('year() casts strftime year to integer on SQLite', function () use ($sqliteOnly) {
    $sqliteOnly();
    expect(DbExpr::year('hire_date'))->toBe("CAST(strftime('%Y', hire_date) AS INTEGER)");
});

it('hoursBetween() uses julianday on SQLite', function () use ($sqliteOnly) {
    $sqliteOnly();
    expect(DbExpr::hoursBetween('opened_at', 'closed_at'))
        ->toBe('((julianday(closed_at) - julianday(opened_at)) * 24)');
});

it('emitted fragments execute against the live connection without syntax error', function () {
    // Reach into the schema via a one-row temp query so a busted helper is
    // caught as a SQL exception. We don't care about the value — only that
    // the database accepts the expression.
    // TIMESTAMP is portable: Postgres has it natively; SQLite accepts the type name
    // and treats it as TEXT, which works fine with strftime().
    \Illuminate\Support\Facades\DB::statement('CREATE TEMP TABLE dbexpr_probe (ts TIMESTAMP)');
    \Illuminate\Support\Facades\DB::table('dbexpr_probe')->insert(['ts' => '2026-05-21 10:00:00']);

    foreach ([
        DbExpr::yearMonth('ts'),
        DbExpr::isoDate('ts'),
        DbExpr::month('ts'),
        DbExpr::week('ts'),
        DbExpr::year('ts'),
        DbExpr::hoursBetween('ts', 'ts'),
    ] as $expr) {
        $row = \Illuminate\Support\Facades\DB::table('dbexpr_probe')->selectRaw("{$expr} AS v")->first();
        expect($row)->not->toBeNull();
    }

    \Illuminate\Support\Facades\DB::statement('DROP TABLE dbexpr_probe');
});
