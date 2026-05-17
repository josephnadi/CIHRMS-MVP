<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Cross-database SQL fragment helpers.
 *
 * SQLite (used in tests) ships `strftime()`. PostgreSQL ships `to_char()`.
 * MySQL/MariaDB ship `DATE_FORMAT()`. Code that calls `selectRaw("strftime(...)")`
 * works in CI but 500s in production on PostgreSQL.
 *
 * These helpers return the right SQL fragment for the active connection so
 * report/analytics queries stay portable across the three drivers we support.
 *
 *   $expr = DbExpr::yearMonth('disbursed_at');
 *   LoanAccount::selectRaw("$expr as ym, SUM(disbursed_amount) as total")
 *       ->groupBy('ym')->get();
 */
class DbExpr
{
    /** Format a date/datetime column as 'YYYY-MM' (e.g. '2026-05'). */
    public static function yearMonth(string $column): string
    {
        return match (self::driver()) {
            'pgsql'  => "to_char({$column}, 'YYYY-MM')",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            'sqlite' => "strftime('%Y-%m', {$column})",
            default  => "strftime('%Y-%m', {$column})",
        };
    }

    /** Format a date/datetime column as 'YYYY-MM-DD'. */
    public static function isoDate(string $column): string
    {
        return match (self::driver()) {
            'pgsql'  => "to_char({$column}, 'YYYY-MM-DD')",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            'sqlite' => "strftime('%Y-%m-%d', {$column})",
            default  => "strftime('%Y-%m-%d', {$column})",
        };
    }

    /** Extract the month number (1–12) as an integer. */
    public static function month(string $column): string
    {
        return match (self::driver()) {
            'pgsql'  => "CAST(EXTRACT(MONTH FROM {$column}) AS INTEGER)",
            'mysql', 'mariadb' => "MONTH({$column})",
            'sqlite' => "CAST(strftime('%m', {$column}) AS INTEGER)",
            default  => "CAST(strftime('%m', {$column}) AS INTEGER)",
        };
    }

    /** Extract the ISO week-of-year number as an integer. */
    public static function week(string $column): string
    {
        return match (self::driver()) {
            'pgsql'  => "CAST(EXTRACT(WEEK FROM {$column}) AS INTEGER)",
            'mysql', 'mariadb' => "WEEK({$column}, 3)",     // mode 3 = ISO-8601
            'sqlite' => "CAST(strftime('%W', {$column}) AS INTEGER)",
            default  => "CAST(strftime('%W', {$column}) AS INTEGER)",
        };
    }

    /** Extract the year as an integer. */
    public static function year(string $column): string
    {
        return match (self::driver()) {
            'pgsql'  => "CAST(EXTRACT(YEAR FROM {$column}) AS INTEGER)",
            'mysql', 'mariadb' => "YEAR({$column})",
            'sqlite' => "CAST(strftime('%Y', {$column}) AS INTEGER)",
            default  => "CAST(strftime('%Y', {$column}) AS INTEGER)",
        };
    }

    /**
     * Fractional hours between two timestamp columns, useful for SLA / resolution-time
     * averages. Returns a SQL fragment safe to drop inside AVG()/SUM().
     */
    public static function hoursBetween(string $start, string $end): string
    {
        return match (self::driver()) {
            'pgsql'  => "(EXTRACT(EPOCH FROM ({$end} - {$start})) / 3600.0)",
            'mysql', 'mariadb' => "(TIMESTAMPDIFF(SECOND, {$start}, {$end}) / 3600.0)",
            'sqlite' => "((julianday({$end}) - julianday({$start})) * 24)",
            default  => "((julianday({$end}) - julianday({$start})) * 24)",
        };
    }

    private static function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
