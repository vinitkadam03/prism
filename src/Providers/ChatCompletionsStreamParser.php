<?php

declare(strict_types=1);

namespace Prism\Prism\Providers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Concerns\ParsesSSE;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\TurnResult;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

abstract class ChatCompletionsStreamParser implements StreamParser
{
    use ParsesSSE;

    public function __construct(protected PendingRequest $client) {}

    /**
     * @return Generator<int, StreamEvent, mixed, TurnResult>
     */
    public function parse(Request $request): Generator
    {
        $response = $this->sendRequest($request);
        $stream = $response->getBody();

        $messageId = EventID::generate();
        $reasoningId = null;
        $model = $request->model();
        $toolCalls = [];
        $finishReason = null;
        $usage = null;

        while (! $stream->eof()) {
            $data = $this->parseSSEDataLine($stream);

            if ($data === null) {
                continue;
            }

            $model = data_get($data, 'model', $model);

            if ($this->hasError($data)) {
                yield from $this->handleError($data, $request);

                continue;
            }

            $thinkingDelta = $this->extractThinkingDelta($data, $request);
            if ($thinkingDelta !== '') {
                $reasoningId ??= EventID::generate();

                yield new ThinkingEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $thinkingDelta,
                    reasoningId: $reasoningId,
                );

                continue;
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);
            }

            $content = $this->extractContentDelta($data);
            if ($content !== '') {
                yield new TextDeltaEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $content,
                    messageId: $messageId,
                );
            }

            $chunkUsage = $this->extractUsage($data);
            if ($chunkUsage instanceof Usage) {
                $usage = $chunkUsage;
            }

            $rawFinishReason = data_get($data, 'choices.0.finish_reason');
            if ($rawFinishReason !== null) {
                $finishReason = $this->mapFinishReason($data);

                if ($toolCalls !== []) {
                    foreach ($this->mapToolCalls($toolCalls) as $toolCall) {
                        yield new ToolCallEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            toolCall: $toolCall,
                            messageId: $messageId,
                        );
                    }
                }
            }
        }

        return new TurnResult(
            finishReason: $finishReason,
            usage: $usage,
            model: $model,
        );
    }

    abstract protected function sendRequest(Request $request): Response;

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
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return ToolCall[]
     */
    abstract protected function mapToolCalls(array $toolCalls): array;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function mapFinishReason(array $data): FinishReason;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function extractUsage(array $data): ?Usage;

    // ──────────────────────────────────────────────────────────
    //  Error hooks (override for providers with in-stream errors)
    // ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasError(array $data): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    protected function handleError(array $data, Request $request): Generator
    {
        yield from [];
    }
}
