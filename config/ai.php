<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI assistant
    |--------------------------------------------------------------------------
    |
    | Wires the in-app AI helper (employee summary, etc.) to an LLM provider.
    | Off by default — when disabled, AiAssistantController returns a
    | deterministic, redacted template so the UI never breaks just because
    | the tenant hasn't configured an API key yet.
    */

    'enabled' => env('AI_ENABLED', false),

    'driver' => env('AI_DRIVER', 'anthropic'),

    'providers' => [
        'anthropic' => [
            // Per-tenant key. Pull from the host MDA's secrets manager in
            // production rather than baking into .env on shared infra.
            'api_key'    => env('ANTHROPIC_API_KEY'),

            // Haiku 4.5 is the right default for a cheap+fast summary
            // endpoint. Operators wanting higher quality (e.g. policy
            // drafting) can override per-call once we add more endpoints.
            'model'      => env('ANTHROPIC_MODEL', 'claude-haiku-4-5'),
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 400),
            'timeout'    => (int) env('ANTHROPIC_TIMEOUT', 20),
        ],

        // Used in tests + when AI_ENABLED=false. Always available; deterministic.
        'fake' => [
            'model' => 'fake-haiku-1',
        ],
    ],

    /*
    | Hard list of Employee fields we strip before sending anything to a
    | third-party LLM. Anything not in this list is allowed through.
    | Tightening is cheaper than loosening — start strict.
    */
    'pii_blocklist' => [
        'national_id',
        'ssnit_number',
        'tin_number',
        'bank_name',
        'bank_account',
        'bank_sort_code',
        'salary',
        'phone',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'address',
        'date_of_birth',
        'tier2_trustee_id',
        'external_crm_id',
    ],
];
