<?php

namespace App\Integrations\Drivers\Slack;

use App\Integrations\Contracts\MessagingProvider;
use App\Integrations\DTO\MessageDto;
use App\Integrations\Drivers\AbstractDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Slack messaging driver — chat.postMessage + slash-command parsing.
 *
 * Auth: Bot User OAuth Token (xoxb-…) configured in env. We don't run the OAuth
 * dance from here because Slack apps are usually pre-installed by the workspace
 * owner; the bot token is then dropped into .env.
 *
 * `to` may be:
 *   - "U01ABCXYZ"   — a Slack user id (DM)
 *   - "C012345"     — a channel id
 *   - "#hr"         — a channel name (Slack auto-resolves)
 *   - empty / null  — falls back to `default_channel` from config
 */
class SlackDriver extends AbstractDriver implements MessagingProvider
{
    public function provider(): string
    {
        return 'slack';
    }

    public function capability(): string
    {
        return 'messaging';
    }

    public function displayName(): string
    {
        return $this->driverConfig()['display_name'] ?? 'Slack';
    }

    protected function requiredConfigKeys(): array
    {
        return ['bot_token'];
    }

    public function ping(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        try {
            return $this->http()->post('auth.test')->ok();
        } catch (\Throwable) {
            return false;
        }
    }

    public function sendText(string $to, string $body): string
    {
        return $this->track('messaging.text', ['to' => $to, 'len' => strlen($body)], function () use ($to, $body) {
            $response = $this->http()->post('chat.postMessage', [
                'channel' => $this->resolveChannel($to),
                'text'    => $body,
            ])->throw();

            $this->guardSlackOk($response->json());

            return (string) data_get($response->json(), 'ts', '');
        });
    }

    /**
     * For Slack, "templates" are Block Kit blocks — pass them as `params['blocks']`
     * (raw Block Kit JSON) and we'll forward them. `templateName` is used as the
     * fallback `text` for notifications.
     *
     * Common templates we ship with for the leave/ticket flows:
     *   - 'leave_approval' → blocks rendered from $params (employee, type, dates, leave_id)
     *   - any other name   → falls back to a plain text message
     */
    public function sendTemplate(string $to, string $templateName, array $params = [], ?string $language = null): string
    {
        return $this->track('messaging.template', ['to' => $to, 'template' => $templateName], function () use ($to, $templateName, $params) {
            $blocks = $params['blocks'] ?? $this->renderBuiltinTemplate($templateName, $params);

            $response = $this->http()->post('chat.postMessage', array_filter([
                'channel' => $this->resolveChannel($to),
                'text'    => $params['fallback_text'] ?? $templateName,
                'blocks'  => $blocks,
            ]))->throw();

            $this->guardSlackOk($response->json());

            return (string) data_get($response->json(), 'ts', '');
        });
    }

    public function sendMedia(string $to, string $mediaUrl, ?string $caption = null, string $type = 'image'): string
    {
        return $this->track('messaging.media', ['to' => $to, 'type' => $type], function () use ($to, $mediaUrl, $caption) {
            $blocks = [
                ['type' => 'image', 'image_url' => $mediaUrl, 'alt_text' => $caption ?? 'attachment'],
            ];
            if ($caption) {
                array_unshift($blocks, ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $caption]]);
            }

            $response = $this->http()->post('chat.postMessage', [
                'channel' => $this->resolveChannel($to),
                'text'    => $caption ?? $mediaUrl,
                'blocks'  => $blocks,
            ])->throw();

            $this->guardSlackOk($response->json());

            return (string) data_get($response->json(), 'ts', '');
        });
    }

    /**
     * Slack inbound shapes we care about:
     *   - Slash command (form-encoded)  → text + command + user_id + channel_id
     *   - Event API (JSON, type=event_callback) → event.user/text/channel
     *   - Block Kit interaction (form-encoded `payload`)
     */
    public function parseInbound(array $payload): ?MessageDto
    {
        // Slash command
        if (! empty($payload['command']) && ! empty($payload['user_id'])) {
            return new MessageDto(
                providerMessageId: (string) ($payload['trigger_id'] ?? ''),
                from:              (string) $payload['user_id'],
                to:                (string) ($payload['channel_id'] ?? ''),
                body:              trim((string) ($payload['command'].' '.($payload['text'] ?? ''))),
                type:              'slash_command',
                raw:               $payload,
            );
        }

        // Event API message.* / app_mention
        if (($payload['type'] ?? null) === 'event_callback' && $event = data_get($payload, 'event')) {
            return new MessageDto(
                providerMessageId: (string) ($event['ts'] ?? ''),
                from:              (string) ($event['user'] ?? ''),
                to:                (string) ($event['channel'] ?? ''),
                body:              (string) ($event['text'] ?? ''),
                type:              (string) ($event['type'] ?? 'message'),
                raw:               $event,
            );
        }

        return null;
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl('https://slack.com/api/')
            ->withToken($this->driverConfig()['bot_token'])
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    protected function resolveChannel(string $to): string
    {
        if ($to !== '') {
            return $to;
        }
        return (string) ($this->driverConfig()['default_channel'] ?? '#general');
    }

    /** Slack returns 200 even on failures — must inspect `ok`. */
    protected function guardSlackOk(?array $body): void
    {
        if (! is_array($body) || empty($body['ok'])) {
            throw new RuntimeException('Slack API call failed: '.($body['error'] ?? 'unknown'));
        }
    }

    /** Tiny built-in Block Kit templates so callers don't have to hand-roll JSON. */
    protected function renderBuiltinTemplate(string $name, array $params): array
    {
        return match ($name) {
            'leave_approval' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "Leave request from {$params['employee']}"]],
                ['type' => 'section', 'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*Type:*\n{$params['type']}"],
                    ['type' => 'mrkdwn', 'text' => "*Dates:*\n{$params['start']} → {$params['end']}"],
                ]],
                ['type' => 'actions', 'elements' => [
                    ['type' => 'button', 'style' => 'primary', 'text' => ['type' => 'plain_text', 'text' => 'Approve'], 'value' => "approve:{$params['leave_id']}", 'action_id' => 'leave_approve'],
                    ['type' => 'button', 'style' => 'danger',  'text' => ['type' => 'plain_text', 'text' => 'Reject'],  'value' => "reject:{$params['leave_id']}",  'action_id' => 'leave_reject'],
                ]],
            ],
            'ticket_assigned' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*New ticket assigned:* <{$params['url']}|#{$params['id']} {$params['title']}>"]],
                ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => "Priority: {$params['priority']} · Reporter: {$params['reporter']}"]]],
            ],
            default => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $params['text'] ?? $name]],
            ],
        };
    }
}
