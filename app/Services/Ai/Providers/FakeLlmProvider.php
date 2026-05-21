<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmProvider;
use App\Services\Ai\Contracts\LlmResponse;

/**
 * Deterministic test double.
 *
 *  - Records every (system, cached, user) prompt it was asked to complete
 *    so tests can assert PII never crossed the boundary.
 *  - Returns a canned reply (configurable via `queue()`) so the controller
 *    contract can be tested without network access.
 *  - Simulates cache behaviour: first call returns cacheCreationTokens > 0,
 *    repeated calls with the same (systemPrompt, cachedContext) prefix
 *    return cacheReadTokens > 0. Lets the EmployeeSummaryService caching
 *    assertion be black-box.
 */
final class FakeLlmProvider implements LlmProvider
{
    /** @var array<int, array{system:string, cached:string, user:string}> */
    public array $calls = [];

    /** @var array<int, string> */
    private array $replies = [];

    private string $defaultReply = 'FAKE LLM SUMMARY: ok.';

    /** @var array<string, true> Cache key = sha1(systemPrompt . cachedContext) */
    private array $cacheHits = [];

    public function queue(string $reply): self
    {
        $this->replies[] = $reply;
        return $this;
    }

    public function complete(string $systemPrompt, string $cachedContext, string $userPrompt): LlmResponse
    {
        $this->calls[] = [
            'system' => $systemPrompt,
            'cached' => $cachedContext,
            'user'   => $userPrompt,
        ];

        $reply = array_shift($this->replies) ?? $this->defaultReply;

        $cacheKey = sha1($systemPrompt.'|'.$cachedContext);
        $isHit = isset($this->cacheHits[$cacheKey]);
        $this->cacheHits[$cacheKey] = true;

        $prefixLen = max(1, (int) ceil(strlen($systemPrompt.$cachedContext) / 4));

        return new LlmResponse(
            text: $reply,
            model: 'fake-haiku-1',
            inputTokens: max(1, (int) ceil(strlen($userPrompt) / 4)),
            outputTokens: max(1, (int) ceil(strlen($reply) / 4)),
            cacheReadTokens: $isHit ? $prefixLen : 0,
            cacheCreationTokens: $isHit ? 0 : $prefixLen,
        );
    }

    public function name(): string
    {
        return 'fake:fake-haiku-1';
    }
}
