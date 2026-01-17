<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Prism\Prism\Enums\TelemetryOperation;
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

/**
 * Mutable builder for collecting span data during its lifecycle.
 *
 * This class accumulates telemetry data while a span is in progress,
 * then converts to an immutable SpanData when the span completes.
 *
 * @internal This is an internal implementation detail. Driver authors
 *           should only interact with the immutable SpanData class.
 */
class PendingSpanData
{
    /** @var array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}> */
    protected array $events = [];

    protected ?\Throwable $exception = null;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    public function __construct(
        public readonly string $spanId,
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        public readonly TelemetryOperation $operation,
        public readonly int $startTimeNano,
        public readonly TextGenerationStarted|StreamingStarted|StreamStepStarted|TextStepStarted|ToolCallStarted|StructuredOutputStarted|EmbeddingGenerationStarted $startEvent,
    ) {}

    /**
     * Set user-provided telemetry metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Add an event to this span.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addEvent(string $name, int $timeNanos, array $attributes = []): self
    {
        $this->events[] = [
            'name' => $name,
            'timeNanos' => $timeNanos,
            'attributes' => $attributes,
        ];

        return $this;
    }

    /**
     * Record an exception on this span.
     */
    public function setException(\Throwable $exception, int $timeNanos): self
    {
        $this->exception = $exception;

        $this->addEvent('exception', $timeNanos, [
            'type' => $exception::class,
            'message' => $exception->getMessage(),
            'stacktrace' => $exception->getTraceAsString(),
        ]);

        return $this;
    }

    /**
     * Convert this pending span to an immutable SpanData.
     *
     * @param  int  $endTimeNano  The end time in Unix epoch nanoseconds
     * @param  TextGenerationCompleted|StreamingCompleted|StreamStepCompleted|TextStepCompleted|ToolCallCompleted|StructuredOutputCompleted|EmbeddingGenerationCompleted  $endEvent  The end event with response data
     */
    public function toSpanData(
        int $endTimeNano,
        TextGenerationCompleted|StreamingCompleted|StreamStepCompleted|TextStepCompleted|ToolCallCompleted|StructuredOutputCompleted|EmbeddingGenerationCompleted $endEvent
    ): SpanData {
        return new SpanData(
            spanId: $this->spanId,
            traceId: $this->traceId,
            parentSpanId: $this->parentSpanId,
            operation: $this->operation,
            startTimeNano: $this->startTimeNano,
            endTimeNano: $endTimeNano,
            startEvent: $this->startEvent,
            endEvent: $endEvent,
            events: $this->events,
            exception: $this->exception,
            metadata: $this->metadata,
        );
    }
}
