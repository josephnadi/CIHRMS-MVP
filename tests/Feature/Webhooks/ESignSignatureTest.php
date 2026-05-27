<?php

use App\Models\Applicant;

beforeEach(function () {
    config([
        'integrations.webhooks.esign.zoho_secret'     => 'zoho-shh',
        'integrations.webhooks.esign.docusign_secret' => 'ds-shh',
    ]);
});

it('rejects an esign webhook with no signature header', function () {
    $resp = $this->postJson(route('webhooks.esign'), ['envelopeId' => 'env-1', 'status' => 'completed']);
    $resp->assertStatus(401);
});

it('rejects an esign webhook with a forged Zoho signature', function () {
    $body = json_encode(['requests' => ['request_id' => 'r1', 'request_status' => 'completed']]);
    $forged = base64_encode(hash_hmac('sha256', $body, 'wrong-secret', true));

    $resp = $this->call(
        method:  'POST',
        uri:     route('webhooks.esign'),
        server:  [
            'HTTP_X-Zs-Webhook-Hmac-Sha256' => $forged,
            'CONTENT_TYPE'                  => 'application/json',
        ],
        content: $body,
    );
    expect($resp->status())->toBe(401);
});

it('accepts a properly signed Zoho Sign webhook', function () {
    $body = json_encode([
        'requests' => ['request_id' => 'r1', 'request_status' => 'completed'],
    ]);
    $sig = base64_encode(hash_hmac('sha256', $body, 'zoho-shh', true));

    $resp = $this->call(
        method:  'POST',
        uri:     route('webhooks.esign'),
        server:  [
            'HTTP_X-Zs-Webhook-Hmac-Sha256' => $sig,
            'CONTENT_TYPE'                  => 'application/json',
        ],
        content: $body,
    );
    $resp->assertStatus(200);
});

it('accepts a properly signed DocuSign webhook', function () {
    $body = json_encode(['envelopeId' => 'env-1', 'status' => 'completed']);
    $sig  = base64_encode(hash_hmac('sha256', $body, 'ds-shh', true));

    $resp = $this->call(
        method:  'POST',
        uri:     route('webhooks.esign'),
        server:  [
            'HTTP_X-DocuSign-Signature-1' => $sig,
            'CONTENT_TYPE'                => 'application/json',
        ],
        content: $body,
    );
    $resp->assertStatus(200);
});

it('rejects when the Zoho secret is not configured even with a header present', function () {
    config(['integrations.webhooks.esign.zoho_secret' => '']);
    $body = json_encode(['requests' => ['request_id' => 'r1']]);
    $sig  = base64_encode(hash_hmac('sha256', $body, 'whatever', true));

    $resp = $this->call(
        method:  'POST',
        uri:     route('webhooks.esign'),
        server:  ['HTTP_X-Zs-Webhook-Hmac-Sha256' => $sig, 'CONTENT_TYPE' => 'application/json'],
        content: $body,
    );
    expect($resp->status())->toBe(401);
});
