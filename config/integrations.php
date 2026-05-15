<?php

/*
|--------------------------------------------------------------------------
| External Integrations
|--------------------------------------------------------------------------
|
| Each capability points at a *provider* key. Each provider key has a
| matching entry under "drivers" containing its OAuth + API config.
| Wave 9 ships the foundation; Waves 10–12 add the concrete driver classes.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Capability → Provider routing
    |--------------------------------------------------------------------------
    |
    | Swap drivers per capability without touching domain code.
    | e.g. INT_FILES_DRIVER=google_drive falls back to Google Drive instead
    | of OneDrive everywhere the FileStorageProvider contract is used.
    */

    'capabilities' => [
        'crm'         => env('INT_CRM_DRIVER', 'zoho_crm'),
        'files'       => env('INT_FILES_DRIVER', 'ms_graph'),
        'spreadsheet' => env('INT_SHEETS_DRIVER', 'ms_graph'),
        'messaging'   => env('INT_MSG_DRIVER', 'whatsapp_cloud'),
        'calendar'    => env('INT_CAL_DRIVER', 'ms_graph'),
        'esign'       => env('INT_ESIGN_DRIVER', 'zoho_sign'),
        'identity'    => env('INT_IDP_DRIVER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Driver registry
    |--------------------------------------------------------------------------
    |
    | Concrete driver classes are added in Waves 10-12. Until then the
    | "class" key may be null — the IntegrationManager will simply report
    | the capability as unavailable.
    */

    'drivers' => [

        'zoho_crm' => [
            'class'         => \App\Integrations\Drivers\Zoho\ZohoCrmDriver::class,
            'display_name'  => 'Zoho CRM',
            'logo'          => '/images/integrations/zoho.svg',
            'client_id'     => env('ZOHO_CLIENT_ID'),
            'client_secret' => env('ZOHO_CLIENT_SECRET'),
            'region'        => env('ZOHO_REGION', 'com'),
            'authorize_url' => 'https://accounts.zoho.'.env('ZOHO_REGION', 'com').'/oauth/v2/auth',
            'token_url'     => 'https://accounts.zoho.'.env('ZOHO_REGION', 'com').'/oauth/v2/token',
            'api_base'      => 'https://www.zohoapis.'.env('ZOHO_REGION', 'com').'/crm/v8',
            'scopes'        => ['ZohoCRM.modules.ALL', 'ZohoCRM.users.READ'],
            'extra_authorize_params' => ['access_type' => 'offline'],
            // Override Zoho field names without code changes — keys are CIHRMS extra-keys → Zoho fields.
            'contact_field_map' => [
                'employee_no' => 'Employee_Number',
                'department'  => 'Department',
            ],
        ],

        'zoho_sign' => [
            'class'         => \App\Integrations\Drivers\Zoho\ZohoSignDriver::class,
            'display_name'  => 'Zoho Sign',
            'logo'          => '/images/integrations/zoho-sign.svg',
            'client_id'     => env('ZOHO_SIGN_CLIENT_ID'),
            'client_secret' => env('ZOHO_SIGN_CLIENT_SECRET'),
            'authorize_url' => 'https://accounts.zoho.'.env('ZOHO_REGION', 'com').'/oauth/v2/auth',
            'token_url'     => 'https://accounts.zoho.'.env('ZOHO_REGION', 'com').'/oauth/v2/token',
            'api_base'      => 'https://sign.zoho.'.env('ZOHO_REGION', 'com').'/api/v1',
            'scopes'        => ['ZohoSign.documents.ALL'],
        ],

        'ms_graph' => [
            // Multi-capability provider: one OAuth grant covers files + spreadsheet + calendar.
            'class'              => \App\Integrations\Drivers\Microsoft\MsGraphFilesDriver::class,
            'capability_classes' => [
                'files'       => \App\Integrations\Drivers\Microsoft\MsGraphFilesDriver::class,
                'spreadsheet' => \App\Integrations\Drivers\Microsoft\MsGraphExcelDriver::class,
                'calendar'    => \App\Integrations\Drivers\Microsoft\MsGraphCalendarDriver::class,
            ],
            'display_name'  => 'Microsoft 365 (OneDrive · Excel · Calendar · Teams)',
            'logo'          => '/images/integrations/microsoft.svg',
            'tenant_id'     => env('MS_TENANT_ID', 'common'),
            'client_id'     => env('MS_CLIENT_ID'),
            'client_secret' => env('MS_CLIENT_SECRET'),
            'authorize_url' => 'https://login.microsoftonline.com/'.env('MS_TENANT_ID', 'common').'/oauth2/v2.0/authorize',
            'token_url'     => 'https://login.microsoftonline.com/'.env('MS_TENANT_ID', 'common').'/oauth2/v2.0/token',
            'api_base'      => 'https://graph.microsoft.com/v1.0',
            'scopes'        => [
                'offline_access',
                'User.Read',
                'Files.ReadWrite.All',
                'Sites.ReadWrite.All',
                'Calendars.ReadWrite',
                'ChannelMessage.Send',
            ],
        ],

        'ms_teams' => [
            'class'         => \App\Integrations\Drivers\Microsoft\MsTeamsDriver::class,
            'display_name'  => 'Microsoft Teams (Webhook)',
            'logo'          => '/images/integrations/teams.svg',
            'webhook_url'   => env('TEAMS_HR_WEBHOOK'),
        ],

        'google' => [
            // Multi-capability provider: one OAuth grant covers files + spreadsheet + calendar.
            'class'              => \App\Integrations\Drivers\Google\GoogleDriveDriver::class,
            'capability_classes' => [
                'files'       => \App\Integrations\Drivers\Google\GoogleDriveDriver::class,
                'spreadsheet' => \App\Integrations\Drivers\Google\GoogleSheetsDriver::class,
                'calendar'    => \App\Integrations\Drivers\Google\GoogleCalendarDriver::class,
            ],
            'display_name'  => 'Google Workspace (Drive · Sheets · Calendar)',
            'logo'          => '/images/integrations/google.svg',
            'client_id'     => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url'     => 'https://oauth2.googleapis.com/token',
            'scopes'        => [
                'https://www.googleapis.com/auth/drive.file',
                'https://www.googleapis.com/auth/spreadsheets',
                'https://www.googleapis.com/auth/calendar',
                'openid', 'email', 'profile',
            ],
            'extra_authorize_params' => ['access_type' => 'offline', 'prompt' => 'consent'],
        ],

        'whatsapp_cloud' => [
            'class'               => \App\Integrations\Drivers\Meta\WhatsAppCloudDriver::class,
            'display_name'        => 'WhatsApp Business (Cloud API)',
            'logo'                => '/images/integrations/whatsapp.svg',
            'phone_number_id'     => env('WHATSAPP_PHONE_ID'),
            'business_account_id' => env('WHATSAPP_WABA_ID'),
            'access_token'        => env('WHATSAPP_TOKEN'),
            'verify_token'        => env('WHATSAPP_VERIFY_TOKEN'),
            'app_secret'          => env('WHATSAPP_APP_SECRET'),
            'api_base'            => 'https://graph.facebook.com/v21.0',
        ],

        'slack' => [
            'class'           => \App\Integrations\Drivers\Slack\SlackDriver::class,
            'display_name'    => 'Slack',
            'logo'            => '/images/integrations/slack.svg',
            'client_id'       => env('SLACK_CLIENT_ID'),
            'client_secret'   => env('SLACK_CLIENT_SECRET'),
            'bot_token'       => env('SLACK_BOT_TOKEN'),
            'signing_secret'  => env('SLACK_SIGNING_SECRET'),
            'default_channel' => env('SLACK_HR_CHANNEL', '#hr'),
            'authorize_url'   => 'https://slack.com/oauth/v2/authorize',
            'token_url'       => 'https://slack.com/api/oauth.v2.access',
            'scopes'          => ['chat:write', 'channels:read', 'commands', 'users:read'],
        ],

        'docusign' => [
            'class'         => \App\Integrations\Drivers\DocuSign\DocuSignDriver::class,
            'display_name'  => 'DocuSign',
            'logo'          => '/images/integrations/docusign.svg',
            'client_id'     => env('DOCUSIGN_CLIENT_ID'),
            'client_secret' => env('DOCUSIGN_CLIENT_SECRET'),
            'authorize_url' => 'https://account.docusign.com/oauth/auth',
            'token_url'     => 'https://account.docusign.com/oauth/token',
            'scopes'        => ['signature', 'extended'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Feature flags — gate new integration-driven flows safely.
    |--------------------------------------------------------------------------
    */

    'feature_flags' => [
        'auto_sync_crm_on_hire'     => env('FLAG_AUTO_SYNC_CRM', false),
        'whatsapp_payslip_notify'   => env('FLAG_WA_PAYSLIP', false),
        'mirror_documents_to_cloud' => env('FLAG_DOC_MIRROR', false),
        'slack_leave_approvals'     => env('FLAG_SLACK_LEAVE', false),
        'teams_ticket_announce'     => env('FLAG_TEAMS_TICKETS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook signature secrets / verification config
    |--------------------------------------------------------------------------
    */

    'webhooks' => [
        'whatsapp' => [
            'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
            'app_secret'   => env('WHATSAPP_APP_SECRET'),
        ],
        'zoho' => [
            'shared_secret' => env('ZOHO_WEBHOOK_SECRET'),
            'header'        => 'X-Zoho-Webhook-Token',
        ],
        'ms_graph' => [
            'client_state' => env('MS_GRAPH_CLIENT_STATE'),
        ],
        'google' => [
            'channel_token' => env('GOOGLE_CHANNEL_TOKEN'),
        ],
        'slack' => [
            'signing_secret' => env('SLACK_SIGNING_SECRET'),
        ],
    ],

];
