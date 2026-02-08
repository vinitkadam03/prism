<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsTools;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Providers\StreamHandler;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;

class Stream extends StreamHandler
{
    use BuildsTools;
    use ProcessRateLimits;

    /** @var array<int, array<string, mixed>> */
    protected array $reasoningItems = [];

    protected function providerName(): string
    {
        return 'openai';
    }

    /**
     * Reset state before processing. OpenAI always resets per-stream.
     */
    protected function beforeProcessing(int $depth): void
    {
        $this->state->reset()->withMessageId(EventID::generate());
        $this->reasoningItems = [];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseNextChunk(StreamInterface $stream): ?array
    {
        return $this->parseSSEDataLine($stream);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    protected function processChunk(array $data, Request $request): Generator
    {
        if ($data['type'] === 'error') {
            $code = data_get($data, 'error.code', 'unknown_error');
            $message = data_get($data, 'error.message', 'No error message provided');

            if ($code === 'rate_limit_exceeded') {
                throw new PrismRateLimitedException([]);
            }

            throw new PrismException(sprintf(
                'Sending to model %s failed. Code: %s. Message: %s',
                $request->model(),
                $code,
                $message
            ));
        }

        if ($data['type'] === 'response.created' && $this->state->shouldEmitStreamStart()) {
            yield new StreamStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                model: $data['response']['model'] ?? 'unknown',
                provider: 'openai',
            );

            $this->state->markStreamStarted();

            return;
        }

        yield from $this->yieldStepStartIfNeeded();

        if ($this->hasReasoningSummaryDelta($data)) {
            $reasoningDelta = $this->extractReasoningSummaryDelta($data);

            if ($reasoningDelta !== '') {
                if ($this->state->reasoningId() === '') {
                    $this->state->withReasoningId(EventID::generate());
                    yield new ThinkingStartEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        reasoningId: $this->state->reasoningId()
                    );
                }

                $this->state->appendThinking($reasoningDelta);

                yield new ThinkingEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    delta: $reasoningDelta,
                    reasoningId: $this->state->reasoningId()
                );
            }

            return;
        }

        if (data_get($data, 'type') === 'response.output_item.done') {
            $item = data_get($data, 'item', []);
            $itemType = data_get($item, 'type', '');

            if ($itemType !== 'function_call' && str_ends_with((string) $itemType, '_call')) {
                yield new ProviderToolEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolType: $itemType,
                    status: 'completed',
                    itemId: data_get($item, 'id', ''),
                    data: $item
                );

                return;
            }
        }

        if ($this->hasReasoningItems($data)) {
            $this->reasoningItems = $this->extractReasoningItems($data, $this->reasoningItems);

            if ($this->state->reasoningId() !== '') {
                yield new ThinkingCompleteEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    reasoningId: $this->state->reasoningId()
                );
                $this->state->withReasoningId('');
            }

            return;
        }

        if ($this->hasToolCalls($data)) {
            $toolCallDeltaEvent = $this->extractToolCalls($data, $this->reasoningItems);

            if ($toolCallDeltaEvent instanceof ToolCallDeltaEvent) {
                yield $toolCallDeltaEvent;
            }

            if ($this->isToolCallComplete($data)) {
                $completedToolCall = $this->getCompletedToolCall($data);
                if ($completedToolCall instanceof ToolCall) {
                    yield new ToolCallEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolCall: $completedToolCall,
                        messageId: $this->state->messageId()
                    );
                }
            }

            return;
        }

        $type = (string) data_get($data, 'type', '');

        if (str_starts_with($type, 'response.') && str_contains($type, '_call.')) {
            $parts = explode('.', $type, 3);

            if (count($parts) === 3 && str_ends_with($parts[1], '_call')) {
                $toolType = $parts[1];
                $status = $parts[2];

                yield new ProviderToolEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolType: $toolType,
                    status: $status,
                    itemId: data_get($data, 'item_id', ''),
                    data: $data
                );

                return;
            }
        }

        $content = $this->extractOutputTextDelta($data);

        if ($content !== '') {
            yield from $this->yieldTextDelta($content);
        }

        if (data_get($data, 'type') === 'response.output_text.done' && $this->state->hasTextStarted()) {
            yield from $this->yieldTextCompleteIfNeeded();
        }

        if (data_get($data, 'type') === 'response.completed') {
            $this->state->withFinishReason($this->mapFinishReasonFromData($data));
            $this->state->addUsage(new Usage(
                promptTokens: data_get($data, 'response.usage.input_tokens'),
                completionTokens: data_get($data, 'response.usage.output_tokens'),
                cacheReadInputTokens: data_get($data, 'response.usage.input_tokens_details.cached_tokens'),
                thoughtTokens: data_get($data, 'response.usage.output_tokens_details.reasoning_tokens')
            ));
            $this->state->withMetadata(['response_id' => data_get($data, 'response.id')]);
        }
    }

    /**
     * Override finalize: ToolCallEvents are already emitted in processChunk.
     * Skip processToolCallResults to avoid double-emission.
     *
     * @return Generator<StreamEvent>
     */
    protected function finalize(Request $request, int $depth): Generator
    {
        if ($this->state->hasToolCalls()) {
            yield from $this->handleToolCalls($request, $depth);

            return;
        }

        yield from $this->yieldStepFinish();
        yield $this->emitStreamEndEvent();
    }

    /**
     * @return array<string, mixed>
     */
    protected function additionalStreamEndContent(): array
    {
        return Arr::whereNotNull([
            'response_id' => $this->state->metadata()['response_id'] ?? null,
            'reasoningSummaries' => $this->state->thinkingSummaries() === [] ? null : $this->state->thinkingSummaries(),
        ]);
    }

    // ──────────────────────────────────────────────────────────
    //  OpenAI-specific tool call handling
    // ──────────────────────────────────────────────────────────

    /**
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(Request $request, int $depth): Generator
    {
        $mappedToolCalls = $this->mapToolCalls($this->state->toolCalls());
        $toolResults = [];
        $hasPendingToolCalls = false;
        yield from $this->callToolsAndYieldEvents($request->tools(), $mappedToolCalls, $this->state->messageId(), $toolResults, $hasPendingToolCalls);

        if ($hasPendingToolCalls) {
            $this->state->markStepFinished();
            yield from $this->yieldToolCallsFinishEvents($this->state);

            return;
        }

        $this->state->markStepFinished();
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        $request->addMessage(new AssistantMessage($this->state->currentText(), $mappedToolCalls));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $depth++;

        if ($depth < $request->maxSteps()) {
            $nextResponse = $this->sendRequest($request);

            yield from $this->processStream($nextResponse, $request, $depth);
        } else {
            yield $this->emitStreamEndEvent();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return ToolCall[]
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return collect($toolCalls)
            ->map(fn ($toolCall): ToolCall => new ToolCall(
                id: data_get($toolCall, 'id'),
                name: data_get($toolCall, 'name'),
                arguments: data_get($toolCall, 'arguments'),
                resultId: data_get($toolCall, 'call_id'),
                reasoningId: data_get($toolCall, 'reasoning_id'),
                reasoningSummary: data_get($toolCall, 'reasoning_summary', []),
            ))
            ->all();
    }

    // ──────────────────────────────────────────────────────────
    //  OpenAI Responses API event extraction
    // ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $reasoningItems
     */
    protected function extractToolCalls(array $data, array $reasoningItems = []): ?ToolCallDeltaEvent
    {
        $type = data_get($data, 'type', '');

        if ($type === 'response.output_item.added' && data_get($data, 'item.type') === 'function_call') {
            $index = (int) data_get($data, 'output_index', count($this->state->toolCalls()));

            $toolCall = [
                'id' => data_get($data, 'item.id'),
                'call_id' => data_get($data, 'item.call_id'),
                'name' => data_get($data, 'item.name'),
                'arguments' => '',
            ];

            if ($reasoningItems !== []) {
                $latestReasoning = end($reasoningItems);
                $toolCall['reasoning_id'] = $latestReasoning['id'];
                $toolCall['reasoning_summary'] = $latestReasoning['summary'] ?? [];
            }

            $this->state->addToolCall($index, $toolCall);

            return null;
        }

        if ($type === 'response.function_call_arguments.delta') {
            $callId = data_get($data, 'item_id');
            $delta = data_get($data, 'delta', '');

            $toolCalls = $this->state->toolCalls();
            foreach ($toolCalls as $index => $call) {
                if (($call['id'] ?? null) === $callId) {
                    $currentArgs = $call['arguments'] ?? '';
                    $this->state->updateToolCall($index, ['arguments' => $currentArgs.$delta]);

                    return new ToolCallDeltaEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolId: $call['id'],
                        toolName: $call['name'],
                        delta: $delta,
                        messageId: $this->state->messageId()
                    );
                }
            }
        }

        if ($type === 'response.function_call_arguments.done') {
            $callId = data_get($data, 'item_id');
            $arguments = data_get($data, 'arguments', '');

            $toolCalls = $this->state->toolCalls();
            foreach ($toolCalls as $index => $call) {
                if (($call['id'] ?? null) === $callId) {
                    if ($arguments !== '') {
                        $this->state->updateToolCall($index, ['arguments' => $arguments]);
                    }
                    break;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        $type = data_get($data, 'type', '');

        if (data_get($data, 'item.type') === 'function_call') {
            return true;
        }

        return in_array($type, [
            'response.function_call_arguments.delta',
            'response.function_call_arguments.done',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasReasoningItems(array $data): bool
    {
        $type = data_get($data, 'type', '');

        return $type === 'response.output_item.done' && data_get($data, 'item.type') === 'reasoning';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $reasoningItems
     * @return array<int, array<string, mixed>>
     */
    protected function extractReasoningItems(array $data, array $reasoningItems): array
    {
        if (data_get($data, 'type') === 'response.output_item.done' && data_get($data, 'item.type') === 'reasoning') {
            $index = (int) data_get($data, 'output_index', count($reasoningItems));

            $reasoningItems[$index] = [
                'id' => data_get($data, 'item.id'),
                'summary' => data_get($data, 'item.summary', []),
            ];
        }

        return $reasoningItems;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReasonFromData(array $data): FinishReason
    {
        $eventType = Str::after(data_get($data, 'type'), 'response.');
        $lastOutputType = data_get($data, 'response.output.{last}.type');

        return FinishReasonMap::map($eventType, $lastOutputType);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function isToolCallComplete(array $data): bool
    {
        return data_get($data, 'type') === 'response.function_call_arguments.done';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function getCompletedToolCall(array $data): ?ToolCall
    {
        $callId = data_get($data, 'item_id');

        foreach ($this->state->toolCalls() as $call) {
            if (($call['id'] ?? null) === $callId) {
                return new ToolCall(
                    id: $call['id'],
                    name: $call['name'],
                    arguments: $call['arguments'] ?? '',
                    resultId: $call['call_id'] ?? null,
                    reasoningId: $call['reasoning_id'] ?? null,
                    reasoningSummary: $call['reasoning_summary'] ?? []
                );
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasReasoningSummaryDelta(array $data): bool
    {
        return data_get($data, 'type', '') === 'response.reasoning_summary_text.delta';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractReasoningSummaryDelta(array $data): string
    {
        if (data_get($data, 'type') === 'response.reasoning_summary_text.delta') {
            return (string) data_get($data, 'delta', '');
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractOutputTextDelta(array $data): string
    {
        if (data_get($data, 'type') === 'response.output_text.delta') {
            return (string) data_get($data, 'delta', '');
        }

        return '';
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'responses',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                ], Arr::whereNotNull([
                    'max_output_tokens' => $request->maxTokens(),
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'metadata' => $request->providerOptions('metadata'),
                    'tools' => $this->buildTools($request),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                    'parallel_tool_calls' => $request->providerOptions('parallel_tool_calls'),
                    'previous_response_id' => $request->providerOptions('previous_response_id'),
                    'service_tier' => $request->providerOptions('service_tier'),
                    'text' => $request->providerOptions('text_verbosity') ? [
                        'verbosity' => $request->providerOptions('text_verbosity'),
                    ] : null,
                    'truncation' => $request->providerOptions('truncation'),
                    'reasoning' => $request->providerOptions('reasoning'),
                ]))
            );

        return $response;
    }
}
