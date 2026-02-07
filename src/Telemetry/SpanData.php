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
use Throwable;

/**
 * Immutable DTO representing a complete telemetry span.
 *
 * Contains the original start/end events with full access to domain objects
 * (Request, Response, ToolCall, etc.). Drivers can extract whatever attributes
 * they need directly from these typed events.
 */
readonly class SpanData
{
    /**
     * @param  string  $spanId  Unique identifier for this span
     * @param  string  $traceId  Trace identifier linking related spans
     * @param  string|null  $parentSpanId  Parent span ID for hierarchical traces
     * @param  TelemetryOperation  $operation  The operation type
     * @param  int  $startTimeNano  Start time in Unix epoch nanoseconds
     * @param  int  $endTimeNano  End time in Unix epoch nanoseconds
     * @param  TextGenerationStarted|StreamingStarted|StreamStepStarted|TextStepStarted|ToolCallStarted|StructuredOutputStarted|EmbeddingGenerationStarted  $startEvent  The original start event with request data
     * @param  TextGenerationCompleted|StreamingCompleted|StreamStepCompleted|TextStepCompleted|ToolCallCompleted|StructuredOutputCompleted|EmbeddingGenerationCompleted  $endEvent  The original end event with response data
     * @param  array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>  $events  Mid-span events
     * @param  Throwable|null  $exception  Exception if the span failed
     * @param  array<string, mixed>  $metadata  User-provided telemetry context (user_id, session_id, tags, etc.)
     */
    public function __construct(
        public string $spanId,
        public string $traceId,
        public ?string $parentSpanId,
        public TelemetryOperation $operation,
        public int $startTimeNano,
        public int $endTimeNano,
        public TextGenerationStarted|StreamingStarted|StreamStepStarted|TextStepStarted|ToolCallStarted|StructuredOutputStarted|EmbeddingGenerationStarted $startEvent,
        public TextGenerationCompleted|StreamingCompleted|StreamStepCompleted|TextStepCompleted|ToolCallCompleted|StructuredOutputCompleted|EmbeddingGenerationCompleted $endEvent,
        public array $events = [],
        public ?Throwable $exception = null,
        public array $metadata = [],
    ) {}

    /**
     * Check if the span has an error.
     */
    public function hasError(): bool
    {
        return $this->exception instanceof Throwable;
    }

    /**
     * Get the duration in nanoseconds.
     */
    public function durationNano(): int
    {
        return $this->endTimeNano - $this->startTimeNano;
    }

    /**
     * Get the duration in milliseconds.
     */
    public function durationMs(): float
    {
        return $this->durationNano() / 1_000_000;
    }
}
