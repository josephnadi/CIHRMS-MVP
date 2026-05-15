<?php

namespace App\Integrations\Drivers\Meta;

use App\Integrations\Contracts\MessagingProvider;
use App\Integrations\DTO\MessageDto;
use App\Integrations\Drivers\AbstractDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WhatsApp Business Cloud API (Meta Graph v21.0) — text, template, media, parseInbound.
 *
 * No OAuth: WhatsApp uses a permanent system-user access token configured in env.
 * `to` is always the recipient's MSISDN in international format without `+` (e.g. `233244123456`).
 *
 * Proactive messages (outside the 24h customer-service window) MUST use sendTemplate()
 * with a pre-approved HSM template name; sendText() will fail silently in that case.
 */
class WhatsAppCloudDriver extends AbstractDriver implements MessagingProvider
{
    public function provider(): string
    {
        return 'whatsapp_cloud';
    }

    public function capability(): string
    {
        return 'messaging';
    }

    public function displayName(): string
    {
        return $this->driverConfig()['display_name'] ?? 'WhatsApp Business (Cloud)';
    }

    protected function requiredConfigKeys(): array
    {
        return ['phone_number_id', 'access_token'];
    }

    public function ping(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        try {
            return $this->http()->get($this->phoneNumberId())->ok();
        } catch (\Throwable) {
            return false;
        }
    }

    public function sendText(string $to, string $body): string
    {
        return $this->track('messaging.text', ['to' => $to, 'len' => strlen($body)], function () use ($to, $body) {
            $response = $this->http()->post($this->phoneNumberId().'/messages', [
                'messaging_product' => 'whatsapp',
                'to'                => $this->normalisePhone($to),
                'type'              => 'text',
                'text'              => ['body' => $body, 'preview_url' => true],
            ])->throw();

            return (string) data_get($response->json(), 'messages.0.id', '');
        });
    }

    public function sendTemplate(string $to, string $templateName, array $params = [], ?string $language = null): string
    {
        return $this->track('messaging.template', [
            'to'       => $to,
            'template' => $templateName,
            'params'   => count($params),
        ], function () use ($to, $templateName, $params, $language) {
            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $this->normalisePhone($to),
                'type'              => 'template',
                'template' => [
                    'name'     => $templateName,
                    'language' => ['code' => $language ?? 'en_US'],
                ],
            ];

            if ($params) {
                $payload['template']['components'] = [[
                    'type'       => 'body',
                    'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($params)),
                ]];
            }

            $response = $this->http()->post($this->phoneNumberId().'/messages', $payload)->throw();
            return (string) data_get($response->json(), 'messages.0.id', '');
        });
    }

    public function sendMedia(string $to, string $mediaUrl, ?string $caption = null, string $type = 'image'): string
    {
        return $this->track('messaging.media', ['to' => $to, 'type' => $type], function () use ($to, $mediaUrl, $caption, $type) {
            $type = in_array($type, ['image', 'document', 'audio', 'video'], true) ? $type : 'document';

            $response = $this->http()->post($this->phoneNumberId().'/messages', [
                'messaging_product' => 'whatsapp',
                'to'                => $this->normalisePhone($to),
                'type'              => $type,
                $type               => array_filter(['link' => $mediaUrl, 'caption' => $caption]),
            ])->throw();

            return (string) data_get($response->json(), 'messages.0.id', '');
        });
    }

    public function parseInbound(array $payload): ?MessageDto
    {
        $entry   = data_get($payload, 'entry.0.changes.0.value');
        $message = data_get($entry, 'messages.0');
        if (! $message) {
            return null;
        }

        $type = (string) ($message['type'] ?? 'text');
        $body = match ($type) {
            'text'        => (string) data_get($message, 'text.body', ''),
            'interactive' => (string) data_get($message, 'interactive.button_reply.title')
                          ?: (string) data_get($message, 'interactive.list_reply.title'),
            'button'      => (string) data_get($message, 'button.text', ''),
            default       => '',
        };

        $mediaUrl = $type !== 'text' ? (string) data_get($message, "{$type}.id") : null;

        return new MessageDto(
            providerMessageId: (string) ($message['id'] ?? ''),
            from:              (string) ($message['from'] ?? ''),
            to:                (string) data_get($entry, 'metadata.display_phone_number'),
            body:              $body,
            type:              $type,
            mediaUrl:          $mediaUrl,
            receivedAt:        isset($message['timestamp']) ? new \DateTimeImmutable('@'.$message['timestamp']) : null,
            raw:               $message,
        );
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->driverConfig()['api_base'] ?? 'https://graph.facebook.com/v21.0', '/').'/')
            ->withToken($this->driverConfig()['access_token'])
            ->acceptJson()
            ->timeout(30);
    }

    protected function phoneNumberId(): string
    {
        $id = $this->driverConfig()['phone_number_id'] ?? null;
        if (! $id) {
            throw new RuntimeException('WHATSAPP_PHONE_ID is not configured.');
        }
        return (string) $id;
    }

    /** Strip non-digits and leading + so the API accepts the MSISDN. */
    protected function normalisePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? $phone;
    }
}
