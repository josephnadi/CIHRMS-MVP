<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\Finance\PaystackException;
use App\Exceptions\Finance\PaystackUnreachableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Paystack REST API.
 *
 * Responsibilities:
 *   • Pesewas conversion (Paystack: amounts in minor units; CIHRMS: GHS)
 *   • Bearer-token authentication via config('services.paystack.secret_key')
 *   • Translates 4xx/5xx into PaystackException; connection failures into
 *     PaystackUnreachableException
 *
 * NO business logic — that lives in PaymentIntentService / WebhookProcessor.
 */
class PaystackGatewayService
{
    /**
     * @param  array{email:string, amount:float, reference:string, callback_url?:string, metadata?:array}  $data
     * @return array{authorization_url:string, access_code:string, reference:string}
     */
    public function initializeTransaction(array $data): array
    {
        $payload = [
            'email'     => $data['email'],
            'amount'    => (int) round($data['amount'] * 100),   // GHS → pesewas
            'reference' => $data['reference'],
        ];
        if (! empty($data['callback_url'])) {
            $payload['callback_url'] = $data['callback_url'];
        }
        if (! empty($data['metadata'])) {
            $payload['metadata'] = $data['metadata'];
        }

        try {
            $response = $this->client()->post('/transaction/initialize', $payload);
            return $this->parse($response, '/transaction/initialize')['data'];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $body = $e->response->json();
            $message = $body['message'] ?? "Paystack /transaction/initialize returned HTTP {$e->response->status()}";
            throw new PaystackException($message);
        }
    }

    /**
     * @return array  the parsed `data` field from Paystack's verify response
     */
    public function verifyTransaction(string $reference): array
    {
        try {
            $response = $this->client()->get("/transaction/verify/{$reference}");
            return $this->parse($response, "/transaction/verify/{$reference}")['data'];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $body = $e->response->json();
            $message = $body['message'] ?? "Paystack /transaction/verify/{$reference} returned HTTP {$e->response->status()}";
            throw new PaystackException($message);
        }
    }

    private function client()
    {
        try {
            return Http::baseUrl(config('services.paystack.url'))
                ->withToken(config('services.paystack.secret_key'))
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->retry(2, 250);
        } catch (ConnectionException $e) {
            throw new PaystackUnreachableException('Paystack unreachable: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{status:bool, message?:string, data:array}
     */
    private function parse(\Illuminate\Http\Client\Response $response, string $endpoint): array
    {
        $body = $response->json();

        if (! $response->ok() || ! is_array($body) || ($body['status'] ?? false) !== true) {
            $message = $body['message'] ?? "Paystack {$endpoint} returned HTTP {$response->status()}";
            throw new PaystackException($message);
        }

        return $body;
    }
}
