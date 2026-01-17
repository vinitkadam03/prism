<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Step;

/**
 * Dispatched when a step in non-streaming text generation completes.
 *
 * @property-read string $spanId Unique identifier for this span (16 hex chars)
 * @property-read string $traceId Trace identifier linking related spans (32 hex chars)
 * @property-read string|null $parentSpanId Parent span ID for nested operations
 * @property-read Request $request The text generation request
 * @property-read Step $step The completed step data
 * @property-read int $timeNanos Unix epoch timestamp in nanoseconds
 */
class TextStepCompleted
{
    use Dispatchable;

    public readonly int $timeNanos;

    public function __construct(
        public readonly string $spanId,
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        public readonly Request $request,
        public readonly Step $step,
        ?int $timeNanos = null,
    ) {
        $this->timeNanos = $timeNanos ?? now_nanos();
    }
}
