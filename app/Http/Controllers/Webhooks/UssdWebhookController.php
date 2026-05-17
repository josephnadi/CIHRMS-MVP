<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Messaging\Ussd\UssdSessionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound USSD callback. Hubtel / AT / mNotify all POST a similar shape:
 *
 *   {
 *     "sessionId":  "...",
 *     "msisdn":     "233200000099",
 *     "shortcode":  "*920*HR#",
 *     "text":       "1*2"           // user's cumulative input
 *   }
 *
 * Response body is plain text — the provider shows it to the caller verbatim.
 * Lines starting with `CON ` keep the session open; `END ` terminates.
 */
class UssdWebhookController extends Controller
{
    public function __construct(private readonly UssdSessionHandler $handler) {}

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'sessionId' => ['required', 'string', 'max:64'],
            'msisdn'    => ['required', 'string', 'max:32'],
            'shortcode' => ['nullable', 'string', 'max:16'],
            'text'      => ['nullable', 'string'],
        ]);

        $body = $this->handler->handle(
            sessionId: $data['sessionId'],
            phone:     $data['msisdn'],
            shortcode: $data['shortcode'] ?? config('messaging.ussd.shortcode', ''),
            text:      $data['text'] ?? '',
        );

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }
}
