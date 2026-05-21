<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Oracle IPPD export (Phase 2 government-interop)
    |--------------------------------------------------------------------------
    | The IPPD2/IPPD3 upload format produced by App\Services\Payroll\Ippd\IppdExporter
    | requires the institutional MDA code printed in the file header. The
    | output disk should be writable but private — production should point at
    | an S3-style disk with object-lock for non-repudiation of submitted runs.
    */
    'ippd' => [
        'mda_code'    => env('IPPD_MDA_CODE', 'CIHRMS'),
        'output_disk' => env('IPPD_OUTPUT_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | GIFMIS journal export (Phase 2 government-interop)
    |--------------------------------------------------------------------------
    | Maps CIHRMS payroll buckets to the MDA's CAGD chart-of-accounts. Each
    | row produces one journal line per closed payroll run. Defaults are
    | placeholders — override per-MDA via env so a wrong GL code never
    | silently posts to a sandbox account.
    */
    'gifmis' => [
        'cost_centre' => env('GIFMIS_COST_CENTRE', '0000-00-00'),
        'output_disk' => env('GIFMIS_OUTPUT_DISK', 'local'),
        // Auto-mint a journal when a payroll run is marked paid. Default off
        // — operators typically want to download + review the JV before
        // pushing to GIFMIS on the first few production runs.
        'auto_mint_on_paid' => env('GIFMIS_AUTO_MINT', false),
        'gl_codes' => [
            // Debits — expense accounts (left side of the JV)
            'dr_salary'           => env('GIFMIS_GL_DR_SALARY',     '21010101'),
            'dr_ssnit_employer'   => env('GIFMIS_GL_DR_SSNIT_EMP',  '21111001'),
            'dr_tier2_employer'   => env('GIFMIS_GL_DR_TIER2_EMP',  '21112001'),
            // Credits — payable accounts (right side of the JV)
            'cr_net_payable'      => env('GIFMIS_GL_CR_NET',        '24101001'),
            'cr_paye'             => env('GIFMIS_GL_CR_PAYE',       '24102001'),
            'cr_ssnit_employee'   => env('GIFMIS_GL_CR_SSNIT_EE',   '24111001'),
            'cr_ssnit_employer'   => env('GIFMIS_GL_CR_SSNIT_EMP',  '24111002'),
            'cr_nhia'             => env('GIFMIS_GL_CR_NHIA',       '24112001'),
            'cr_tier2'            => env('GIFMIS_GL_CR_TIER2',      '24113001'),
            'cr_tier3'            => env('GIFMIS_GL_CR_TIER3',      '24113002'),
            'cr_voluntary'        => env('GIFMIS_GL_CR_VOLUNTARY',  '24199001'),
        ],
    ],
];
