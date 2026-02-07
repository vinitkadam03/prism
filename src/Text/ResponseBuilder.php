<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Telemetry\Events\TextStepCompleted;
use Prism\Prism\Telemetry\Events\TextStepStarted;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Usage;

class ResponseBuilder
{
    /** @var Collection<int, Step> */
    public readonly Collection $steps;

    protected ?Request $request = null;

    protected int $stepStartTimeNanos;

    protected ?string $pendingStepSpanId = null;

    protected ?int $stepEndTimeNanos = null;

    public function __construct()
    {
        $this->steps = new Collection;
        $this->stepStartTimeNanos = now_nanos();
    }

    /**
     * Set the request context for telemetry.
     */
    public function forRequest(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Signal that a new step is beginning.
     *
     * Providers CAN call this before sending HTTP requests for real-time telemetry.
     * If not called, telemetry will still work (events dispatched retrospectively).
     */
    public function beginStep(): self
    {
        if (! $this->shouldDispatchTelemetry() || ! $this->request instanceof Request) {
            return $this;
        }

        $this->pendingStepSpanId = bin2hex(random_bytes(8));
        $this->stepStartTimeNanos = now_nanos();

        Event::dispatch(new TextStepStarted(
            spanId: $this->pendingStepSpanId,
            traceId: Context::getHidden('prism.telemetry.trace_id'),
            parentSpanId: Context::getHidden('prism.telemetry.current_span_id'),
            request: $this->request,
            timeNanos: $this->stepStartTimeNanos,
        ));

        return $this;
    }

    /**
     * Mark the step timing as complete (LLM response received).
     *
     * Call this before tool execution to ensure step duration only measures
     * LLM latency, not tool execution time. Tool calls have their own spans.
     */
    public function markStepTimingComplete(): self
    {
        $this->stepEndTimeNanos = now_nanos();

        return $this;
    }

    public function addStep(Step $step): self
    {
        $startTime = $this->stepStartTimeNanos;
        $endTime = $this->stepEndTimeNanos ?? now_nanos();
        $spanId = $this->pendingStepSpanId ?? bin2hex(random_bytes(8));

        $this->steps->push($step);
        $this->dispatchStepTelemetry($step, $spanId, $startTime, $endTime);

        // Reset for next step
        $this->pendingStepSpanId = null;
        $this->stepStartTimeNanos = now_nanos();
        $this->stepEndTimeNanos = null;

        return $this;
    }

    public function toResponse(): Response
    {
        /** @var Step $finalStep */
        $finalStep = $this->steps->last();

        // Build messages collection: input messages + final assistant message
        $messages = collect($finalStep->messages);

        // Include provider_tool_calls in additionalContent if present
        $additionalContent = $finalStep->additionalContent;
        if ($finalStep->providerToolCalls !== []) {
            $additionalContent['provider_tool_calls'] = $finalStep->providerToolCalls;
        }

        $messages->push(new AssistantMessage(
            content: $finalStep->text,
            toolCalls: $finalStep->toolCalls,
            additionalContent: $additionalContent,
        ));

        return new Response(
            steps: $this->steps,
            text: $finalStep->text,
            finishReason: $finalStep->finishReason,
            toolCalls: $finalStep->toolCalls,
            toolResults: $finalStep->toolResults,
            usage: $this->calculateTotalUsage(),
            meta: $finalStep->meta,
            messages: $messages,
            additionalContent: $finalStep->additionalContent,
            raw: $finalStep->raw,
        );
    }

    protected function dispatchStepTelemetry(Step $step, string $spanId, int $startTimeNanos, int $endTimeNanos): void
    {
        if (! $this->shouldDispatchTelemetry() || ! $this->request instanceof Request) {
            return;
        }

        $traceId = Context::getHidden('prism.telemetry.trace_id');
        $parentSpanId = Context::getHidden('prism.telemetry.current_span_id');

        // Only emit start if beginStep() wasn't called (retrospective mode)
        if ($this->pendingStepSpanId === null) {
            Event::dispatch(new TextStepStarted(
                spanId: $spanId,
                traceId: $traceId,
                parentSpanId: $parentSpanId,
                request: $this->request,
                timeNanos: $startTimeNanos,
            ));
        }

        Event::dispatch(new TextStepCompleted(
            spanId: $spanId,
            traceId: $traceId,
            parentSpanId: $parentSpanId,
            request: $this->request,
            step: $step,
            timeNanos: $endTimeNanos,
        ));
    }

    protected function shouldDispatchTelemetry(): bool
    {
        if (! config('prism.telemetry.enabled', false)) {
            return false;
        }

        return $this->request instanceof Request;
    }

    protected function calculateTotalUsage(): Usage
    {
        return new Usage(
            promptTokens: $this
                ->steps
                ->sum(fn (Step $result): int => $result->usage->promptTokens),
            completionTokens: $this
                ->steps
                ->sum(fn (Step $result): int => $result->usage->completionTokens),
            cacheWriteInputTokens: $this->steps->contains(fn (Step $result): bool => $result->usage->cacheWriteInputTokens !== null)
                ? $this->steps->sum(fn (Step $result): int => $result->usage->cacheWriteInputTokens ?? 0)
                : null,
            cacheReadInputTokens: $this->steps->contains(fn (Step $result): bool => $result->usage->cacheReadInputTokens !== null)
                ? $this->steps->sum(fn (Step $result): int => $result->usage->cacheReadInputTokens ?? 0)
                : null,
            thoughtTokens: $this->steps->contains(fn (Step $result): bool => $result->usage->thoughtTokens !== null)
                ? $this->steps->sum(fn (Step $result): int => $result->usage->thoughtTokens ?? 0)
                : null,
        );
    }
}
