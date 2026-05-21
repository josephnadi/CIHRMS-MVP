<?php

namespace App\Services\Ai\Providers;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use App\Services\Ai\Contracts\LlmProvider;
use App\Services\Ai\Contracts\LlmResponse;
use RuntimeException;

/**
 * Real Anthropic provider. We use two cache breakpoints on the system block:
 *
 *   [0] = the stable instruction prompt        (cache_control: ephemeral)
 *   [1] = the stable employee-data template    (cache_control: ephemeral)
 *
 * The user message carries only the volatile per-call payload. Anthropic
 * matches caches by prefix, so as long as [0] and [1] are byte-identical
 * across calls (which is why the per-employee data goes in the user
 * message, not in [1]) we get cache_read_input_tokens > 0 on subsequent
 * requests. Haiku 4.5's minimum cacheable prefix is ~2048 tokens, so the
 * template is intentionally verbose enough to clear that bar.
 */
final class AnthropicLlmProvider implements LlmProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens = 400,
        private readonly int $timeout = 20,
        private readonly ?Client $client = null,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('AnthropicLlmProvider: api_key not configured. Set ANTHROPIC_API_KEY or disable AI_ENABLED.');
        }
    }

    public function complete(string $systemPrompt, string $cachedContext, string $userPrompt): LlmResponse
    {
        $client = $this->client ?? new Client(apiKey: $this->apiKey);

        $message = $client->messages->create(
            maxTokens: $this->maxTokens,
            messages: [
                ['role' => 'user', 'content' => $userPrompt],
            ],
            model: $this->model,
            system: [
                [
                    'type' => 'text',
                    'text' => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
                [
                    'type' => 'text',
                    'text' => $cachedContext,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
        );

        $text = '';
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        $usage = $message->usage;

        return new LlmResponse(
            text: trim($text),
            model: $this->model,
            inputTokens: $usage->inputTokens ?? 0,
            outputTokens: $usage->outputTokens ?? 0,
            cacheReadTokens: $usage->cacheReadInputTokens ?? 0,
            cacheCreationTokens: $usage->cacheCreationInputTokens ?? 0,
        );
    }

    public function name(): string
    {
        return 'anthropic:'.$this->model;
    }
}
