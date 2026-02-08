<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Anthropic\Maps\CitationsMapper;
use Prism\Prism\Providers\Anthropic\ValueObjects\AnthropicStreamState;
use Prism\Prism\Providers\StreamHandler;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\CitationEvent;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream extends StreamHandler
{
    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        $this->state->reset();
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    protected function providerName(): string
    {
        return 'anthropic';
    }

    protected function createState(): StreamState
    {
        return new AnthropicStreamState;
    }

    protected function anthropicState(): AnthropicStreamState
    {
        if (! $this->state instanceof AnthropicStreamState) {
            throw new \LogicException('Expected AnthropicStreamState.');
        }

        return $this->state;
    }

    /**
     * Anthropic uses event+data SSE format.
     *
     * @return array<string, mixed>|null
     */
    protected function parseNextChunk(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);
        $line = trim($line);

        if ($line === '' || $line === '0') {
            return null;
        }

        if (str_starts_with($line, 'event:')) {
            return $this->parseEventChunk($line, $stream);
        }

        if (str_starts_with($line, 'data:')) {
            return $this->parseDataChunk($line);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    protected function processChunk(array $data, Request $request): Generator
    {
        $streamEvent = $this->processEvent($data);

        if ($streamEvent instanceof Generator) {
            foreach ($streamEvent as $event) {
                yield $event;
            }
        } elseif ($streamEvent instanceof StreamEvent) {
            yield $streamEvent;
        }
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function finalize(Request $request, int $depth): Generator
    {
        if ($this->state->hasToolCalls()) {
            yield from $this->handleToolCalls($request, $depth);

            return;
        }

        $this->state->markStepFinished();
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        yield $this->emitStreamEndEvent();
    }

    /**
     * @return array<string, mixed>
     */
    protected function additionalStreamEndContent(): array
    {
        return [
            'thinking' => $this->anthropicState()->thinkingSummaries() === [] ? null : implode('', $this->anthropicState()->thinkingSummaries()),
            'thinking_signature' => $this->anthropicState()->currentThinkingSignature() === '' ? null : $this->anthropicState()->currentThinkingSignature(),
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  Event processing (Anthropic-specific SSE events)
    // ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $event
     * @return StreamEvent|Generator<StreamEvent>|null
     */
    protected function processEvent(array $event): StreamEvent|Generator|null
    {
        return match ($event['type'] ?? null) {
            'message_start' => $this->handleMessageStart($event),
            'content_block_start' => $this->handleContentBlockStart($event),
            'content_block_delta' => $this->handleContentBlockDelta($event),
            'content_block_stop' => $this->handleContentBlockStop($event),
            'message_delta' => $this->handleMessageDelta($event),
            'message_stop' => $this->handleMessageStop($event),
            'error' => $this->handleError($event),
            'ping' => null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     * @return Generator<StreamEvent>
     */
    protected function handleMessageStart(array $event): Generator
    {
        $message = $event['message'] ?? [];
        $this->state->withMessageId($message['id'] ?? EventID::generate());

        $usageData = $message['usage'] ?? [];
        if (! empty($usageData)) {
            $this->state->addUsage(new Usage(
                promptTokens: $usageData['input_tokens'] ?? 0,
                completionTokens: $usageData['output_tokens'] ?? 0,
                cacheWriteInputTokens: $usageData['cache_creation_input_tokens'] ?? null,
                cacheReadInputTokens: $usageData['cache_read_input_tokens'] ?? null
            ));
        }

        if ($this->state->shouldEmitStreamStart()) {
            $this->state->markStreamStarted();

            yield new StreamStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                model: $message['model'] ?? 'unknown',
                provider: 'anthropic'
            );
        }

        if ($this->state->shouldEmitStepStart()) {
            $this->state->markStepStarted();

            yield new StepStartEvent(
                id: EventID::generate(),
                timestamp: time()
            );
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleContentBlockStart(array $event): ?StreamEvent
    {
        $contentBlock = $event['content_block'] ?? [];
        $this->state->withBlockContext(
            $event['index'] ?? 0,
            $contentBlock['type'] ?? ''
        );

        return match ($this->state->currentBlockType()) {
            'text' => new TextStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId()
            ),
            'thinking' => $this->handleThinkingStart(),
            'tool_use' => $this->handleToolUseStart($contentBlock),
            'server_tool_use' => $this->handleProviderToolUseStart($contentBlock),
            'web_search_tool_result' => $this->handleProviderToolResultStart($contentBlock),
            'web_fetch_tool_result' => $this->handleProviderToolResultStart($contentBlock),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleContentBlockDelta(array $event): ?StreamEvent
    {
        $delta = $event['delta'] ?? [];
        $deltaType = $delta['type'] ?? null;

        return match ([$this->state->currentBlockType(), $deltaType]) {
            ['text', 'text_delta'] => $this->handleTextDelta($delta),
            ['text', 'citations_delta'] => $this->handleCitationsDelta($delta),
            ['thinking', 'thinking_delta'] => $this->handleThinkingDelta($delta),
            ['thinking', 'signature_delta'] => $this->handleSignatureDelta($delta),
            ['tool_use', 'input_json_delta'] => $this->handleToolInputDelta($delta),
            ['server_tool_use', 'input_json_delta'] => $this->handleProviderToolInputDelta($delta),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleContentBlockStop(array $event): ?StreamEvent
    {
        $result = match ($this->state->currentBlockType()) {
            'text' => $this->handleTextComplete(),
            'thinking' => new ThinkingCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                reasoningId: $this->state->reasoningId()
            ),
            'tool_use' => $this->handleToolUseComplete(),
            'server_tool_use' => $this->handleProviderToolUseComplete(),
            default => null,
        };

        $this->state->resetBlockContext();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleMessageDelta(array $event): null
    {
        $usageData = $event['usage'] ?? [];
        if (! empty($usageData) && $this->state->usage() instanceof Usage && isset($usageData['output_tokens'])) {
            $currentUsage = $this->state->usage();
            $this->state->withUsage(new Usage(
                promptTokens: $currentUsage->promptTokens,
                completionTokens: $usageData['output_tokens'],
                cacheWriteInputTokens: $currentUsage->cacheWriteInputTokens,
                cacheReadInputTokens: $currentUsage->cacheReadInputTokens
            ));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleMessageStop(array $event): null
    {
        if (! $this->state->finishReason() instanceof FinishReason) {
            $this->state->withFinishReason(FinishReason::Stop);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleError(array $event): ErrorEvent
    {
        return new ErrorEvent(
            id: EventID::generate(),
            timestamp: time(),
            errorType: $event['error']['type'] ?? 'unknown_error',
            message: $event['error']['message'] ?? 'Unknown error occurred',
            recoverable: true
        );
    }

    protected function handleThinkingStart(): ThinkingStartEvent
    {
        $this->state->withReasoningId(EventID::generate());

        return new ThinkingStartEvent(
            id: EventID::generate(),
            timestamp: time(),
            reasoningId: $this->state->reasoningId()
        );
    }

    protected function handleTextComplete(): TextCompleteEvent
    {
        $this->state->markTextCompleted();

        return new TextCompleteEvent(
            id: EventID::generate(),
            timestamp: time(),
            messageId: $this->state->messageId()
        );
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleTextDelta(array $delta): ?TextDeltaEvent
    {
        $text = $delta['text'] ?? '';

        if ($text === '') {
            return null;
        }

        $this->state->appendText($text);

        return new TextDeltaEvent(
            id: EventID::generate(),
            timestamp: time(),
            delta: $text,
            messageId: $this->state->messageId()
        );
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleCitationsDelta(array $delta): ?CitationEvent
    {
        $citationData = $delta['citation'] ?? null;

        if ($citationData === null) {
            return null;
        }

        $citation = CitationsMapper::mapCitationFromAnthropic($citationData);

        $messagePartWithCitations = new MessagePartWithCitations(
            outputText: $this->state->currentText(),
            citations: [$citation]
        );

        $this->state->addCitation($messagePartWithCitations);

        return new CitationEvent(
            id: EventID::generate(),
            timestamp: time(),
            citation: $citation,
            messageId: $this->state->messageId(),
            blockIndex: $this->state->currentBlockIndex()
        );
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleThinkingDelta(array $delta): ?ThinkingEvent
    {
        $thinking = $delta['thinking'] ?? '';

        if ($thinking === '') {
            return null;
        }

        $this->state->appendThinking($thinking);

        return new ThinkingEvent(
            id: EventID::generate(),
            timestamp: time(),
            delta: $thinking,
            reasoningId: $this->state->reasoningId()
        );
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleSignatureDelta(array $delta): null
    {
        $this->anthropicState()->appendThinkingSignature($delta['signature'] ?? '');

        return null;
    }

    /**
     * @param  array<string, mixed>  $contentBlock
     */
    protected function handleToolUseStart(array $contentBlock): null
    {
        if ($this->state->currentBlockIndex() !== null) {
            $this->state->addToolCall($this->state->currentBlockIndex(), [
                'id' => $contentBlock['id'] ?? EventID::generate(),
                'name' => $contentBlock['name'] ?? 'unknown',
                'input' => '',
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleToolInputDelta(array $delta): ?ToolCallDeltaEvent
    {
        $partialJson = $delta['partial_json'] ?? '';

        if ($this->state->currentBlockIndex() !== null && isset($this->state->toolCalls()[$this->state->currentBlockIndex()])) {
            $this->state->appendToolCallInput($this->state->currentBlockIndex(), $partialJson);

            $toolCall = $this->state->toolCalls()[$this->state->currentBlockIndex()];

            return new ToolCallDeltaEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolId: $toolCall['id'],
                toolName: $toolCall['name'],
                delta: $partialJson,
                messageId: $this->state->messageId()
            );
        }

        return null;
    }

    protected function handleToolUseComplete(): ?ToolCallEvent
    {
        if ($this->state->currentBlockIndex() === null || ! isset($this->state->toolCalls()[$this->state->currentBlockIndex()])) {
            return null;
        }

        $toolCall = $this->state->toolCalls()[$this->state->currentBlockIndex()];

        return new ToolCallEvent(
            id: EventID::generate(),
            timestamp: time(),
            toolCall: new ToolCall(
                id: $toolCall['id'],
                name: $toolCall['name'],
                arguments: $this->parseToolInput($toolCall['input']),
                reasoningId: $this->state->reasoningId() !== '' ? $this->state->reasoningId() : null
            ),
            messageId: $this->state->messageId()
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return ToolCall[]
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCallData): ToolCall => new ToolCall(
            id: $toolCallData['id'],
            name: $toolCallData['name'],
            arguments: $this->parseToolInput($toolCallData['input']),
        ), $toolCalls);
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(Request $request, int $depth): Generator
    {
        $toolCalls = array_map(fn (array $toolCallData): ToolCall => new ToolCall(
            id: $toolCallData['id'],
            name: $toolCallData['name'],
            arguments: $this->parseToolInput($toolCallData['input']),
        ), $this->state->toolCalls());

        $toolResults = [];
        $hasPendingToolCalls = false;
        yield from $this->callToolsAndYieldEvents($request->tools(), $toolCalls, $this->state->messageId(), $toolResults, $hasPendingToolCalls);

        if ($hasPendingToolCalls) {
            $this->state->markStepFinished();
            yield from $this->yieldToolCallsFinishEvents($this->state);

            return;
        }

        if ($toolResults !== []) {
            $this->state->markStepFinished();
            yield new StepFinishEvent(
                id: EventID::generate(),
                timestamp: time()
            );

            $request->addMessage(new AssistantMessage(
                content: $this->state->currentText(),
                toolCalls: $toolCalls,
                additionalContent: in_array($this->state->currentThinking(), ['', '0'], true) ? [] : [
                    'thinking' => $this->state->currentThinking(),
                    'thinking_signature' => $this->anthropicState()->currentThinkingSignature(),
                ]
            ));

            $request->addMessage(new ToolResultMessage($toolResults));
            $request->resetToolChoice();

            $depth++;
            if ($depth < $request->maxSteps()) {
                $this->state->reset();
                $nextResponse = $this->sendRequest($request);
                yield from $this->processStream($nextResponse, $request, $depth);
            } else {
                yield $this->emitStreamEndEvent();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseToolInput(mixed $input): array
    {
        if (is_string($input) && json_validate($input)) {
            return json_decode($input, true);
        }

        if (is_string($input) && $input !== '') {
            return ['input' => $input];
        }

        return [];
    }

    // ──────────────────────────────────────────────────────────
    //  Provider tool handling
    // ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $contentBlock
     */
    protected function handleProviderToolUseStart(array $contentBlock): ProviderToolEvent
    {
        if ($this->state->currentBlockIndex() !== null) {
            $this->anthropicState()->addProviderToolCall($this->state->currentBlockIndex(), [
                'type' => $contentBlock['type'] ?? 'server_tool_use',
                'id' => $contentBlock['id'] ?? EventID::generate(),
                'name' => $contentBlock['name'] ?? 'unknown',
                'input' => '',
            ]);
        }

        return new ProviderToolEvent(
            id: EventID::generate(),
            timestamp: time(),
            toolType: $contentBlock['name'] ?? 'unknown',
            status: 'started',
            itemId: $contentBlock['id'],
            data: $contentBlock,
        );
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function handleProviderToolInputDelta(array $delta): null
    {
        $partialJson = $delta['partial_json'] ?? '';

        if ($this->state->currentBlockIndex() !== null && isset($this->anthropicState()->providerToolCalls()[$this->state->currentBlockIndex()])) {
            $this->anthropicState()->appendProviderToolCallInput($this->state->currentBlockIndex(), $partialJson);
        }

        return null;
    }

    protected function handleProviderToolUseComplete(): ?ProviderToolEvent
    {
        if ($this->state->currentBlockIndex() === null || ! isset($this->anthropicState()->providerToolCalls()[$this->state->currentBlockIndex()])) {
            return null;
        }

        $providerToolCall = $this->anthropicState()->providerToolCalls()[$this->state->currentBlockIndex()];

        return new ProviderToolEvent(
            id: EventID::generate(),
            timestamp: time(),
            toolType: $providerToolCall['name'],
            status: 'completed',
            itemId: $providerToolCall['id'],
            data: $providerToolCall,
        );
    }

    /**
     * @param  array<string, mixed>  $contentBlock
     */
    protected function handleProviderToolResultStart(array $contentBlock): ?ProviderToolEvent
    {
        if ($this->state->currentBlockIndex() !== null) {
            $this->anthropicState()->addProviderToolResult($this->state->currentBlockIndex(), $contentBlock);
        }

        return new ProviderToolEvent(
            id: EventID::generate(),
            timestamp: time(),
            toolType: $contentBlock['type'],
            status: 'result_received',
            itemId: $contentBlock['tool_use_id'] ?? EventID::generate(),
            data: $contentBlock,
        );
    }

    // ──────────────────────────────────────────────────────────
    //  SSE parsing helpers
    // ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    protected function parseEventChunk(string $line, StreamInterface $stream): ?array
    {
        $eventType = trim(substr($line, strlen('event:')));

        if ($eventType === 'ping') {
            return ['type' => 'ping'];
        }

        $dataLine = $this->readLine($stream);
        $dataLine = trim($dataLine);

        if ($dataLine === '' || $dataLine === '0' || ! str_starts_with($dataLine, 'data:')) {
            return ['type' => $eventType];
        }

        return $this->parseJsonData($dataLine, $eventType);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseDataChunk(string $line): ?array
    {
        $jsonData = trim(substr($line, strlen('data:')));

        if ($jsonData === '' || $jsonData === '0' || str_contains($jsonData, 'DONE')) {
            return null;
        }

        return $this->parseJsonData($jsonData);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseJsonData(string $jsonDataLine, ?string $eventType = null): ?array
    {
        $jsonData = trim(str_starts_with($jsonDataLine, 'data:')
            ? substr($jsonDataLine, strlen('data:'))
            : $jsonDataLine);

        if ($jsonData === '' || $jsonData === '0') {
            return $eventType ? ['type' => $eventType] : null;
        }

        try {
            $data = json_decode($jsonData, true, flags: JSON_THROW_ON_ERROR);

            if ($eventType) {
                $data['type'] = $eventType;
            }

            return $data;
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Anthropic', $e);
        }
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this->client
            ->withOptions(['stream' => true])
            ->post('messages', Arr::whereNotNull([
                'stream' => true,
                ...Text::buildHttpRequestPayload($request),
            ]));

        return $response;
    }
}
