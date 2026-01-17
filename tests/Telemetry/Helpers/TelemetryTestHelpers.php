<?php

declare(strict_types=1);

namespace Tests\Telemetry\Helpers;

use Illuminate\Support\Collection;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationCompleted;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationStarted;
use Prism\Prism\Telemetry\Events\StreamingCompleted;
use Prism\Prism\Telemetry\Events\StreamingStarted;
use Prism\Prism\Telemetry\Events\StreamStepCompleted;
use Prism\Prism\Telemetry\Events\StreamStepStarted;
use Prism\Prism\Telemetry\Events\StructuredOutputCompleted;
use Prism\Prism\Telemetry\Events\StructuredOutputStarted;
use Prism\Prism\Telemetry\Events\TextGenerationCompleted;
use Prism\Prism\Telemetry\Events\TextGenerationStarted;
use Prism\Prism\Telemetry\Events\TextStepCompleted;
use Prism\Prism\Telemetry\Events\TextStepStarted;
use Prism\Prism\Telemetry\Events\ToolCallCompleted;
use Prism\Prism\Telemetry\Events\ToolCallStarted;
use Prism\Prism\Telemetry\SpanData;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\Step as TextStep;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

/**
 * Helper functions for creating test telemetry events and span data.
 */
class TelemetryTestHelpers
{
    public static function createTextRequest(
        string $model = 'gpt-4',
        string $provider = 'openai',
        ?string $prompt = 'Hello',
        ?float $temperature = 0.7,
        ?int $maxTokens = 100,
        ?float $topP = 1.0,
        int $maxSteps = 1,
        array $systemPrompts = [],
        array $messages = [],
        array $tools = [],
    ): TextRequest {
        return new TextRequest(
            model: $model,
            providerKey: $provider,
            systemPrompts: $systemPrompts,
            prompt: $prompt,
            messages: $messages,
            maxSteps: $maxSteps,
            maxTokens: $maxTokens,
            temperature: $temperature,
            topP: $topP,
            tools: $tools,
            clientOptions: [],
            clientRetry: [3],
            toolChoice: null,
        );
    }

    public static function createTextResponse(
        string $text = 'Hello there!',
        int $promptTokens = 10,
        int $completionTokens = 5,
        string $model = 'gpt-4',
        string $responseId = 'resp-123',
        ?string $serviceTier = null,
        array $toolCalls = [],
        FinishReason $finishReason = FinishReason::Stop,
    ): TextResponse {
        return new TextResponse(
            steps: new Collection,
            text: $text,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            toolResults: [],
            usage: new Usage($promptTokens, $completionTokens),
            meta: new Meta(id: $responseId, model: $model, serviceTier: $serviceTier),
            messages: new Collection,
        );
    }

    public static function createTextGenerationStarted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        ?TextRequest $request = null,
        ?int $timeNanos = null,
    ): TextGenerationStarted {
        return new TextGenerationStarted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: $request ?? self::createTextRequest(),
            timeNanos: $timeNanos ?? 1000000000,
        );
    }

    public static function createTextGenerationCompleted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        ?TextRequest $request = null,
        ?TextResponse $response = null,
        ?int $timeNanos = null,
    ): TextGenerationCompleted {
        return new TextGenerationCompleted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: $request ?? self::createTextRequest(),
            response: $response ?? self::createTextResponse(),
            timeNanos: $timeNanos ?? 2000000000,
        );
    }

    public static function createStructuredRequest(
        string $model = 'gpt-4',
        string $provider = 'openai',
    ): StructuredRequest {
        return new StructuredRequest(
            systemPrompts: [],
            model: $model,
            providerKey: $provider,
            prompt: 'Generate a user profile',
            messages: [],
            maxTokens: 100,
            temperature: 0.7,
            topP: 1.0,
            clientOptions: [],
            clientRetry: [3],
            schema: new ObjectSchema('UserProfile', 'A user profile', [
                'name' => new StringSchema('name', 'The user name'),
            ]),
            mode: StructuredMode::Json,
            tools: [],
            toolChoice: null,
            maxSteps: 1,
        );
    }

    public static function createStructuredResponse(
        mixed $structured = ['name' => 'John'],
        int $promptTokens = 10,
        int $completionTokens = 5,
        string $model = 'gpt-4',
    ): StructuredResponse {
        return new StructuredResponse(
            steps: new Collection,
            text: json_encode($structured),
            structured: $structured,
            finishReason: FinishReason::Stop,
            usage: new Usage($promptTokens, $completionTokens),
            meta: new Meta(id: 'resp-123', model: $model),
            toolCalls: [],
            toolResults: [],
        );
    }

    public static function createStructuredOutputStarted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
    ): StructuredOutputStarted {
        return new StructuredOutputStarted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: self::createStructuredRequest(),
            timeNanos: 1000000000,
        );
    }

    public static function createStructuredOutputCompleted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
    ): StructuredOutputCompleted {
        return new StructuredOutputCompleted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: self::createStructuredRequest(),
            response: self::createStructuredResponse(),
            timeNanos: 2000000000,
        );
    }

    public static function createEmbeddingRequest(
        string $model = 'text-embedding-ada-002',
        string $provider = 'openai',
        array $inputs = ['Hello world'],
    ): EmbeddingRequest {
        return new EmbeddingRequest(
            model: $model,
            providerKey: $provider,
            inputs: $inputs,
            images: [],
            clientOptions: [],
            clientRetry: [3],
        );
    }

    public static function createEmbeddingResponse(int $tokens = 10): EmbeddingResponse
    {
        return new EmbeddingResponse(
            embeddings: [[0.1, 0.2, 0.3]],
            usage: new EmbeddingsUsage($tokens),
            meta: new Meta(id: 'embed-123', model: 'text-embedding-ada-002'),
        );
    }

    public static function createEmbeddingGenerationStarted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        array $inputs = ['Hello world'],
    ): EmbeddingGenerationStarted {
        return new EmbeddingGenerationStarted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: self::createEmbeddingRequest(inputs: $inputs),
            timeNanos: 1000000000,
        );
    }

    public static function createEmbeddingGenerationCompleted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        int $tokens = 10,
    ): EmbeddingGenerationCompleted {
        return new EmbeddingGenerationCompleted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: self::createEmbeddingRequest(),
            response: self::createEmbeddingResponse($tokens),
            timeNanos: 2000000000,
        );
    }

    public static function createToolCall(
        string $id = 'call_123',
        string $name = 'search',
        array $arguments = ['query' => 'test'],
    ): ToolCall {
        return new ToolCall($id, $name, $arguments);
    }

    public static function createToolResult(
        string $toolCallId = 'call_123',
        string $toolName = 'search',
        array $args = ['query' => 'test'],
        mixed $result = 'Search results',
    ): ToolResult {
        return new ToolResult(
            toolCallId: $toolCallId,
            toolName: $toolName,
            args: $args,
            result: $result,
        );
    }

    public static function createToolCallStarted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        ?ToolCall $toolCall = null,
    ): ToolCallStarted {
        return new ToolCallStarted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            toolCall: $toolCall ?? self::createToolCall(),
            timeNanos: 1000000000,
        );
    }

    public static function createToolCallCompleted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        ?ToolCall $toolCall = null,
        ?ToolResult $toolResult = null,
    ): ToolCallCompleted {
        return new ToolCallCompleted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            toolCall: $toolCall ?? self::createToolCall(),
            toolResult: $toolResult ?? self::createToolResult(),
            timeNanos: 2000000000,
        );
    }

    public static function createStreamingStarted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        ?StreamStartEvent $streamStart = null,
    ): StreamingStarted {
        return new StreamingStarted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: self::createTextRequest(),
            streamStart: $streamStart ?? new StreamStartEvent('stream-1', time(), 'gpt-4', 'openai'),
            timeNanos: 1000000000,
        );
    }

    public static function createStreamingCompleted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        ?StreamEndEvent $streamEnd = null,
        array $events = [],
    ): StreamingCompleted {
        return new StreamingCompleted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: self::createTextRequest(),
            streamEnd: $streamEnd ?? new StreamEndEvent('stream-1', time(), FinishReason::Stop, new Usage(10, 5)),
            events: $events,
            timeNanos: 2000000000,
        );
    }

    public static function createStreamStepStarted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
    ): StreamStepStarted {
        $request = self::createTextRequest();

        return new StreamStepStarted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: $request,
            stepStart: new StepStartEvent('step-1', time(), $request),
            timeNanos: 1000000000,
        );
    }

    public static function createStreamStepCompleted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        array $events = [],
    ): StreamStepCompleted {
        return new StreamStepCompleted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: self::createTextRequest(),
            stepFinish: new StepFinishEvent('step-1', time(), FinishReason::Stop),
            events: $events,
            timeNanos: 2000000000,
        );
    }

    /**
     * Create a complete SpanData for text generation.
     */
    public static function createTextGenerationSpanData(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        array $metadata = [],
        ?\Throwable $exception = null,
    ): SpanData {
        return new SpanData(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            operation: TelemetryOperation::TextGeneration,
            startTimeNano: 1000000000,
            endTimeNano: 2000000000,
            startEvent: self::createTextGenerationStarted($spanId, $traceId, $parentSpanId),
            endEvent: self::createTextGenerationCompleted($spanId, $traceId, $parentSpanId),
            exception: $exception,
            metadata: $metadata,
        );
    }

    /**
     * Create a complete SpanData for tool calls.
     */
    public static function createToolCallSpanData(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
    ): SpanData {
        return new SpanData(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            operation: TelemetryOperation::ToolCall,
            startTimeNano: 1000000000,
            endTimeNano: 2000000000,
            startEvent: self::createToolCallStarted($spanId, $traceId, $parentSpanId),
            endEvent: self::createToolCallCompleted($spanId, $traceId, $parentSpanId),
        );
    }

    /**
     * Create a complete SpanData for embeddings.
     */
    public static function createEmbeddingSpanData(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        array $inputs = ['Hello world'],
    ): SpanData {
        return new SpanData(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            operation: TelemetryOperation::Embeddings,
            startTimeNano: 1000000000,
            endTimeNano: 2000000000,
            startEvent: self::createEmbeddingGenerationStarted($spanId, $traceId, $parentSpanId, $inputs),
            endEvent: self::createEmbeddingGenerationCompleted($spanId, $traceId, $parentSpanId),
        );
    }

    /**
     * Create a complete SpanData for streaming.
     */
    public static function createStreamingSpanData(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        array $streamEvents = [],
    ): SpanData {
        return new SpanData(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            operation: TelemetryOperation::Streaming,
            startTimeNano: 1000000000,
            endTimeNano: 2000000000,
            startEvent: self::createStreamingStarted($spanId, $traceId, $parentSpanId),
            endEvent: self::createStreamingCompleted($spanId, $traceId, $parentSpanId, events: $streamEvents),
        );
    }

    /**
     * Create a complete SpanData for structured output.
     */
    public static function createStructuredOutputSpanData(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
    ): SpanData {
        return new SpanData(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            operation: TelemetryOperation::StructuredOutput,
            startTimeNano: 1000000000,
            endTimeNano: 2000000000,
            startEvent: self::createStructuredOutputStarted($spanId, $traceId, $parentSpanId),
            endEvent: self::createStructuredOutputCompleted($spanId, $traceId, $parentSpanId),
        );
    }

    /**
     * Create a complete SpanData for stream step.
     */
    public static function createStreamStepSpanData(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        array $events = [],
    ): SpanData {
        return new SpanData(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            operation: TelemetryOperation::StreamStep,
            startTimeNano: 1000000000,
            endTimeNano: 2000000000,
            startEvent: self::createStreamStepStarted($spanId, $traceId, $parentSpanId),
            endEvent: self::createStreamStepCompleted($spanId, $traceId, $parentSpanId, $events),
        );
    }

    /**
     * Create TextDeltaEvent for streaming tests.
     */
    public static function createTextDeltaEvent(string $delta = 'Hello'): TextDeltaEvent
    {
        return new TextDeltaEvent('event-1', time(), $delta, 'msg-1');
    }

    /**
     * Create ToolCallEvent for streaming tests.
     */
    public static function createToolCallEvent(?ToolCall $toolCall = null): ToolCallEvent
    {
        return new ToolCallEvent('event-1', time(), $toolCall ?? self::createToolCall(), 'msg-1');
    }

    /**
     * Create a TextStep for testing.
     */
    public static function createTextStep(
        string $text = 'Hello there!',
        int $promptTokens = 10,
        int $completionTokens = 5,
        string $model = 'gpt-4',
        string $responseId = 'resp-123',
        ?string $serviceTier = null,
        array $toolCalls = [],
        array $toolResults = [],
        FinishReason $finishReason = FinishReason::Stop,
        array $messages = [],
        array $systemPrompts = [],
    ): TextStep {
        return new TextStep(
            text: $text,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            providerToolCalls: [],
            usage: new Usage($promptTokens, $completionTokens),
            meta: new Meta(id: $responseId, model: $model, serviceTier: $serviceTier),
            messages: $messages,
            systemPrompts: $systemPrompts,
        );
    }

    public static function createTextStepStarted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        ?TextRequest $request = null,
        ?int $timeNanos = null,
    ): TextStepStarted {
        return new TextStepStarted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: $request ?? self::createTextRequest(),
            timeNanos: $timeNanos ?? 1000000000,
        );
    }

    public static function createTextStepCompleted(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
        ?TextRequest $request = null,
        ?TextStep $step = null,
        ?int $timeNanos = null,
    ): TextStepCompleted {
        return new TextStepCompleted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: $request ?? self::createTextRequest(),
            step: $step ?? self::createTextStep(),
            timeNanos: $timeNanos ?? 2000000000,
        );
    }

    /**
     * Create a complete SpanData for text step.
     */
    public static function createTextStepSpanData(
        string $spanId = 'test-span-id',
        string $traceId = 'test-trace-id',
        ?string $parentSpanId = null,
    ): SpanData {
        return new SpanData(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            operation: TelemetryOperation::TextStep,
            startTimeNano: 1000000000,
            endTimeNano: 2000000000,
            startEvent: self::createTextStepStarted($spanId, $traceId, $parentSpanId),
            endEvent: self::createTextStepCompleted($spanId, $traceId, $parentSpanId),
        );
    }
}
