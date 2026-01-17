<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use Prism\Prism\Telemetry\Otel\OpenInferenceBatchSpanProcessor;
use Prism\Prism\Telemetry\Otel\PrismSemanticConventions;
use Prism\Prism\Telemetry\Otel\PrismSpanAttributes;
use Tests\Telemetry\Helpers\TelemetryTestHelpers;

/**
 * Helper: creates a mock ReadWriteSpanInterface with the given prism.* attributes.
 * Calls onEnding() on the processor and returns the attributes that were set.
 */
function processSpanAttributes(array $prismAttrs): array
{
    $setAttributes = [];
    $allAttributes = $prismAttrs; // includes original prism.* attrs

    $mockSpan = Mockery::mock(ReadWriteSpanInterface::class);
    $mockSpan->shouldReceive('getAttribute')
        ->andReturnUsing(function (string $key) use (&$allAttributes) {
            return $allAttributes[$key] ?? null;
        });
    $mockSpan->shouldReceive('setAttribute')
        ->andReturnUsing(function (string $key, $value) use (&$setAttributes, &$allAttributes, $mockSpan) {
            $setAttributes[$key] = $value;
            $allAttributes[$key] = $value;

            return $mockSpan;
        });

    $exporter = Mockery::mock(\OpenTelemetry\SDK\Trace\SpanExporterInterface::class);
    $exporter->shouldIgnoreMissing();

    $processor = new OpenInferenceBatchSpanProcessor($exporter, \OpenTelemetry\API\Common\Time\Clock::getDefault());
    $processor->onEnding($mockSpan);

    return $setAttributes;
}

/**
 * Helper: extract prism.* attributes from SpanData and run through the processor.
 */
function processSpanData(\Prism\Prism\Telemetry\SpanData $spanData): array
{
    $prismAttrs = PrismSpanAttributes::extract($spanData);

    return processSpanAttributes($prismAttrs);
}

describe('TextGeneration (CHAIN)', function (): void {
    it('maps prism attributes to OpenInference CHAIN', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createTextGenerationSpanData());

        expect($attrs['openinference.span.kind'])->toBe('CHAIN')
            ->and($attrs['llm.model_name'])->toBe('gpt-4')
            ->and($attrs['llm.provider'])->toBe('openai')
            ->and($attrs['llm.response.model'])->toBe('gpt-4')
            ->and($attrs['llm.response.id'])->toBe('resp-123');
    });

    it('maps invocation parameters', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createTextGenerationSpanData());

        $params = json_decode($attrs['llm.invocation_parameters'], true);
        expect($params)->toBeArray()
            ->and($params['temperature'])->toBe(0.7)
            ->and($params['max_tokens'])->toBe(100);
    });

    it('maps token usage', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createTextGenerationSpanData());

        expect($attrs['llm.token_count.prompt'])->toBe(10)
            ->and($attrs['llm.token_count.completion'])->toBe(5)
            ->and($attrs['llm.token_count.total'])->toBe(15);
    });

    it('maps input/output values', function (): void {
        // Create with messages so input.value is populated
        $request = TelemetryTestHelpers::createTextRequest(
            messages: [new \Prism\Prism\ValueObjects\Messages\UserMessage('Hello')],
        );
        $startEvent = TelemetryTestHelpers::createTextGenerationStarted(request: $request);
        $endEvent = TelemetryTestHelpers::createTextGenerationCompleted(request: $request);
        $span = new \Prism\Prism\Telemetry\SpanData(
            spanId: 'test-span-id',
            traceId: 'test-trace-id',
            parentSpanId: null,
            operation: \Prism\Prism\Enums\TelemetryOperation::TextGeneration,
            startTimeNano: 1000000000,
            endTimeNano: 2000000000,
            startEvent: $startEvent,
            endEvent: $endEvent,
        );
        $attrs = processSpanData($span);

        expect($attrs['input.value'])->toBeString()
            ->and($attrs['input.mime_type'])->toBe('application/json')
            ->and($attrs['output.value'])->toBe('Hello there!')
            ->and($attrs['output.mime_type'])->toBe('text/plain');
    });

    it('maps output messages', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createTextGenerationSpanData());

        expect($attrs['llm.output_messages.0.message.role'])->toBe('assistant')
            ->and($attrs['llm.output_messages.0.message.content'])->toBe('Hello there!')
            ->and($attrs['llm.output_messages.0.message.finish_reason'])->toBe('Stop');
    });
});

describe('ToolCall (TOOL)', function (): void {
    it('maps prism attributes to OpenInference TOOL', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createToolCallSpanData());

        expect($attrs['openinference.span.kind'])->toBe('TOOL')
            ->and($attrs['tool.name'])->toBe('search')
            ->and($attrs['input.value'])->toBeString()
            ->and($attrs['input.mime_type'])->toBe('application/json')
            ->and($attrs['output.value'])->toBe('Search results')
            ->and($attrs['output.mime_type'])->toBe('text/plain');
    });
});

describe('Embedding (EMBEDDING)', function (): void {
    it('maps prism attributes to OpenInference EMBEDDING', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createEmbeddingSpanData());

        expect($attrs['openinference.span.kind'])->toBe('EMBEDDING')
            ->and($attrs['embedding.model_name'])->toBe('text-embedding-ada-002')
            ->and($attrs['embedding.provider'])->toBe('openai')
            ->and($attrs['embedding.embeddings.0.embedding.text'])->toBe('Hello world')
            ->and($attrs['input.value'])->toBe('Hello world')
            ->and($attrs['input.mime_type'])->toBe('text/plain')
            ->and($attrs['llm.token_count.prompt'])->toBe(10)
            ->and($attrs['llm.token_count.total'])->toBe(10);
    });
});

describe('Streaming (CHAIN)', function (): void {
    it('maps streaming span with text events', function (): void {
        $events = [
            TelemetryTestHelpers::createTextDeltaEvent('Hello'),
            TelemetryTestHelpers::createTextDeltaEvent(' world'),
        ];
        $attrs = processSpanData(TelemetryTestHelpers::createStreamingSpanData(streamEvents: $events));

        expect($attrs['openinference.span.kind'])->toBe('CHAIN')
            ->and($attrs['output.value'])->toBe('Hello world')
            ->and($attrs['llm.token_count.prompt'])->toBe(10);
    });
});

describe('StructuredOutput (CHAIN)', function (): void {
    it('maps structured output span', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createStructuredOutputSpanData());

        expect($attrs['openinference.span.kind'])->toBe('CHAIN')
            ->and($attrs['output.schema.name'])->toBe('UserProfile')
            ->and($attrs['output.schema'])->toBeString()
            ->and($attrs['output.value'])->toBeString()
            ->and($attrs['output.mime_type'])->toBe('application/json');
    });
});

describe('TextStep (LLM)', function (): void {
    it('maps text step span to LLM kind', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createTextStepSpanData());

        expect($attrs['openinference.span.kind'])->toBe('LLM')
            ->and($attrs['llm.model_name'])->toBe('gpt-4')
            ->and($attrs['llm.provider'])->toBe('openai')
            ->and($attrs['llm.response.model'])->toBe('gpt-4')
            ->and($attrs['llm.token_count.prompt'])->toBe(10)
            ->and($attrs['llm.token_count.completion'])->toBe(5);
    });
});

describe('StreamStep (LLM)', function (): void {
    it('maps stream step span to LLM kind', function (): void {
        $events = [TelemetryTestHelpers::createTextDeltaEvent('chunk')];
        $attrs = processSpanData(TelemetryTestHelpers::createStreamStepSpanData(events: $events));

        expect($attrs['openinference.span.kind'])->toBe('LLM')
            ->and($attrs['llm.model_name'])->toBe('gpt-4')
            ->and($attrs['output.value'])->toBe('chunk');
    });
});

describe('Metadata Mapping', function (): void {
    it('maps user_id to user.id', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createTextGenerationSpanData(metadata: ['user_id' => 'u-123']));

        expect($attrs['user.id'])->toBe('u-123');
    });

    it('maps session_id to session.id', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createTextGenerationSpanData(metadata: ['session_id' => 's-456']));

        expect($attrs['session.id'])->toBe('s-456');
    });

    it('maps agent to agent.name', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createTextGenerationSpanData(metadata: ['agent' => 'MyBot']));

        expect($attrs['agent.name'])->toBe('MyBot');
    });

    it('maps custom metadata to metadata.* namespace', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createTextGenerationSpanData(metadata: ['custom_key' => 'custom_value']));

        expect($attrs['metadata.custom_key'])->toBe('custom_value');
    });

    it('maps tags to tag.tags', function (): void {
        $attrs = processSpanData(TelemetryTestHelpers::createTextGenerationSpanData(metadata: [
            'tags' => ['env' => 'testing', 'version' => '1.0'],
        ]));

        $tags = json_decode($attrs['tag.tags'], true);
        expect($tags)->toContain('env:testing')
            ->and($tags)->toContain('version:1.0');
    });
});

describe('Non-prism spans', function (): void {
    it('skips spans without prism.operation attribute', function (): void {
        $attrs = processSpanAttributes(['some.other.attr' => 'value']);

        // No OpenInference attributes should be set (except none from metadata mapping)
        expect($attrs)->not->toHaveKey('openinference.span.kind');
    });
});
