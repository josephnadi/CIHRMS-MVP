<?php

it('does not leak env or db topology on the public health endpoint', function () {
    $resp = $this->getJson('/api/v1/health');

    $resp->assertOk();
    $body = $resp->json();

    expect(array_keys($body))->toEqualCanonicalizing(['service', 'status', 'time']);
    expect($body)->not->toHaveKey('env');
    expect($body)->not->toHaveKey('database');
    expect($body)->not->toHaveKey('version');
    expect($body)->not->toHaveKey('api');
});
