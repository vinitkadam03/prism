<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Illuminate\Support\Facades\Context;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationCompleted;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationStarted;
use Prism\Prism\Telemetry\Events\SpanException;
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

/**
 * Manages span lifecycle and passes domain objects to drivers.
 *
 * This collector acts as a thin coordination layer - it tracks pending spans
 * and passes the original events (with their domain objects) to drivers.
 * Drivers/mappers are responsible for extracting whatever attributes they need.
 */
class SpanCollector
{
    /** @var array<string, PendingSpanData> */
    protected array $pendingSpans = [];

    public function __construct(
        protected TelemetryDriver $driver
    ) {}

    public function startSpan(StreamingStarted|TextGenerationStarted|TextStepStarted|StreamStepStarted|ToolCallStarted|StructuredOutputStarted|EmbeddingGenerationStarted $event): string
    {
        $spanId = $event->spanId;

        $pending = new PendingSpanData(
            spanId: $spanId,
            traceId: $event->traceId,
            parentSpanId: $event->parentSpanId,
            operation: TelemetryOperation::fromStartEvent($event),
            startTimeNano: $event->timeNanos,
            startEvent: $event,
        );

        // Add user metadata from Laravel hidden Context (set by HasTelemetryContext trait)
        $metadata = Context::getHidden('prism.telemetry.metadata');
        if (! empty($metadata)) {
            $pending->setMetadata($metadata);
        }

        $this->pendingSpans[$spanId] = $pending;

        return $spanId;
    }

    public function endSpan(TextGenerationCompleted|StructuredOutputCompleted|EmbeddingGenerationCompleted|StreamingCompleted|StreamStepCompleted|TextStepCompleted|ToolCallCompleted $event): void
    {
        if (! isset($this->pendingSpans[$event->spanId])) {
            return;
        }

        $pending = $this->pendingSpans[$event->spanId];

        $this->driver->recordSpan($pending->toSpanData($event->timeNanos, $event));

        unset($this->pendingSpans[$event->spanId]);
    }

    /**
     * Shutdown the telemetry driver, flushing any buffered spans.
     *
     * Should be called when a Prism operation completes.
     */
    public function shutdown(): void
    {
        $this->driver->shutdown();
        $this->pendingSpans = [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addEvent(string $spanId, string $name, int $timeNanos, array $attributes = []): void
    {
        ($this->pendingSpans[$spanId] ?? null)?->addEvent($name, $timeNanos, $attributes);
    }

    public function recordException(SpanException $event): void
    {
        ($this->pendingSpans[$event->spanId] ?? null)?->setException($event->exception, $event->timeNanos);
    }
}
