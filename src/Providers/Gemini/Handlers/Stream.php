<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Gemini\Maps\FinishReasonMap;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Gemini\Maps\ToolMap;
use Prism\Prism\Providers\StreamHandler;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;

class Stream extends StreamHandler
{
    protected ?string $currentThoughtSignature = null;

    public function __construct(
        protected PendingRequest $client,
        #[\SensitiveParameter] protected string $apiKey,
    ) {
        parent::__construct($client);
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        $this->state->reset();
        $this->currentThoughtSignature = null;
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    protected function providerName(): string
    {
        return 'gemini';
    }

    /**
     * Override beforeProcessing to skip the default reset on depth 0 --
     * Gemini's handle() already resets the state.
     */
    protected function beforeProcessing(int $depth): void
    {
        // Gemini resets in handle() and in handleToolCalls().
        // Do not reset here.
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
        // Emit stream start event once
        if ($this->state->shouldEmitStreamStart()) {
            $this->state->withMessageId(EventID::generate());

            yield new StreamStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                model: data_get($data, 'modelVersion', 'unknown'),
                provider: 'gemini'
            );
            $this->state->markStreamStarted();
        }

        yield from $this->yieldStepStartIfNeeded();

        // Update usage data from each chunk
        $this->state->withUsage($this->extractUsage($data, $request));

        // Process tool calls
        if ($this->hasToolCalls($data)) {
            $existingIndices = array_keys($this->state->toolCalls());

            $toolCalls = $this->extractToolCalls($data, $this->state->toolCalls());
            foreach ($toolCalls as $index => $toolCall) {
                $this->state->addToolCall($index, $toolCall);
            }

            // Emit tool call events only for NEWLY added tool calls
            foreach ($this->state->toolCalls() as $index => $toolCallData) {
                if (! in_array($index, $existingIndices, true)) {
                    yield new ToolCallEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolCall: $this->mapToolCall($toolCallData),
                        messageId: $this->state->messageId()
                    );
                }
            }

            if ($this->mapFinishReason($data) === FinishReason::ToolCalls) {
                // Signal to finalize that tool calls are ready
                return;
            }

            return;
        }

        // Handle content from all parts
        $parts = data_get($data, 'candidates.0.content.parts', []);

        foreach ($parts as $part) {
            if (isset($part['thought']) && $part['thought'] === true) {
                $thinkingContent = $part['text'] ?? '';

                if ($thinkingContent !== '') {
                    if ($this->state->reasoningId() === '') {
                        $this->state->withReasoningId(EventID::generate());

                        yield new ThinkingStartEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            reasoningId: $this->state->reasoningId()
                        );
                    }

                    $this->state->appendThinking($thinkingContent);

                    yield new ThinkingEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        delta: $thinkingContent,
                        reasoningId: $this->state->reasoningId()
                    );
                }
            } elseif (isset($part['text']) && (! isset($part['thought']) || $part['thought'] === false)) {
                $content = $part['text'];

                if ($content !== '') {
                    yield from $this->yieldTextDelta($content);
                }
            }
        }

        $finishReason = $this->mapFinishReason($data);

        if ($finishReason !== FinishReason::Unknown) {
            if ($this->state->reasoningId() !== '') {
                yield new ThinkingCompleteEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    reasoningId: $this->state->reasoningId()
                );
            }

            yield from $this->yieldTextCompleteIfNeeded();

            $this->state->withFinishReason($finishReason);
            $this->state->withMetadata([
                'grounding_metadata' => $this->extractGroundingMetadata($data),
            ]);
        }
    }

    /**
     * Override finalize: ToolCallEvents already emitted in processChunk.
     * Also handles usage accumulation across tool turns.
     *
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
        return Arr::whereNotNull([
            'grounding_metadata' => $this->state->metadata()['grounding_metadata'] ?? null,
            'thoughtSummaries' => $this->state->thinkingSummaries() === [] ? null : $this->state->thinkingSummaries(),
        ]);
    }

    // ──────────────────────────────────────────────────────────
    //  Gemini-specific tool call handling
    // ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(
        Request $request,
        int $depth,
        array $data = []
    ): Generator {
        $mappedToolCalls = [];
        $hasPendingToolCalls = false;

        foreach ($this->state->toolCalls() as $toolCallData) {
            $mappedToolCalls[] = $this->mapToolCall($toolCallData);
        }

        $toolResults = [];
        yield from $this->callToolsAndYieldEvents($request->tools(), $mappedToolCalls, $this->state->messageId(), $toolResults, $hasPendingToolCalls);

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

            $request->addMessage(new AssistantMessage($this->state->currentText(), $mappedToolCalls));
            $request->addMessage(new ToolResultMessage($toolResults));
            $request->resetToolChoice();

            $depth++;
            if ($depth < $request->maxSteps()) {
                $previousUsage = $this->state->usage();
                $this->state->reset();
                $this->currentThoughtSignature = null;
                $nextResponse = $this->sendRequest($request);
                yield from $this->processStream($nextResponse, $request, $depth);

                if ($previousUsage instanceof Usage && $this->state->usage() instanceof Usage) {
                    $this->state->withUsage(new Usage(
                        promptTokens: $previousUsage->promptTokens + $this->state->usage()->promptTokens,
                        completionTokens: $previousUsage->completionTokens + $this->state->usage()->completionTokens,
                        cacheWriteInputTokens: ($previousUsage->cacheWriteInputTokens ?? 0) + ($this->state->usage()->cacheWriteInputTokens ?? 0),
                        cacheReadInputTokens: ($previousUsage->cacheReadInputTokens ?? 0) + ($this->state->usage()->cacheReadInputTokens ?? 0),
                        thoughtTokens: ($previousUsage->thoughtTokens ?? 0) + ($this->state->usage()->thoughtTokens ?? 0)
                    ));
                }
            } else {
                yield $this->emitStreamEndEvent();
            }
        }
    }

    // ──────────────────────────────────────────────────────────
    //  Gemini data extraction
    // ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        $parts = data_get($data, 'candidates.0.content.parts', []);
        $nextIndex = $toolCalls === [] ? 0 : max(array_keys($toolCalls)) + 1;

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                if (isset($part['thoughtSignature'])) {
                    $this->currentThoughtSignature = $part['thoughtSignature'];
                }

                $toolCalls[$nextIndex] = [
                    'id' => EventID::generate('gm'),
                    'name' => data_get($part, 'functionCall.name'),
                    'arguments' => data_get($part, 'functionCall.args', []),
                    'reasoningId' => $part['thoughtSignature'] ?? $this->currentThoughtSignature,
                ];
                $nextIndex++;
            }
        }

        return $toolCalls;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return ToolCall[]
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map($this->mapToolCall(...), $toolCalls);
    }

    /**
     * @param  array<string, mixed>  $toolCallData
     */
    protected function mapToolCall(array $toolCallData): ToolCall
    {
        $arguments = data_get($toolCallData, 'arguments', []);

        if (is_string($arguments) && $arguments !== '') {
            $decoded = json_decode($arguments, true);
            $arguments = json_last_error() === JSON_ERROR_NONE ? $decoded : ['input' => $arguments];
        }

        return new ToolCall(
            id: empty($toolCallData['id']) ? EventID::generate('gm') : $toolCallData['id'],
            name: data_get($toolCallData, 'name', 'unknown'),
            arguments: $arguments,
            reasoningId: data_get($toolCallData, 'reasoningId')
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        $parts = data_get($data, 'candidates.0.content.parts', []);

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data, Request $request): Usage
    {
        $providerOptions = $request->providerOptions();

        return new Usage(
            promptTokens: isset($providerOptions['cachedContentName'])
                ? (data_get($data, 'usageMetadata.promptTokenCount', 0) - data_get($data, 'usageMetadata.cachedContentTokenCount', 0))
                : data_get($data, 'usageMetadata.promptTokenCount', 0),
            completionTokens: data_get($data, 'usageMetadata.candidatesTokenCount', 0),
            cacheReadInputTokens: data_get($data, 'usageMetadata.cachedContentTokenCount'),
            thoughtTokens: data_get($data, 'usageMetadata.thoughtsTokenCount'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        $finishReason = data_get($data, 'candidates.0.finishReason');

        if (! $finishReason) {
            return FinishReason::Unknown;
        }

        $isToolCall = $this->hasToolCalls($data);

        return FinishReasonMap::map($finishReason, $isToolCall);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function extractGroundingMetadata(array $data): ?array
    {
        $groundingMetadata = data_get($data, 'candidates.0.groundingMetadata');

        if (! $groundingMetadata) {
            return null;
        }

        return $groundingMetadata;
    }

    protected function sendRequest(Request $request): Response
    {
        $providerOptions = $request->providerOptions();

        if ($request->tools() !== [] && $request->providerTools() !== []) {
            throw new PrismException('Use of provider tools with custom tools is not currently supported by Gemini.');
        }

        if ($request->tools() !== [] && ($providerOptions['searchGrounding'] ?? false)) {
            throw new PrismException('Use of search grounding with custom tools is not currently supported by Prism.');
        }

        $tools = [];

        if ($request->providerTools() !== []) {
            $tools = array_map(
                fn ($providerTool): array => [
                    $providerTool->type => $providerTool->options !== [] ? $providerTool->options : (object) [],
                ],
                $request->providerTools()
            );
        } elseif ($providerOptions['searchGrounding'] ?? false) {
            $tools = [
                [
                    'google_search' => (object) [],
                ],
            ];
        } elseif ($request->tools() !== []) {
            $tools = ['function_declarations' => ToolMap::map($request->tools())];
        }

        $thinkingConfig = $providerOptions['thinkingConfig'] ?? null;

        if (isset($providerOptions['thinkingBudget'])) {
            $thinkingConfig = [
                'thinkingBudget' => $providerOptions['thinkingBudget'],
                'includeThoughts' => true,
            ];
        }

        if (isset($providerOptions['thinkingLevel'])) {
            $thinkingConfig = [
                'thinkingLevel' => $providerOptions['thinkingLevel'],
                'includeThoughts' => true,
            ];
        }

        /** @var Response $response */
        $response = $this->client
            ->withOptions(['stream' => true])
            ->post(
                "{$request->model()}:streamGenerateContent?alt=sse",
                Arr::whereNotNull([
                    ...(new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'cachedContent' => $providerOptions['cachedContentName'] ?? null,
                    'generationConfig' => Arr::whereNotNull([
                        'temperature' => $request->temperature(),
                        'topP' => $request->topP(),
                        'maxOutputTokens' => $request->maxTokens(),
                        'thinkingConfig' => $thinkingConfig,
                    ]) ?: null,
                    'tools' => $tools !== [] ? $tools : null,
                    'tool_config' => $request->toolChoice() ? ToolChoiceMap::map($request->toolChoice()) : null,
                    'safetySettings' => $providerOptions['safetySettings'] ?? null,
                ])
            );

        return $response;
    }
}
