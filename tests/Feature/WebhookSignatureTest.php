<?php

use Illuminate\Support\Facades\Config;

// ─────────────────────────────────────────────────────────────────────────────
// WhatsApp (Meta) — sha256 HMAC of raw body + verify-token handshake
// ─────────────────────────────────────────────────────────────────────────────

test('whatsapp GET handshake succeeds with the correct verify token', function () {
    Config::set('integrations.webhooks.whatsapp.verify_token', 'meta-verify-token');

    $this->get('/webhooks/whatsapp?hub_verify_token=meta-verify-token&hub_challenge=42')
        ->assertOk();
});

test('whatsapp GET handshake fails with the wrong verify token', function () {
    Config::set('integrations.webhooks.whatsapp.verify_token', 'meta-verify-token');

    $this->get('/webhooks/whatsapp?hub_verify_token=wrong')
        ->assertStatus(401);
});

test('whatsapp POST accepts a correctly signed payload', function () {
    Config::set('integrations.webhooks.whatsapp.app_secret', 'wa-secret');

    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);
    $sig  = 'sha256='.hash_hmac('sha256', $body, 'wa-secret');

    $this->call('POST', '/webhooks/whatsapp', [], [], [], [
        'HTTP_X-Hub-Signature-256' => $sig,
        'CONTENT_TYPE'             => 'application/json',
    ], $body)->assertOk();
});

test('whatsapp POST rejects a payload with an invalid signature', function () {
    Config::set('integrations.webhooks.whatsapp.app_secret', 'wa-secret');

    $this->postJson('/webhooks/whatsapp', ['entry' => []], [
        'X-Hub-Signature-256' => 'sha256=deadbeef',
    ])->assertStatus(401);
});

// ─────────────────────────────────────────────────────────────────────────────
// Zoho — shared secret in X-Zoho-Webhook-Token header
// ─────────────────────────────────────────────────────────────────────────────

test('zoho POST accepts a request with the matching shared secret', function () {
    Config::set('integrations.webhooks.zoho.shared_secret', 'zoho-shared-secret');
    Config::set('integrations.webhooks.zoho.header', 'X-Zoho-Webhook-Token');

    $this->postJson('/webhooks/zoho', ['event' => 'contact.created'], [
        'X-Zoho-Webhook-Token' => 'zoho-shared-secret',
    ])->assertOk();
});

test('zoho POST rejects a request with a wrong shared secret', function () {
    Config::set('integrations.webhooks.zoho.shared_secret', 'zoho-shared-secret');
    Config::set('integrations.webhooks.zoho.header', 'X-Zoho-Webhook-Token');

    $this->postJson('/webhooks/zoho', ['event' => 'contact.created'], [
        'X-Zoho-Webhook-Token' => 'wrong-secret',
    ])->assertStatus(401);
});

// ─────────────────────────────────────────────────────────────────────────────
// MS Graph — clientState match, or validationToken handshake
// ─────────────────────────────────────────────────────────────────────────────

test('ms graph subscription validationToken handshake passes signature check', function () {
    Config::set('integrations.webhooks.ms_graph.client_state', 'graph-client-state');

    $this->call('POST', '/webhooks/ms-graph?validationToken=hello')
        ->assertOk();
});

test('ms graph POST rejects a payload with mismatched clientState', function () {
    Config::set('integrations.webhooks.ms_graph.client_state', 'graph-client-state');

    $this->postJson('/webhooks/ms-graph', [
        'value' => [
            ['clientState' => 'not-the-right-one'],
        ],
    ])->assertStatus(401);
});

// ─────────────────────────────────────────────────────────────────────────────
// Google — channel token in X-Goog-Channel-Token header
// ─────────────────────────────────────────────────────────────────────────────

test('google POST accepts a request with the matching channel token', function () {
    Config::set('integrations.webhooks.google.channel_token', 'goog-channel-token');

    $this->postJson('/webhooks/google', ['resourceId' => 'abc'], [
        'X-Goog-Channel-Token' => 'goog-channel-token',
    ])->assertOk();
});

test('google POST rejects a request with no token', function () {
    Config::set('integrations.webhooks.google.channel_token', 'goog-channel-token');

    $this->postJson('/webhooks/google', ['resourceId' => 'abc'])
        ->assertStatus(401);
});

// ─────────────────────────────────────────────────────────────────────────────
// Slack — v0=hex HMAC of "v0:{ts}:{body}" + 5-minute replay window
// ─────────────────────────────────────────────────────────────────────────────

test('slack POST accepts a correctly signed payload within the replay window', function () {
    Config::set('integrations.webhooks.slack.signing_secret', 'slack-signing-secret');

    $timestamp = (string) time();
    $body      = json_encode(['type' => 'event_callback', 'event' => ['type' => 'message']]);
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'slack-signing-secret');

    $this->call('POST', '/webhooks/slack/events', [], [], [], [
        'HTTP_X-Slack-Request-Timestamp' => $timestamp,
        'HTTP_X-Slack-Signature'         => $signature,
        'CONTENT_TYPE'                   => 'application/json',
    ], $body)->assertOk();
});

test('slack POST rejects a stale (replayed) request more than 5 minutes old', function () {
    Config::set('integrations.webhooks.slack.signing_secret', 'slack-signing-secret');

    $timestamp = (string) (time() - 600); // 10 minutes ago
    $body      = json_encode(['type' => 'event_callback']);
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'slack-signing-secret');

    $this->call('POST', '/webhooks/slack/events', [], [], [], [
        'HTTP_X-Slack-Request-Timestamp' => $timestamp,
        'HTTP_X-Slack-Signature'         => $signature,
        'CONTENT_TYPE'                   => 'application/json',
    ], $body)->assertStatus(401);
});

test('slack POST rejects a tampered signature', function () {
    Config::set('integrations.webhooks.slack.signing_secret', 'slack-signing-secret');

    $this->postJson('/webhooks/slack/events', ['type' => 'event_callback'], [
        'X-Slack-Request-Timestamp' => (string) time(),
        'X-Slack-Signature'         => 'v0=deadbeef',
    ])->assertStatus(401);
});
