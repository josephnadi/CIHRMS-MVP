<?php

namespace App\Services\Ai\Contracts;

interface LlmProvider
{
    /**
     * Send a single-turn prompt and return the model's text reply.
     *
     * The provider is responsible for any prompt-caching, retry, or
     * timeout handling — callers should treat this as a synchronous,
     * already-redacted boundary call.
     *
     * @param  string  $systemPrompt  Stable instructions cached on the prefix.
     * @param  string  $cachedContext Stable structured context (e.g. the employee
     *                                schema template) — appended to the system
     *                                block and cached together with it.
     * @param  string  $userPrompt    The volatile per-request question.
     *
     * @return LlmResponse
     */
    public function complete(string $systemPrompt, string $cachedContext, string $userPrompt): LlmResponse;

    /** Identifier for logging / observability (e.g. "anthropic:claude-haiku-4-5"). */
    public function name(): string;
}
