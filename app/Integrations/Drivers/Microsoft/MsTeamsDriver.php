<?php

namespace App\Integrations\Drivers\Microsoft;

use App\Integrations\Contracts\MessagingProvider;
use App\Integrations\DTO\MessageDto;
use App\Integrations\Drivers\AbstractDriver;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Microsoft Teams via Incoming Webhook (no OAuth, channel-scoped).
 *
 * The webhook URL is generated when an admin adds the "Incoming Webhook" connector
 * to a Teams channel — drop it into TEAMS_HR_WEBHOOK. We POST adaptive cards.
 *
 * `to` is the destination webhook URL; pass empty/null to use the default
 * configured webhook (the HR channel).
 *
 * Inbound is intentionally not supported — the Incoming Webhook connector is one-way.
 * Two-way Teams bots require a different (Bot Framework) integration that we'd
 * add as a separate driver later.
 */
class MsTeamsDriver extends AbstractDriver implements MessagingProvider
{
    public function provider(): string
    {
        return 'ms_teams';
    }

    public function capability(): string
    {
        return 'messaging';
    }

    public function displayName(): string
    {
        return $this->driverConfig()['display_name'] ?? 'Microsoft Teams';
    }

    protected function requiredConfigKeys(): array
    {
        return ['webhook_url'];
    }

    public function ping(): bool
    {
        // Incoming webhooks have no probe endpoint; treat "configured" as healthy.
        return $this->isConfigured();
    }

    public function sendText(string $to, string $body): string
    {
        return $this->track('messaging.text', ['len' => strlen($body)], function () use ($to, $body) {
            $card = $this->buildAdaptiveCard($body);
            return $this->postCard($this->resolveUrl($to), $card);
        });
    }

    public function sendTemplate(string $to, string $templateName, array $params = [], ?string $language = null): string
    {
        return $this->track('messaging.template', ['template' => $templateName], function () use ($to, $templateName, $params) {
            $card = $params['card'] ?? $this->renderBuiltinTemplate($templateName, $params);
            return $this->postCard($this->resolveUrl($to), $card);
        });
    }

    public function sendMedia(string $to, string $mediaUrl, ?string $caption = null, string $type = 'image'): string
    {
        return $this->track('messaging.media', ['type' => $type], function () use ($to, $mediaUrl, $caption) {
            $card = [
                'type' => 'AdaptiveCard',
                '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                'version' => '1.4',
                'body' => array_filter([
                    $caption ? ['type' => 'TextBlock', 'text' => $caption, 'wrap' => true] : null,
                    ['type' => 'Image', 'url' => $mediaUrl, 'size' => 'Stretch'],
                ]),
            ];
            return $this->postCard($this->resolveUrl($to), $card);
        });
    }

    public function parseInbound(array $payload): ?MessageDto
    {
        // Incoming webhook is one-way. Future Bot-Framework driver would implement this.
        return null;
    }

    /** Wrap an Adaptive Card in the Teams "attachments" envelope and POST it. */
    protected function postCard(string $url, array $card): string
    {
        $envelope = [
            'type'        => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content'     => array_merge([
                    'type'    => 'AdaptiveCard',
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'version' => '1.4',
                ], $card),
            ]],
        ];

        $response = Http::asJson()->timeout(20)->post($url, $envelope)->throw();

        // Teams returns "1" for success on classic webhooks and JSON for the new ones —
        // either way, the response code is the success signal.
        return (string) $response->status();
    }

    protected function resolveUrl(string $to): string
    {
        if ($to !== '') {
            return $to;
        }
        $url = $this->driverConfig()['webhook_url'] ?? null;
        if (! $url) {
            throw new RuntimeException('TEAMS_HR_WEBHOOK is not configured.');
        }
        return (string) $url;
    }

    protected function buildAdaptiveCard(string $body): array
    {
        return [
            'body' => [
                ['type' => 'TextBlock', 'text' => $body, 'wrap' => true],
            ],
        ];
    }

    /** Built-in Adaptive Card templates so callers don't have to hand-roll JSON. */
    protected function renderBuiltinTemplate(string $name, array $params): array
    {
        return match ($name) {
            'leave_approval' => [
                'body' => [
                    ['type' => 'TextBlock', 'text' => "Leave request from {$params['employee']}", 'weight' => 'Bolder', 'size' => 'Medium'],
                    ['type' => 'FactSet', 'facts' => [
                        ['title' => 'Type',  'value' => $params['type']  ?? '—'],
                        ['title' => 'Start', 'value' => $params['start'] ?? '—'],
                        ['title' => 'End',   'value' => $params['end']   ?? '—'],
                    ]],
                ],
                'actions' => [
                    ['type' => 'Action.OpenUrl', 'title' => 'Open in CIHRMS', 'url' => $params['url'] ?? url('/dashboard?module=leave')],
                ],
            ],
            'ticket_announce' => [
                'body' => [
                    ['type' => 'TextBlock', 'text' => "New {$params['priority']} ticket #{$params['id']}", 'weight' => 'Bolder', 'size' => 'Medium'],
                    ['type' => 'TextBlock', 'text' => $params['title'] ?? '', 'wrap' => true],
                    ['type' => 'TextBlock', 'text' => "Reporter: {$params['reporter']}", 'isSubtle' => true, 'spacing' => 'None'],
                ],
                'actions' => [
                    ['type' => 'Action.OpenUrl', 'title' => 'View ticket', 'url' => $params['url'] ?? url('/dashboard?module=tickets')],
                ],
            ],
            default => [
                'body' => [
                    ['type' => 'TextBlock', 'text' => $params['text'] ?? $name, 'wrap' => true],
                ],
            ],
        };
    }
}
