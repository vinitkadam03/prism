<?php

declare(strict_types=1);

use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Telemetry\Events\TextGenerationCompleted;
use Prism\Prism\Telemetry\PendingSpanData;
use Prism\Prism\Telemetry\SpanData;
use Tests\Telemetry\Helpers\TelemetryTestHelpers;

describe('construction', function (): void {
    it('creates pending span with identity properties', function (): void {
        $startEvent = TelemetryTestHelpers::createTextGenerationStarted(
            spanId: 'span-123',
            traceId: 'trace-456',
            parentSpanId: 'parent-789',
        );

        $pending = new PendingSpanData(
            spanId: 'span-123',
            traceId: 'trace-456',
            parentSpanId: 'parent-789',
            operation: TelemetryOperation::TextGeneration,
            startTimeNano: 1000000000,
            startEvent: $startEvent,
        );

        expect($pending->spanId)->toBe('span-123')
            ->and($pending->traceId)->toBe('trace-456')
            ->and($pending->parentSpanId)->toBe('parent-789')
            ->and($pending->operation)->toBe(TelemetryOperation::TextGeneration)
            ->and($pending->startTimeNano)->toBe(1000000000)
            ->and($pending->startEvent)->toBe($startEvent);
    });

    it('allows null parent span id', function (): void {
        $startEvent = TelemetryTestHelpers::createTextGenerationStarted(
            spanId: 'span-123',
            traceId: 'trace-456',
        );

        $pending = new PendingSpanData(
            spanId: 'span-123',
            traceId: 'trace-456',
            parentSpanId: null,
            operation: TelemetryOperation::TextGeneration,
            startTimeNano: 1000000000,
            startEvent: $startEvent,
        );

        expect($pending->parentSpanId)->toBeNull();
    });
});

describe('setMetadata', function (): void {
    it('sets user-provided metadata', function (): void {
        $pending = createPendingSpan();
        $pending->setMetadata(['user_id' => '123', 'session_id' => 'abc']);

        $spanData = $pending->toSpanData(2000000000, createEndEvent());

        expect($spanData->metadata['user_id'])->toBe('123')
            ->and($spanData->metadata['session_id'])->toBe('abc');
    });

    it('returns self for fluent chaining', function (): void {
        $pending = createPendingSpan();
        $result = $pending->setMetadata(['user_id' => '123']);

        expect($result)->toBe($pending);
    });
});

describe('addEvent', function (): void {
    it('adds events to the span', function (): void {
        $pending = createPendingSpan();
        $pending->addEvent('token_generated', 1500000000, ['count' => 10]);

        $spanData = $pending->toSpanData(2000000000, createEndEvent());

        expect($spanData->events)->toHaveCount(1)
            ->and($spanData->events[0]['name'])->toBe('token_generated')
            ->and($spanData->events[0]['timeNanos'])->toBe(1500000000)
            ->and($spanData->events[0]['attributes'])->toBe(['count' => 10]);
    });

    it('supports empty attributes', function (): void {
        $pending = createPendingSpan();
        $pending->addEvent('chunk_received', 1500000000);

        $spanData = $pending->toSpanData(2000000000, createEndEvent());

        expect($spanData->events[0]['attributes'])->toBe([]);
    });

    it('preserves event order', function (): void {
        $pending = createPendingSpan();
        $pending->addEvent('first', 1100000000);
        $pending->addEvent('second', 1200000000);
        $pending->addEvent('third', 1300000000);

        $spanData = $pending->toSpanData(2000000000, createEndEvent());

        expect($spanData->events)->toHaveCount(3)
            ->and($spanData->events[0]['name'])->toBe('first')
            ->and($spanData->events[1]['name'])->toBe('second')
            ->and($spanData->events[2]['name'])->toBe('third');
    });

    it('returns self for fluent chaining', function (): void {
        $pending = createPendingSpan();
        $result = $pending->addEvent('test', 1500000000);

        expect($result)->toBe($pending);
    });
});

describe('setException', function (): void {
    it('records exception on the span', function (): void {
        $pending = createPendingSpan();
        $exception = new RuntimeException('Test error');
        $pending->setException($exception, 1500000000);

        $spanData = $pending->toSpanData(2000000000, createEndEvent());

        expect($spanData->exception)->toBe($exception)
            ->and($spanData->hasError())->toBeTrue();
    });

    it('adds exception event automatically', function (): void {
        $pending = createPendingSpan();
        $exception = new RuntimeException('Test error');
        $pending->setException($exception, 1500000000);

        $spanData = $pending->toSpanData(2000000000, createEndEvent());

        expect($spanData->events)->toHaveCount(1)
            ->and($spanData->events[0]['name'])->toBe('exception')
            ->and($spanData->events[0]['timeNanos'])->toBe(1500000000)
            ->and($spanData->events[0]['attributes']['type'])->toBe(RuntimeException::class)
            ->and($spanData->events[0]['attributes']['message'])->toBe('Test error');
    });

    it('returns self for fluent chaining', function (): void {
        $pending = createPendingSpan();
        $result = $pending->setException(new RuntimeException('Test'), 1500000000);

        expect($result)->toBe($pending);
    });
});

describe('toSpanData', function (): void {
    it('converts to immutable SpanData', function (): void {
        $pending = createPendingSpan();
        $spanData = $pending->toSpanData(2000000000, createEndEvent());

        expect($spanData)->toBeInstanceOf(SpanData::class);
    });

    it('sets end time from parameter', function (): void {
        $pending = createPendingSpan();
        $spanData = $pending->toSpanData(2500000000, createEndEvent());

        expect($spanData->endTimeNano)->toBe(2500000000);
    });

    it('includes start and end events', function (): void {
        $startEvent = TelemetryTestHelpers::createTextGenerationStarted();
        $endEvent = TelemetryTestHelpers::createTextGenerationCompleted();

        $pending = new PendingSpanData(
            spanId: 'span-123',
            traceId: 'trace-456',
            parentSpanId: null,
            operation: TelemetryOperation::TextGeneration,
            startTimeNano: 1000000000,
            startEvent: $startEvent,
        );

        $spanData = $pending->toSpanData(2000000000, $endEvent);

        expect($spanData->startEvent)->toBe($startEvent)
            ->and($spanData->endEvent)->toBe($endEvent);
    });

    it('preserves all identity properties', function (): void {
        $startEvent = TelemetryTestHelpers::createTextGenerationStarted(
            spanId: 'span-123',
            traceId: 'trace-456',
            parentSpanId: 'parent-789',
        );

        $pending = new PendingSpanData(
            spanId: 'span-123',
            traceId: 'trace-456',
            parentSpanId: 'parent-789',
            operation: TelemetryOperation::TextGeneration,
            startTimeNano: 1000000000,
            startEvent: $startEvent,
        );

        $spanData = $pending->toSpanData(2000000000, createEndEvent());

        expect($spanData->spanId)->toBe('span-123')
            ->and($spanData->traceId)->toBe('trace-456')
            ->and($spanData->parentSpanId)->toBe('parent-789')
            ->and($spanData->operation)->toBe(TelemetryOperation::TextGeneration)
            ->and($spanData->startTimeNano)->toBe(1000000000);
    });
});

describe('fluent interface', function (): void {
    it('supports full fluent workflow', function (): void {
        $startEvent = TelemetryTestHelpers::createTextGenerationStarted();
        $endEvent = TelemetryTestHelpers::createTextGenerationCompleted();

        $spanData = (new PendingSpanData(
            spanId: 'span-123',
            traceId: 'trace-456',
            parentSpanId: null,
            operation: TelemetryOperation::TextGeneration,
            startTimeNano: 1000000000,
            startEvent: $startEvent,
        ))
            ->setMetadata(['user_id' => '123'])
            ->addEvent('started', 1100000000)
            ->addEvent('completed', 1900000000)
            ->toSpanData(2000000000, $endEvent);

        expect($spanData->spanId)->toBe('span-123')
            ->and($spanData->metadata['user_id'])->toBe('123')
            ->and($spanData->events)->toHaveCount(2)
            ->and($spanData->durationNano())->toBe(1000000000);
    });
});

function createPendingSpan(): PendingSpanData
{
    return new PendingSpanData(
        spanId: 'span-123',
        traceId: 'trace-456',
        parentSpanId: null,
        operation: TelemetryOperation::TextGeneration,
        startTimeNano: 1000000000,
        startEvent: TelemetryTestHelpers::createTextGenerationStarted(),
    );
}

function createEndEvent(): TextGenerationCompleted
{
    return TelemetryTestHelpers::createTextGenerationCompleted();
}
