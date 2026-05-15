<?php

namespace App\Integrations;

use App\Integrations\Drivers\Microsoft\MsTeamsDriver;
use App\Integrations\Drivers\Meta\WhatsAppCloudDriver;
use App\Integrations\Drivers\Slack\SlackDriver;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Fan a single domain message out to the user's preferred channels respecting:
 *   - their notification_channels JSON preferences
 *   - per-channel availability (driver class wired + integration enabled)
 *   - WhatsApp consent + a working phone number
 *   - per-user Slack id when present, falling back to default channel
 *
 * Each provider has a different addressing model so this service handles the
 * channel-specific destination resolution rather than every listener doing it.
 */
class MessagingDispatcher
{
    public function __construct(protected IntegrationManager $integrations) {}

    /**
     * @param  array{whatsapp_template?: string, whatsapp_params?: array, slack_template?: string, teams_template?: string, params?: array}  $opts
     * @return array<string, string|null>  channel → message id (null on skip)
     */
    public function send(User $recipient, string $body, array $opts = []): array
    {
        $prefs = $recipient->notificationPreferences();
        $sent  = [];

        if ($prefs['whatsapp']) {
            $sent['whatsapp'] = $this->safe('whatsapp', fn () => $this->sendWhatsApp($recipient, $body, $opts));
        }
        if ($prefs['slack']) {
            $sent['slack'] = $this->safe('slack', fn () => $this->sendSlack($recipient, $body, $opts));
        }
        if ($prefs['teams']) {
            $sent['teams'] = $this->safe('teams', fn () => $this->sendTeams($body, $opts));
        }

        return $sent;
    }

    /** Broadcast to a configured channel (e.g. HR ops channel) — no per-user routing. */
    public function broadcast(string $body, array $opts = []): array
    {
        $sent = [];
        if ($this->integrations->driver('slack', 'messaging')->isConfigured()) {
            $sent['slack'] = $this->safe('slack', function () use ($body, $opts) {
                /** @var SlackDriver $slack */
                $slack = $this->integrations->driver('slack', 'messaging');
                return isset($opts['slack_template'])
                    ? $slack->sendTemplate('', $opts['slack_template'], $opts['params'] ?? [])
                    : $slack->sendText('', $body);
            });
        }
        if ($this->integrations->driver('ms_teams', 'messaging')->isConfigured()) {
            $sent['teams'] = $this->safe('teams', function () use ($body, $opts) {
                /** @var MsTeamsDriver $teams */
                $teams = $this->integrations->driver('ms_teams', 'messaging');
                return isset($opts['teams_template'])
                    ? $teams->sendTemplate('', $opts['teams_template'], $opts['params'] ?? [])
                    : $teams->sendText('', $body);
            });
        }
        return $sent;
    }

    protected function sendWhatsApp(User $recipient, string $body, array $opts): ?string
    {
        if (! $recipient->hasWhatsappConsent()) {
            return null;
        }
        /** @var WhatsAppCloudDriver $wa */
        $wa = $this->integrations->driver('whatsapp_cloud', 'messaging');
        if (! $wa->isConfigured()) return null;

        // Outside the 24h reply window we MUST use a template — caller may pass one.
        if (! empty($opts['whatsapp_template'])) {
            return $wa->sendTemplate(
                to:           $recipient->whatsapp_phone,
                templateName: $opts['whatsapp_template'],
                params:       $opts['whatsapp_params'] ?? [],
            );
        }

        return $wa->sendText($recipient->whatsapp_phone, $body);
    }

    protected function sendSlack(User $recipient, string $body, array $opts): ?string
    {
        /** @var SlackDriver $slack */
        $slack = $this->integrations->driver('slack', 'messaging');
        if (! $slack->isConfigured()) return null;

        $to = $recipient->slack_user_id ?: '';
        return isset($opts['slack_template'])
            ? $slack->sendTemplate($to, $opts['slack_template'], $opts['params'] ?? [])
            : $slack->sendText($to, $body);
    }

    protected function sendTeams(string $body, array $opts): ?string
    {
        /** @var MsTeamsDriver $teams */
        $teams = $this->integrations->driver('ms_teams', 'messaging');
        if (! $teams->isConfigured()) return null;

        return isset($opts['teams_template'])
            ? $teams->sendTemplate('', $opts['teams_template'], $opts['params'] ?? [])
            : $teams->sendText('', $body);
    }

    protected function safe(string $channel, callable $cb): ?string
    {
        try {
            return $cb();
        } catch (\Throwable $e) {
            Log::warning("[messaging:{$channel}] dispatch failed", ['error' => $e->getMessage()]);
            return null;
        }
    }
}
