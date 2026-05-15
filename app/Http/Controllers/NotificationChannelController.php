<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-user messaging preferences. Lives at /notifications/channels.
 *
 * Updates `users.notification_channels`, `whatsapp_phone`, `whatsapp_consent_at`
 * and `slack_user_id`. Consent timestamp is stamped only when the user explicitly
 * ticks the consent box AND provides a phone — that pair is what Meta requires
 * before we can target them with template messages.
 */
class NotificationChannelController extends Controller
{
    public function __construct(protected IntegrationManager $integrations) {}

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Notifications/Channels', [
            'preferences' => $user->notificationPreferences(),
            'profile' => [
                'whatsapp_phone'      => $user->whatsapp_phone,
                'whatsapp_consent_at' => $user->whatsapp_consent_at?->toIso8601String(),
                'slack_user_id'       => $user->slack_user_id,
            ],
            'available' => [
                'email'    => true,
                'in_app'   => true,
                'whatsapp' => $this->integrations->driver('whatsapp_cloud', 'messaging')->isConfigured(),
                'slack'    => $this->integrations->driver('slack', 'messaging')->isConfigured(),
                'teams'    => $this->integrations->driver('ms_teams', 'messaging')->isConfigured(),
            ],
            'activeModule' => 'settings',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'preferences'           => ['required', 'array'],
            'preferences.email'     => ['boolean'],
            'preferences.in_app'    => ['boolean'],
            'preferences.whatsapp'  => ['boolean'],
            'preferences.slack'     => ['boolean'],
            'preferences.teams'     => ['boolean'],

            'whatsapp_phone'        => ['nullable', 'string', 'max:20'],
            'whatsapp_consent'      => ['boolean'],
            'slack_user_id'         => ['nullable', 'string', 'max:30'],
        ]);

        $user = $request->user();

        $update = [
            'notification_channels' => $data['preferences'],
            'whatsapp_phone'        => $data['whatsapp_phone'] ?? null,
            'slack_user_id'         => $data['slack_user_id']  ?? null,
        ];

        // Stamp consent only when the user gave both consent AND a phone — and
        // clear it when they revoke either condition.
        $hasConsent = (bool) ($data['whatsapp_consent'] ?? false);
        $hasPhone   = ! empty($data['whatsapp_phone']);
        if ($hasConsent && $hasPhone) {
            $update['whatsapp_consent_at'] = $user->whatsapp_consent_at ?? now();
        } else {
            $update['whatsapp_consent_at'] = null;
            // Force whatsapp pref off if consent is missing, even if the toggle was on.
            $update['notification_channels']['whatsapp'] = false;
        }

        $user->update($update);

        return back()->with('success', 'Notification preferences updated.');
    }
}
