<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Text\Request;

/**
 * Dispatched when a step in streaming text generation begins.
 *
 * @property-read string $spanId Unique identifier for this span (16 hex chars)
 * @property-read string $traceId Trace identifier linking related spans (32 hex chars)
 * @property-read string|null $parentSpanId Parent span ID for nested operations
 * @property-read Request $request The streaming request
 * @property-read StepStartEvent $stepStart The step start event
 * @property-read int $timeNanos Unix epoch timestamp in nanoseconds
 */
class StreamStepStarted
{
    use Dispatchable;

    public readonly int $timeNanos;

    public function __construct(
        public readonly string $spanId,
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        public readonly Request $request,
        public readonly StepStartEvent $stepStart,
        ?int $timeNanos = null,
    ) {
        $this->timeNanos = $timeNanos ?? now_nanos();
    }
}
