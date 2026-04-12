<?php

declare(strict_types=1);

use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Telemetry\Drivers\NullDriver;
use Prism\Prism\Telemetry\SpanData;
use Tests\Telemetry\Helpers\TelemetryTestHelpers;

it('does not throw when recording a span', function (): void {
    $driver = new NullDriver;
    $spanData = TelemetryTestHelpers::createTextGenerationSpanData();

    $driver->recordSpan($spanData);

    expect(true)->toBeTrue();
});

it('handles spans with exceptions gracefully', function (): void {
    $driver = new NullDriver;
    $spanData = TelemetryTestHelpers::createTextGenerationSpanData(
        exception: new Exception('Test exception')
    );

    $driver->recordSpan($spanData);

    expect(true)->toBeTrue();
});

it('handles spans with events gracefully', function (): void {
    $driver = new NullDriver;

    $startEvent = TelemetryTestHelpers::createTextGenerationStarted();
    $endEvent = TelemetryTestHelpers::createTextGenerationCompleted();

    $spanData = new SpanData(
        spanId: 'test-span-id',
        traceId: bin2hex(random_bytes(16)),
        parentSpanId: null,
        operation: TelemetryOperation::TextGeneration,
        startTimeNano: (int) (microtime(true) * 1_000_000_000),
        endTimeNano: (int) (microtime(true) * 1_000_000_000) + 100_000_000,
        startEvent: $startEvent,
        endEvent: $endEvent,
        events: [
            ['name' => 'event1', 'timeNanos' => 1000, 'attributes' => []],
            ['name' => 'event2', 'timeNanos' => 2000, 'attributes' => ['key' => 'value']],
        ],
    );

    $driver->recordSpan($spanData);

    expect(true)->toBeTrue();
});
