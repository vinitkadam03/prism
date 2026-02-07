<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Otel\PrismSemanticConventions;
use Prism\Prism\Telemetry\Otel\PrismSpanAttributes;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Telemetry\Helpers\TelemetryTestHelpers;

describe('TextGeneration', function (): void {
    it('extracts core attributes', function (): void {
        $span = TelemetryTestHelpers::createTextGenerationSpanData();
        $attrs = PrismSpanAttributes::extract($span);

        expect($attrs[PrismSemanticConventions::OPERATION])->toBe('prism.text.asText')
            ->and($attrs[PrismSemanticConventions::MODEL])->toBe('gpt-4')
            ->and($attrs[PrismSemanticConventions::PROVIDER])->toBe('openai')
            ->and($attrs[PrismSemanticConventions::SETTINGS_TEMPERATURE])->toBe(0.7)
            ->and($attrs[PrismSemanticConventions::SETTINGS_MAX_TOKENS])->toBe(100)
            ->and($attrs[PrismSemanticConventions::SETTINGS_TOP_P])->toBe(1.0)
            ->and($attrs[PrismSemanticConventions::SETTINGS_MAX_STEPS])->toBe(1);
    });

    it('extracts response attributes', function (): void {
        $span = TelemetryTestHelpers::createTextGenerationSpanData();
        $attrs = PrismSpanAttributes::extract($span);

        expect($attrs[PrismSemanticConventions::RESPONSE_TEXT])->toBe('Hello there!')
            ->and($attrs[PrismSemanticConventions::RESPONSE_MODEL])->toBe('gpt-4')
            ->and($attrs[PrismSemanticConventions::RESPONSE_ID])->toBe('resp-123')
            ->and($attrs[PrismSemanticConventions::RESPONSE_FINISH_REASON])->toBe('Stop')
            ->and($attrs[PrismSemanticConventions::USAGE_PROMPT_TOKENS])->toBe(10)
            ->and($attrs[PrismSemanticConventions::USAGE_COMPLETION_TOKENS])->toBe(5);
    });

    it('encodes messages as JSON when messages exist', function (): void {
        $request = TelemetryTestHelpers::createTextRequest(
            messages: [new UserMessage('Hello')],
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
        $attrs = PrismSpanAttributes::extract($span);

        expect($attrs)->toHaveKey(PrismSemanticConventions::PROMPT_MESSAGES);
        $messages = json_decode($attrs[PrismSemanticConventions::PROMPT_MESSAGES], true);
        expect($messages)->toBeArray();
    });

    it('omits messages when empty', function (): void {
        // Default test helpers create requests with no messages
        $span = TelemetryTestHelpers::createTextGenerationSpanData();
        $attrs = PrismSpanAttributes::extract($span);

        // With no system prompts and no messages, prompt_messages should not be present
        expect($attrs)->not->toHaveKey(PrismSemanticConventions::PROMPT_MESSAGES);
    });
});

describe('ToolCall', function (): void {
    it('extracts tool call attributes', function (): void {
        $span = TelemetryTestHelpers::createToolCallSpanData();
        $attrs = PrismSpanAttributes::extract($span);

        expect($attrs[PrismSemanticConventions::OPERATION])->toBe('toolCall')
            ->and($attrs[PrismSemanticConventions::TOOL_NAME])->toBe('search')
            ->and($attrs[PrismSemanticConventions::TOOL_CALL_ID])->toBe('call_123')
            ->and($attrs[PrismSemanticConventions::TOOL_RESULT])->toBe('Search results');

        // Arguments should be JSON encoded
        $args = json_decode($attrs[PrismSemanticConventions::TOOL_ARGUMENTS], true);
        expect($args)->toBe(['query' => 'test']);
    });
});

describe('Embedding', function (): void {
    it('extracts embedding attributes', function (): void {
        $span = TelemetryTestHelpers::createEmbeddingSpanData();
        $attrs = PrismSpanAttributes::extract($span);

        expect($attrs[PrismSemanticConventions::OPERATION])->toBe('prism.embeddings.asEmbeddings')
            ->and($attrs[PrismSemanticConventions::MODEL])->toBe('text-embedding-ada-002')
            ->and($attrs[PrismSemanticConventions::PROVIDER])->toBe('openai')
            ->and($attrs[PrismSemanticConventions::EMBEDDING_COUNT])->toBe(1)
            ->and($attrs[PrismSemanticConventions::EMBEDDING_USAGE_TOKENS])->toBe(10);

        $inputs = json_decode($attrs[PrismSemanticConventions::EMBEDDING_INPUTS], true);
        expect($inputs)->toBe(['Hello world']);
    });
});

describe('Streaming', function (): void {
    it('extracts streaming attributes with events', function (): void {
        $events = [
            TelemetryTestHelpers::createTextDeltaEvent('Hello'),
            TelemetryTestHelpers::createTextDeltaEvent(' world'),
        ];
        $span = TelemetryTestHelpers::createStreamingSpanData(streamEvents: $events);
        $attrs = PrismSpanAttributes::extract($span);

        expect($attrs[PrismSemanticConventions::OPERATION])->toBe('prism.text.asStream')
            ->and($attrs[PrismSemanticConventions::MODEL])->toBe('gpt-4')
            ->and($attrs[PrismSemanticConventions::RESPONSE_TEXT])->toBe('Hello world')
            ->and($attrs[PrismSemanticConventions::USAGE_PROMPT_TOKENS])->toBe(10);
    });
});

describe('StructuredOutput', function (): void {
    it('extracts structured output attributes', function (): void {
        $span = TelemetryTestHelpers::createStructuredOutputSpanData();
        $attrs = PrismSpanAttributes::extract($span);

        expect($attrs[PrismSemanticConventions::OPERATION])->toBe('prism.structured.asStructured')
            ->and($attrs[PrismSemanticConventions::SCHEMA_NAME])->toBe('UserProfile')
            ->and($attrs)->toHaveKey(PrismSemanticConventions::SCHEMA_DEFINITION)
            ->and($attrs)->toHaveKey(PrismSemanticConventions::RESPONSE_OBJECT);
    });
});

describe('TextStep', function (): void {
    it('extracts text step attributes', function (): void {
        $span = TelemetryTestHelpers::createTextStepSpanData();
        $attrs = PrismSpanAttributes::extract($span);

        expect($attrs[PrismSemanticConventions::OPERATION])->toBe('textStep')
            ->and($attrs[PrismSemanticConventions::MODEL])->toBe('gpt-4')
            ->and($attrs[PrismSemanticConventions::RESPONSE_TEXT])->toBe('Hello there!')
            ->and($attrs[PrismSemanticConventions::USAGE_PROMPT_TOKENS])->toBe(10);
    });
});

describe('StreamStep', function (): void {
    it('extracts stream step attributes with events', function (): void {
        $events = [TelemetryTestHelpers::createTextDeltaEvent('chunk')];
        $span = TelemetryTestHelpers::createStreamStepSpanData(events: $events);
        $attrs = PrismSpanAttributes::extract($span);

        expect($attrs[PrismSemanticConventions::OPERATION])->toBe('streamStep')
            ->and($attrs[PrismSemanticConventions::MODEL])->toBe('gpt-4')
            ->and($attrs[PrismSemanticConventions::RESPONSE_TEXT])->toBe('chunk');
    });
});

describe('Metadata', function (): void {
    it('extracts metadata as prism.metadata.* attributes', function (): void {
        $span = TelemetryTestHelpers::createTextGenerationSpanData(metadata: [
            'user_id' => 'user-123',
            'session_id' => 'sess-456',
            'custom_key' => 'custom_value',
        ]);
        $attrs = PrismSpanAttributes::extract($span);

        expect($attrs['prism.metadata.user_id'])->toBe('user-123')
            ->and($attrs['prism.metadata.session_id'])->toBe('sess-456')
            ->and($attrs['prism.metadata.custom_key'])->toBe('custom_value');
    });

    it('filters null values', function (): void {
        $span = TelemetryTestHelpers::createTextGenerationSpanData();
        $attrs = PrismSpanAttributes::extract($span);

        // No null values should be present
        foreach ($attrs as $value) {
            expect($value)->not->toBeNull();
        }
    });
});
