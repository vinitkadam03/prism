<?php

declare(strict_types=1);

use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Telemetry\SpanData;
use Tests\Telemetry\Helpers\TelemetryTestHelpers;

it('creates immutable span data with all properties', function (): void {
    $startEvent = TelemetryTestHelpers::createTextGenerationStarted('span-123', 'trace-456', 'parent-789');
    $endEvent = TelemetryTestHelpers::createTextGenerationCompleted('span-123', 'trace-456', 'parent-789');

    $spanData = new SpanData(
        spanId: 'span-123',
        traceId: 'trace-456',
        parentSpanId: 'parent-789',
        operation: TelemetryOperation::TextGeneration,
        startTimeNano: 1000000000,
        endTimeNano: 2000000000,
        startEvent: $startEvent,
        endEvent: $endEvent,
        events: [['name' => 'event1', 'timeNanos' => 1500000000, 'attributes' => []]],
        metadata: ['user_id' => '123'],
    );

    expect($spanData->spanId)->toBe('span-123')
        ->and($spanData->traceId)->toBe('trace-456')
        ->and($spanData->parentSpanId)->toBe('parent-789')
        ->and($spanData->operation)->toBe(TelemetryOperation::TextGeneration)
        ->and($spanData->startTimeNano)->toBe(1000000000)
        ->and($spanData->endTimeNano)->toBe(2000000000)
        ->and($spanData->startEvent)->toBe($startEvent)
        ->and($spanData->endEvent)->toBe($endEvent)
        ->and($spanData->events)->toHaveCount(1)
        ->and($spanData->metadata)->toBe(['user_id' => '123'])
        ->and($spanData->exception)->toBeNull();
});

it('uses sensible defaults for optional parameters', function (): void {
    $spanData = TelemetryTestHelpers::createTextGenerationSpanData();

    expect($spanData->events)->toBe([])
        ->and($spanData->exception)->toBeNull()
        ->and($spanData->metadata)->toBe([]);
});

describe('hasError', function (): void {
    it('returns false when exception is null', function (): void {
        $spanData = TelemetryTestHelpers::createTextGenerationSpanData();

        expect($spanData->hasError())->toBeFalse();
    });

    it('returns true when exception is present', function (): void {
        $spanData = TelemetryTestHelpers::createTextGenerationSpanData(
            exception: new RuntimeException('Test error')
        );

        expect($spanData->hasError())->toBeTrue();
    });

    it('returns true for any Throwable type', function (): void {
        $spanData = TelemetryTestHelpers::createTextGenerationSpanData(
            exception: new Error('Fatal error')
        );

        expect($spanData->hasError())->toBeTrue();
    });
});

describe('durationNano', function (): void {
    it('calculates duration in nanoseconds', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 2500000000);

        expect($spanData->durationNano())->toBe(1500000000);
    });

    it('returns zero for same start and end time', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 1000000000);

        expect($spanData->durationNano())->toBe(0);
    });

    it('handles very small durations (1 nanosecond)', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 1000000001);

        expect($spanData->durationNano())->toBe(1);
    });
});

describe('durationMs', function (): void {
    it('calculates duration in milliseconds', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 2500000000);

        expect($spanData->durationMs())->toBe(1500.0);
    });

    it('returns float for sub-millisecond precision', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 1000500000);

        expect($spanData->durationMs())->toBe(0.5);
    });

    it('returns zero for same start and end time', function (): void {
        $spanData = createSpanDataWithTimes(1000000000, 1000000000);

        expect($spanData->durationMs())->toBe(0.0);
    });
});

describe('metadata', function (): void {
    it('supports metadata with user context', function (): void {
        $spanData = TelemetryTestHelpers::createTextGenerationSpanData(
            metadata: [
                'user_id' => '123',
                'session_id' => 'session-abc',
                'custom' => 'value',
            ]
        );

        expect($spanData->metadata['user_id'])->toBe('123')
            ->and($spanData->metadata['session_id'])->toBe('session-abc')
            ->and($spanData->metadata['custom'])->toBe('value');
    });

    it('supports empty metadata', function (): void {
        $spanData = TelemetryTestHelpers::createTextGenerationSpanData();

        expect($spanData->metadata)->toBe([]);
    });
});

describe('events', function (): void {
    it('supports multiple events and preserves order', function (): void {
        $startEvent = TelemetryTestHelpers::createTextGenerationStarted();
        $endEvent = TelemetryTestHelpers::createTextGenerationCompleted();

        $spanData = new SpanData(
            spanId: 'span-123',
            traceId: 'trace-456',
            parentSpanId: null,
            operation: TelemetryOperation::TextGeneration,
            startTimeNano: 1000000000,
            endTimeNano: 2000000000,
            startEvent: $startEvent,
            endEvent: $endEvent,
            events: [
                ['name' => 'first', 'timeNanos' => 1100000000, 'attributes' => ['size' => 10]],
                ['name' => 'second', 'timeNanos' => 1200000000, 'attributes' => ['size' => 15]],
                ['name' => 'third', 'timeNanos' => 1300000000, 'attributes' => []],
            ],
        );

        expect($spanData->events)->toHaveCount(3)
            ->and($spanData->events[0]['name'])->toBe('first')
            ->and($spanData->events[1]['name'])->toBe('second')
            ->and($spanData->events[2]['name'])->toBe('third');
    });
});

function createSpanDataWithTimes(int $startTimeNano, int $endTimeNano): SpanData
{
    $startEvent = TelemetryTestHelpers::createTextGenerationStarted(timeNanos: $startTimeNano);
    $endEvent = TelemetryTestHelpers::createTextGenerationCompleted(timeNanos: $endTimeNano);

    return new SpanData(
        spanId: 'span-123',
        traceId: 'trace-456',
        parentSpanId: null,
        operation: TelemetryOperation::TextGeneration,
        startTimeNano: $startTimeNano,
        endTimeNano: $endTimeNano,
        startEvent: $startEvent,
        endEvent: $endEvent,
    );
}
