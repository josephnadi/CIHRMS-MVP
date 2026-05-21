<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert as PHPUnit;
use PHPUnit\Framework\AssertionFailedError;

abstract class TestCase extends BaseTestCase
{
    /**
     * Re-register the assertInertia macro with JSON_PRESERVE_ZERO_FRACTION so
     * that PHP float zeros (0.0) survive the json_encode → json_decode round-trip
     * that Inertia's test helper performs internally.  Without this flag,
     * json_encode(0.0) → "0" → json_decode → int(0), breaking assertSame(0.0, 0).
     */
    protected function setUp(): void
    {
        parent::setUp();

        TestResponse::macro('assertInertia', function (?callable $callback = null) {
            /** @var TestResponse $this */
            try {
                $this->assertViewHas('page');
                $page = json_decode(
                    json_encode($this->viewData('page'), JSON_PRESERVE_ZERO_FRACTION),
                    true,
                );

                PHPUnit::assertIsArray($page);
                PHPUnit::assertArrayHasKey('component', $page);
                PHPUnit::assertArrayHasKey('props', $page);
                PHPUnit::assertArrayHasKey('url', $page);
                PHPUnit::assertArrayHasKey('version', $page);
            } catch (AssertionFailedError) {
                PHPUnit::fail('Not a valid Inertia response.');
            }

            $assert = AssertableInertia::fromArray($page['props']);

            // Patch the component / url / version onto the instance via reflection
            // so component() / url() checks still work.
            $ref = new \ReflectionObject($assert);
            foreach (['component', 'url', 'version'] as $prop) {
                if ($ref->hasProperty($prop)) {
                    $p = $ref->getProperty($prop);
                    $p->setAccessible(true);
                    $p->setValue($assert, $page[$prop] ?? null);
                }
            }
            // encryptHistory / clearHistory / deferredProps / flash (Inertia v2)
            foreach (['encryptHistory', 'clearHistory', 'deferredProps', 'flash'] as $prop) {
                if ($ref->hasProperty($prop)) {
                    $p = $ref->getProperty($prop);
                    $p->setAccessible(true);
                    $p->setValue($assert, $page[$prop] ?? ($prop === 'deferredProps' || $prop === 'flash' ? [] : false));
                }
            }

            if ($callback !== null) {
                $callback($assert);
            }

            return $this;
        });
    }
}
