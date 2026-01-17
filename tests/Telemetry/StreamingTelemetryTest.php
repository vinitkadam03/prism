<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Telemetry\Events\StreamingCompleted;
use Prism\Prism\Telemetry\Events\StreamingStarted;
use Prism\Prism\Telemetry\Listeners\TelemetryEventListener;
use Prism\Prism\Telemetry\SpanCollector;
use Prism\Prism\Telemetry\SpanData;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Usage;

beforeEach(function (): void {
    config(['prism.telemetry.enabled' => true]);
    Event::fake();
    Context::forgetHidden('prism.telemetry.trace_id');
    Context::forgetHidden('prism.telemetry.current_span_id');
    Context::forgetHidden('prism.telemetry.metadata');
});

describe('event dispatch', function (): void {
    it('creates StreamingStarted event with correct properties', function (): void {
        $request = createStreamingRequest();
        $streamStart = new StreamStartEvent('stream-1', time(), 'gpt-4', 'openai');

        $event = new StreamingStarted(
            spanId: 'stream-span-123',
            traceId: 'stream-trace-123',
            parentSpanId: null,
            request: $request,
            streamStart: $streamStart,
        );

        expect($event->spanId)->toBe('stream-span-123')
            ->and($event->traceId)->toBe('stream-trace-123')
            ->and($event->request)->toBe($request)
            ->and($event->streamStart)->toBe($streamStart);
    });

    it('creates StreamingCompleted event with correct properties', function (): void {
        $request = createStreamingRequest();
        $streamEnd = new StreamEndEvent('stream-1', time(), FinishReason::Stop, new Usage(10, 5));

        $event = new StreamingCompleted(
            spanId: 'stream-span-123',
            traceId: 'stream-trace-123',
            parentSpanId: null,
            request: $request,
            streamEnd: $streamEnd,
        );

        expect($event->spanId)->toBe('stream-span-123')
            ->and($event->request)->toBe($request)
            ->and($event->streamEnd)->toBe($streamEnd);
    });
});

describe('SpanCollector', function (): void {
    it('passes through streaming events and sets correct operation', function (): void {
        $capturedSpan = captureSpanFromCollector();

        expect($capturedSpan)->toBeInstanceOf(SpanData::class)
            ->and($capturedSpan->operation)->toBe(TelemetryOperation::Streaming)
            ->and($capturedSpan->startEvent)->toBeInstanceOf(StreamingStarted::class)
            ->and($capturedSpan->startEvent->request->model())->toBe('gpt-4');
    });

    it('maintains trace context from events', function (): void {
        $capturedSpan = captureSpanFromCollector(
            spanId: 'child-stream-span',
            traceId: 'parent-trace-id',
            parentSpanId: 'parent-span-id'
        );

        expect($capturedSpan->traceId)->toBe('parent-trace-id')
            ->and($capturedSpan->parentSpanId)->toBe('parent-span-id');
    });

    it('preserves telemetry context metadata', function (): void {
        Context::addHidden('prism.telemetry.metadata', [
            'user_id' => 'stream-user-123',
            'session_id' => 'stream-session-456',
        ]);

        $capturedSpan = captureSpanFromCollector();

        expect($capturedSpan->metadata)->toHaveKey('user_id')
            ->and($capturedSpan->metadata['user_id'])->toBe('stream-user-123')
            ->and($capturedSpan->metadata['session_id'])->toBe('stream-session-456');

        Context::forgetHidden('prism.telemetry.metadata');
    });
});

describe('TelemetryEventListener', function (): void {
    it('routes streaming events through collector', function (): void {
        $capturedSpan = null;
        $driver = createStreamingMockDriver($capturedSpan);
        $collector = new SpanCollector($driver);
        $listener = new TelemetryEventListener($collector);

        $request = createStreamingRequest();
        $streamStart = new StreamStartEvent('stream-1', time(), 'gpt-4', 'openai');

        $listener->handleStreamingStarted(new StreamingStarted(
            spanId: 'stream-span-123',
            traceId: 'stream-trace-123',
            parentSpanId: null,
            request: $request,
            streamStart: $streamStart,
        ));

        $listener->handleStreamingCompleted(new StreamingCompleted(
            spanId: 'stream-span-123',
            traceId: 'stream-trace-123',
            parentSpanId: null,
            request: $request,
            streamEnd: new StreamEndEvent('stream-1', time(), FinishReason::Stop, new Usage(10, 5)),
        ));

        expect($capturedSpan)->toBeInstanceOf(SpanData::class)
            ->and($capturedSpan->operation)->toBe(TelemetryOperation::Streaming);
    });
});

function createStreamingRequest(): Request
{
    return new Request(
        model: 'gpt-4',
        providerKey: 'openai',
        systemPrompts: [],
        prompt: 'Stream this response',
        messages: [],
        maxSteps: 1,
        maxTokens: 200,
        temperature: 0.5,
        topP: 1.0,
        tools: [],
        clientOptions: [],
        clientRetry: [3],
        toolChoice: null,
    );
}

function createStreamingMockDriver(?SpanData &$capturedSpan): TelemetryDriver
{
    $driver = Mockery::mock(TelemetryDriver::class);
    $driver->shouldReceive('recordSpan')
        ->once()
        ->with(Mockery::on(function ($span) use (&$capturedSpan): bool {
            $capturedSpan = $span;

            return $span instanceof SpanData;
        }));

    return $driver;
}

function captureSpanFromCollector(
    string $spanId = 'stream-span-123',
    string $traceId = 'stream-trace-123',
    ?string $parentSpanId = null
): SpanData {
    $capturedSpan = null;
    $driver = createStreamingMockDriver($capturedSpan);
    $collector = new SpanCollector($driver);
    $request = createStreamingRequest();
    $streamStart = new StreamStartEvent('stream-1', time(), 'gpt-4', 'openai');
    $streamEnd = new StreamEndEvent('stream-1', time(), FinishReason::Stop, new Usage(10, 5));

    $collector->startSpan(new StreamingStarted(
        spanId: $spanId,
        traceId: $traceId,
        parentSpanId: $parentSpanId,
        request: $request,
        streamStart: $streamStart,
    ));

    $collector->endSpan(new StreamingCompleted(
        spanId: $spanId,
        traceId: $traceId,
        parentSpanId: $parentSpanId,
        request: $request,
        streamEnd: $streamEnd,
    ));

    return $capturedSpan;
}
