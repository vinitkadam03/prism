<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\Request;

/**
 * Dispatched when a step in streaming text generation completes.
 *
 * @property-read string $spanId Unique identifier for this span (16 hex chars)
 * @property-read string $traceId Trace identifier linking related spans (32 hex chars)
 * @property-read string|null $parentSpanId Parent span ID for nested operations
 * @property-read Request $request The streaming request
 * @property-read StepFinishEvent $stepFinish The step finish event
 * @property-read array<StreamEvent> $events All streaming events collected during this step
 * @property-read int $timeNanos Unix epoch timestamp in nanoseconds
 */
class StreamStepCompleted
{
    use Dispatchable;

    public readonly int $timeNanos;

    public function __construct(
        public readonly string $spanId,
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        public readonly Request $request,
        public readonly StepFinishEvent $stepFinish,
        /** @var array<StreamEvent> */
        public readonly array $events = [],
        ?int $timeNanos = null,
    ) {
        $this->timeNanos = $timeNanos ?? now_nanos();
    }
}
