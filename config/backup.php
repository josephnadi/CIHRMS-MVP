<?php

declare(strict_types=1);

/**
 * Backup configuration skeleton (spatie/laravel-backup).
 *
 * Activates when spatie/laravel-backup is installed:
 *     composer require spatie/laravel-backup
 *     php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"
 *
 * Default daily 02:00 schedule lives in routes/console.php once the
 * package is installed. Until then this file is a placeholder so deploy
 * scripts don't choke on a missing config path.
 */

return [
    'backup' => [
        'name' => env('APP_NAME', 'cihrms'),

        'source' => [
            'files' => [
                'include' => [
                    storage_path('app'),
                ],
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                ],
            ],
            'databases' => [
                env('DB_CONNECTION', 'sqlite'),
            ],
        ],

        'destination' => [
            'disks' => [
                env('BACKUP_FILESYSTEM_DISK', 'local'),
            ],
        ],

        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
    ],

    /*
     * Retention policy: keep daily backups for 7 days, weekly for 4 weeks,
     * monthly for 6 months. Tune per organisation's RPO/RTO.
     */
    'cleanup' => [
        'default_strategy' => [
            'keep_all_backups_for_days'                => 7,
            'keep_daily_backups_for_days'              => 16,
            'keep_weekly_backups_for_weeks'            => 4,
            'keep_monthly_backups_for_months'          => 6,
            'keep_yearly_backups_for_years'            => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],
    ],

    'notifications' => [
        'mail' => [
            'to' => env('BACKUP_NOTIFY_EMAIL', env('MAIL_FROM_ADDRESS')),
        ],
    ],
];
