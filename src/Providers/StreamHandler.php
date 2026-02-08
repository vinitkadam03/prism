<?php

declare(strict_types=1);

namespace Prism\Prism\Providers;

use Generator;
use Throwable;
use Illuminate\Support\Str;
use Prism\Prism\Text\Request;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Concerns\CallsTools;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\StreamInterface;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\ValueObjects\ToolCall;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;

abstract class StreamHandler
{
    use CallsTools;

    protected StreamState $state;

    public function __construct(protected PendingRequest $client)
    {
        $this->state = $this->createState();
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * The canonical streaming loop shared by all providers.
     *
     * @throws PrismException
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        $this->beforeProcessing($depth);

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextChunk($response->getBody());

            if ($data === null) {
                continue;
            }

            // Use foreach+yield instead of yield from to avoid generator key
            // collisions. Sub-generators start keys from 0 and yield from
            // propagates those keys, causing iterator_to_array (used by
            // collect()) to overwrite events sharing the same integer key.
            foreach ($this->processChunk($data, $request) as $event) {
                yield $event;
            }
        }

        foreach ($this->finalize($request, $depth) as $event) {
            yield $event;
        }
    }

    /**
     * Emit completion events, or process tool calls and recurse.
     *
     * @return Generator<StreamEvent>
     * @throws PrismException
     */
    protected function finalize(Request $request, int $depth): Generator
    {
        // Safety net: ensure lifecycle events are emitted even if processChunk
        // didn't emit them (e.g. stream ended without explicit finish_reason).
        // These helpers are idempotent -- they check state flags before emitting.
        yield from $this->yieldTextCompleteIfNeeded();
        yield from $this->yieldThinkingCompleteIfNeeded();

        if ($this->state->hasToolCalls()) {
            if ($depth >= $request->maxSteps()) {
                throw new PrismException('Maximum tool call chain depth exceeded. Increase maxSteps to allow more tool call iterations.');
            }

            yield from $this->processToolCallResults(
                $request,
                $this->state->currentText(),
                $this->mapToolCalls($this->state->toolCalls()),
                $depth,
            );

            return;
        }

        $this->state->markStepFinished();
        yield from $this->yieldStepFinish();
        yield $this->emitStreamEndEvent();
    }

    /**
     * Execute tools, yield results, and recurse into the next turn.
     *
     * @param  ToolCall[]  $mappedToolCalls
     * @return Generator<StreamEvent>
     */
    protected function processToolCallResults(
        Request $request,
        string $text,
        array $mappedToolCalls,
        int $depth,
    ): Generator {
        foreach ($mappedToolCalls as $toolCall) {
            yield new ToolCallEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolCall: $toolCall,
                messageId: $this->state->messageId(),
            );
        }

        $toolResults = [];
        $hasPendingToolCalls = false;

        yield from $this->callToolsAndYieldEvents(
            $request->tools(),
            $mappedToolCalls,
            $this->state->messageId(),
            $toolResults,
            $hasPendingToolCalls,
        );

        // Client-executed tools are pending -- stop here.
        if ($hasPendingToolCalls) {
            $this->state->markStepFinished();
            yield from $this->yieldToolCallsFinishEvents($this->state);

            return;
        }

        yield from $this->yieldStepFinish();

        // Prepare the conversation for the next turn.
        $request->addMessage(new AssistantMessage(
            content: $text,
            toolCalls: $mappedToolCalls,
            additionalContent: $this->toolCallAdditionalContent(),
        ));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $depth++;

        if ($depth < $request->maxSteps()) {
            $this->resetStateForNextTurn();
            $nextResponse = $this->sendRequest($request);
            yield from $this->processStream($nextResponse, $request, $depth);
        } else {
            yield $this->emitStreamEndEvent();
        }
    }

    // ──────────────────────────────────────────────────────────
    //  Abstract methods — every provider must implement these
    // ──────────────────────────────────────────────────────────

    /**
     * Parse the next chunk from the raw stream.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function parseNextChunk(StreamInterface $stream): ?array;

    /**
     * Process a single parsed chunk, yielding stream events.
     *
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    abstract protected function processChunk(array $data, Request $request): Generator;

    /**
     * Build and send the HTTP request to the provider.
     */
    abstract protected function sendRequest(Request $request): Response;

    /**
     * The provider name used in StreamStartEvent.
     */
    abstract protected function providerName(): string;

    // ──────────────────────────────────────────────────────────
    //  Factory hooks — override as needed
    // ──────────────────────────────────────────────────────────

    /**
     * Create the StreamState instance. Override for custom state classes.
     */
    protected function createState(): StreamState
    {
        return new StreamState;
    }

    /**
     * Called before processing begins. Resets state on depth 0.
     */
    protected function beforeProcessing(int $depth): void
    {
        if ($depth === 0) {
            $this->state->reset();
        }
    }

    /**
     * Reset state between tool call turns.
     * Uses reset() which clears tool calls, text, thinking, etc. but preserves
     * streamStarted, usage, and finishReason.
     */
    protected function resetStateForNextTurn(): void
    {
        $this->state->reset();
        $this->state->withMessageId(EventID::generate());
    }

    /**
     * @return array<string, mixed>
     */
    protected function additionalStreamEndContent(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function toolCallAdditionalContent(): array
    {
        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return ToolCall[]
     */
    abstract protected function mapToolCalls(array $toolCalls): array;

    // ──────────────────────────────────────────────────────────
    //  Lifecycle events
    // ──────────────────────────────────────────────────────────

    /**
     * @return Generator<StreamStartEvent>
     */
    protected function yieldStreamStartIfNeeded(string $model): Generator
    {
        if ($this->state->shouldEmitStreamStart()) {
            $this->state
                ->withMessageId(EventID::generate())
                ->markStreamStarted();

            yield new StreamStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                model: $model,
                provider: $this->providerName(),
            );
        }
    }

    /**
     * @return Generator<StepStartEvent>
     */
    protected function yieldStepStartIfNeeded(): Generator
    {
        if ($this->state->shouldEmitStepStart()) {
            $this->state->markStepStarted();

            yield new StepStartEvent(
                id: EventID::generate(),
                timestamp: time(),
            );
        }
    }

    /**
     * @return Generator<ThinkingStartEvent|ThinkingEvent>
     */
    protected function yieldThinkingDelta(string $delta): Generator
    {
        if ($this->state->shouldEmitThinkingStart()) {
            $this->state
                ->withReasoningId(EventID::generate())
                ->markThinkingStarted();

            yield new ThinkingStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                reasoningId: $this->state->reasoningId(),
            );
        }

        $this->state->appendThinking($delta);

        yield new ThinkingEvent(
            id: EventID::generate(),
            timestamp: time(),
            delta: $delta,
            reasoningId: $this->state->reasoningId(),
        );
    }

    /**
     * @return Generator<ThinkingCompleteEvent>
     */
    protected function yieldThinkingCompleteIfNeeded(): Generator
    {
        if ($this->state->hasThinkingStarted()) {
            $this->state->markThinkingCompleted();

            yield new ThinkingCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                reasoningId: $this->state->reasoningId(),
            );
        }
    }

    /**
     * @return Generator<TextStartEvent|TextDeltaEvent>
     */
    protected function yieldTextDelta(string $delta): Generator
    {
        if ($this->state->shouldEmitTextStart()) {
            $this->state->markTextStarted();

            yield new TextStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId(),
            );
        }

        $this->state->appendText($delta);

        yield new TextDeltaEvent(
            id: EventID::generate(),
            timestamp: time(),
            delta: $delta,
            messageId: $this->state->messageId(),
        );
    }

    /**
     * @return Generator<TextCompleteEvent>
     */
    protected function yieldTextCompleteIfNeeded(): Generator
    {
        if ($this->state->hasTextStarted()) {
            $this->state->markTextCompleted();

            yield new TextCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId(),
            );
        }
    }

    /**
     * @return Generator<StepFinishEvent>
     */
    protected function yieldStepFinish(): Generator
    {
        $this->state->markStepFinished();

        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time(),
        );
    }

    protected function emitStreamEndEvent(): StreamEndEvent
    {
        return new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $this->state->finishReason() ?? FinishReason::Stop,
            usage: $this->state->usage(),
            citations: $this->state->citations() !== [] ? $this->state->citations() : null,
            additionalContent: $this->additionalStreamEndContent(),
        );
    }

    // ──────────────────────────────────────────────────────────
    //  Shared utilities
    // ──────────────────────────────────────────────────────────

    /**
     * Read a single line from the stream (byte-by-byte until newline).
     */
    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            $byte = $stream->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    /**
     * Parse a standard SSE data line (data: {...}).
     *
     * @return array<string, mixed>|null
     *
     * @throws PrismStreamDecodeException
     */
    protected function parseSSEDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data:')));

        if ($line === '' || $line === '[DONE]' || Str::contains($line, '[DONE]')) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException($this->providerName(), $e);
        }
    }
}
