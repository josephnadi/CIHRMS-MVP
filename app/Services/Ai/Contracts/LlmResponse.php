<?php

namespace App\Services\Ai\Contracts;

/**
 * Plain DTO for an LLM reply. Includes cache-hit accounting so the
 * AiAssistant UI / metrics dashboard can report savings from prompt
 * caching — without coupling callers to the Anthropic SDK shape.
 */
final class LlmResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheCreationTokens = 0,
    ) {}
}
