<?php

declare(strict_types=1);

use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Telemetry\Semantics\OpenInferenceMapper;
use Prism\Prism\Telemetry\SpanData;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Telemetry\Helpers\TelemetryTestHelpers;

it('maps text generation span to CHAIN kind', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createTextGenerationSpanData();
    $attrs = $mapper->map($span);

    expect($attrs['openinference.span.kind'])->toBe('CHAIN');
    expect($attrs['llm.model_name'])->toBe('gpt-4');
    expect($attrs['llm.provider'])->toBe('openai');
});

it('maps streaming span to CHAIN kind', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createStreamingSpanData();
    $attrs = $mapper->map($span);

    expect($attrs['openinference.span.kind'])->toBe('CHAIN');
    expect($attrs['llm.model_name'])->toBe('gpt-4');
});

it('maps tool_call span to TOOL kind', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createToolCallSpanData();
    $attrs = $mapper->map($span);

    expect($attrs['openinference.span.kind'])->toBe('TOOL');
    expect($attrs['tool.name'])->toBe('search');
    expect($attrs['tool.parameters'])->toBe('{"query":"test"}');
    expect($attrs['output.value'])->toBe('Search results');
});

it('maps embedding_generation span to EMBEDDING kind', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createEmbeddingSpanData(inputs: ['Hello world', 'Test input']);
    $attrs = $mapper->map($span);

    expect($attrs['openinference.span.kind'])->toBe('EMBEDDING');
    expect($attrs['embedding.model_name'])->toBe('text-embedding-ada-002');
    expect($attrs['embedding.embeddings.0.embedding.text'])->toBe('Hello world');
});

it('maps structured_output span to CHAIN kind', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createStructuredOutputSpanData();
    $attrs = $mapper->map($span);

    expect($attrs['openinference.span.kind'])->toBe('CHAIN');
    expect($attrs['output.schema.name'])->toBe('UserProfile');
});

it('converts token usage correctly', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createTextGenerationSpanData();
    $attrs = $mapper->map($span);

    expect($attrs['llm.token_count.prompt'])->toBe(10);
    expect($attrs['llm.token_count.completion'])->toBe(5);
    expect($attrs['llm.token_count.total'])->toBe(15);
});

it('converts invocation parameters to JSON', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createTextGenerationSpanData();
    $attrs = $mapper->map($span);

    expect($attrs)->toHaveKey('llm.invocation_parameters');

    $params = json_decode((string) $attrs['llm.invocation_parameters'], true);
    expect($params['temperature'])->toBe(0.7);
    expect($params['max_tokens'])->toBe(100);
});

it('includes system prompts in input messages', function (): void {
    $mapper = new OpenInferenceMapper;

    $request = TelemetryTestHelpers::createTextRequest(
        systemPrompts: [new SystemMessage('You are a helpful assistant.')],
    );

    $startEvent = TelemetryTestHelpers::createTextGenerationStarted(request: $request);
    $endEvent = TelemetryTestHelpers::createTextGenerationCompleted(request: $request);

    $span = new SpanData(
        spanId: 'test-span',
        traceId: 'test-trace',
        parentSpanId: null,
        operation: TelemetryOperation::TextGeneration,
        startTimeNano: 1000000000,
        endTimeNano: 2000000000,
        startEvent: $startEvent,
        endEvent: $endEvent,
    );

    $attrs = $mapper->map($span);

    // System prompts are included in input messages, not as separate llm.system
    expect($attrs['llm.input_messages.0.message.role'])->toBe('system');
    expect($attrs['llm.input_messages.0.message.content'])->toBe('You are a helpful assistant.');
});

it('formats input messages correctly', function (): void {
    $mapper = new OpenInferenceMapper;

    $request = TelemetryTestHelpers::createTextRequest(
        messages: [
            new UserMessage('Hello'),
        ],
    );

    $startEvent = TelemetryTestHelpers::createTextGenerationStarted(request: $request);
    $endEvent = TelemetryTestHelpers::createTextGenerationCompleted(request: $request);

    $span = new SpanData(
        spanId: 'test-span',
        traceId: 'test-trace',
        parentSpanId: null,
        operation: TelemetryOperation::TextGeneration,
        startTimeNano: 1000000000,
        endTimeNano: 2000000000,
        startEvent: $startEvent,
        endEvent: $endEvent,
    );

    $attrs = $mapper->map($span);

    expect($attrs['llm.input_messages.0.message.role'])->toBe('user');
    expect($attrs['llm.input_messages.0.message.content'])->toBe('Hello');
});

it('formats output messages correctly', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createTextGenerationSpanData();
    $attrs = $mapper->map($span);

    expect($attrs['llm.output_messages.0.message.role'])->toBe('assistant');
    expect($attrs['llm.output_messages.0.message.content'])->toBe('Hello there!');
});

it('maps user and session attributes to OpenInference format', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createTextGenerationSpanData(
        metadata: [
            'user_id' => 'user_123',
            'session_id' => 'session_456',
        ]
    );

    $attrs = $mapper->map($span);

    expect($attrs['user.id'])->toBe('user_123');
    expect($attrs['session.id'])->toBe('session_456');
});

it('maps agent to agent.name per OpenInference spec', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createTextGenerationSpanData(
        metadata: [
            'agent' => 'support-bot',
        ]
    );

    $attrs = $mapper->map($span);

    expect($attrs['agent.name'])->toBe('support-bot');
});

it('formats general metadata with prefix', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createTextGenerationSpanData(
        metadata: [
            'custom_key' => 'custom_value',
            'user_email' => 'test@example.com',
        ]
    );

    $attrs = $mapper->map($span);

    expect($attrs['metadata.custom_key'])->toBe('custom_value');
    expect($attrs['metadata.user_email'])->toBe('test@example.com');
});

it('maps tags to OpenInference tag.tags list format', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createTextGenerationSpanData(
        metadata: [
            'tags' => [
                'environment' => 'production',
                'app' => 'my-app',
            ],
        ]
    );

    $attrs = $mapper->map($span);

    expect($attrs['tag.tags'])->toBe('["environment:production","app:my-app"]');
});

it('supports simple string tags in tag.tags list', function (): void {
    $mapper = new OpenInferenceMapper;

    $span = TelemetryTestHelpers::createTextGenerationSpanData(
        metadata: [
            'tags' => ['shopping', 'travel'],
        ]
    );

    $attrs = $mapper->map($span);

    expect($attrs['tag.tags'])->toBe('["shopping","travel"]');
});

it('maps exception events correctly', function (): void {
    $mapper = new OpenInferenceMapper;

    $events = [
        [
            'name' => 'exception',
            'timeNanos' => 1000000000,
            'attributes' => [
                'type' => 'RuntimeException',
                'message' => 'Test error',
            ],
        ],
    ];

    $mappedEvents = $mapper->mapEvents($events);

    expect($mappedEvents[0]['attributes']['exception.type'])->toBe('RuntimeException');
    expect($mappedEvents[0]['attributes']['exception.message'])->toBe('Test error');
    expect($mappedEvents[0]['attributes']['exception.escaped'])->toBeTrue();
});

it('filters null values from attributes', function (): void {
    $mapper = new OpenInferenceMapper;

    $request = TelemetryTestHelpers::createTextRequest(temperature: null, maxTokens: null, topP: null);
    $startEvent = TelemetryTestHelpers::createTextGenerationStarted(request: $request);
    $endEvent = TelemetryTestHelpers::createTextGenerationCompleted(request: $request);

    $span = new SpanData(
        spanId: 'test-span',
        traceId: 'test-trace',
        parentSpanId: null,
        operation: TelemetryOperation::TextGeneration,
        startTimeNano: 1000000000,
        endTimeNano: 2000000000,
        startEvent: $startEvent,
        endEvent: $endEvent,
    );

    $attrs = $mapper->map($span);

    // Invocation parameters should be null or filtered when all params are null
    expect($attrs['llm.invocation_parameters'] ?? null)->toBeNull();
});

it('maps all operation types to their correct OpenInference kinds', function (): void {
    $mapper = new OpenInferenceMapper;

    $testCases = [
        ['operation' => TelemetryOperation::TextGeneration, 'kind' => 'CHAIN', 'span' => TelemetryTestHelpers::createTextGenerationSpanData()],
        ['operation' => TelemetryOperation::Streaming, 'kind' => 'CHAIN', 'span' => TelemetryTestHelpers::createStreamingSpanData()],
        ['operation' => TelemetryOperation::StructuredOutput, 'kind' => 'CHAIN', 'span' => TelemetryTestHelpers::createStructuredOutputSpanData()],
        ['operation' => TelemetryOperation::Embeddings, 'kind' => 'EMBEDDING', 'span' => TelemetryTestHelpers::createEmbeddingSpanData()],
        ['operation' => TelemetryOperation::ToolCall, 'kind' => 'TOOL', 'span' => TelemetryTestHelpers::createToolCallSpanData()],
        ['operation' => TelemetryOperation::StreamStep, 'kind' => 'LLM', 'span' => TelemetryTestHelpers::createStreamStepSpanData()],
        ['operation' => TelemetryOperation::TextStep, 'kind' => 'LLM', 'span' => TelemetryTestHelpers::createTextStepSpanData()],
    ];

    foreach ($testCases as $testCase) {
        $attrs = $mapper->map($testCase['span']);

        expect($attrs['openinference.span.kind'])->toBe($testCase['kind'], "Expected {$testCase['operation']->value} to map to {$testCase['kind']}");
    }
});
