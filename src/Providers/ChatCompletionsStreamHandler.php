<?php

declare(strict_types=1);

namespace Prism\Prism\Providers;

use Generator;
use Prism\Prism\Text\Request;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Prism\Prism\Streaming\Events\StreamEvent;

/**
 * Shared base for providers using the chat completions wire format.
 *
 * Concrete providers: DeepSeek, Groq, XAI, Mistral, OpenRouter.
 */
abstract class ChatCompletionsStreamHandler extends StreamHandler
{
    /**
     * Parse the next chunk from an SSE stream (delegates to base parseSSEDataLine).
     *
     * @return array<string, mixed>|null
     */
    protected function parseNextChunk(StreamInterface $stream): ?array
    {
        return $this->parseSSEDataLine($stream);
    }

    /**
     * Process a single parsed chunk from the chat completions format.
     *
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    protected function processChunk(array $data, Request $request): Generator
    {
        yield from $this->yieldStreamStartIfNeeded($this->resolveModel($data, $request));
        yield from $this->yieldStepStartIfNeeded();

        // Thinking
        $thinkingDelta = $this->extractThinkingDelta($data, $request);
        if ($thinkingDelta !== '') {
            yield from $this->yieldThinkingDelta($thinkingDelta);

            return;
        }

        // Transition from thinking to text
        if ($this->state->hasThinkingStarted()) {
            yield from $this->yieldThinkingCompleteIfNeeded();
        }

        // Tool calls
        if ($this->hasToolCalls($data)) {
            $this->accumulateToolCalls($data);

            return;
        }

        // Text
        $content = $this->extractContentDelta($data);
        if ($content !== '') {
            yield from $this->yieldTextDelta($content);
        }

        // Finish
        $rawFinishReason = data_get($data, 'choices.0.finish_reason');
        if ($rawFinishReason !== null) {
            yield from $this->yieldTextCompleteIfNeeded();
            yield from $this->yieldThinkingCompleteIfNeeded();

            $this->state->withFinishReason($this->mapFinishReason($data));

            $usage = $this->extractUsage($data);
            if ($usage instanceof Usage) {
                $this->state->addUsage($usage);
            }
        }
    }

    /**
     * Accumulate tool call deltas into state.
     *
     * @param  array<string, mixed>  $data
     */
    protected function accumulateToolCalls(array $data): void
    {
        $updated = $this->extractToolCalls($data, $this->state->toolCalls());

        foreach ($updated as $index => $toolCall) {
            $this->state->addToolCall($index, $toolCall);
        }
    }

    /**
     * Resolve the model name from the chunk data or fall back to the request.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveModel(array $data, Request $request): string
    {
        return data_get($data, 'model', $request->model());
    }

    // ──────────────────────────────────────────────────────────
    //  Extraction hooks
    // ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractThinkingDelta(array $data, Request $request): string
    {
        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractContentDelta(array $data): string
    {
        return data_get($data, 'choices.0.delta.content') ?? '';
    }

    // ──────────────────────────────────────────────────────────
    //  Abstract
    // ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function hasToolCalls(array $data): bool;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    abstract protected function extractToolCalls(array $data, array $toolCalls): array;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function mapFinishReason(array $data): FinishReason;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function extractUsage(array $data): ?Usage;
}
