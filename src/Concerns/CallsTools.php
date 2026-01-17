<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Generator;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\MultipleItemsFoundException;
use JsonException;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Telemetry\Events\SpanException;
use Prism\Prism\Telemetry\Events\ToolCallCompleted;
use Prism\Prism\Telemetry\Events\ToolCallStarted;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\ToolResult;

trait CallsTools
{
    /**
     * Execute tools and return results (for non-streaming handlers).
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @return ToolResult[]
     *
     * @throws PrismException|JsonException
     */
    protected function callTools(array $tools, array $toolCalls, bool &$hasPendingToolCalls): array
    {
        $toolResults = [];

        // Consume generator to execute all tools and collect results
        foreach ($this->callToolsAndYieldEvents($tools, $toolCalls, EventID::generate(), $toolResults, $hasPendingToolCalls) as $event) {
            // Events are discarded for non-streaming handlers
        }

        return $toolResults;
    }

    /**
     * Generate tool execution events and collect results (for streaming handlers).
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults  Results are collected into this array by reference
     * @return Generator<ToolResultEvent|ArtifactEvent>
     */
    protected function callToolsAndYieldEvents(array $tools, array $toolCalls, string $messageId, array &$toolResults, bool &$hasPendingToolCalls): Generator
    {
        $resolvedCalls = $this->resolveToolCalls($tools, $toolCalls, $hasPendingToolCalls);

        $executionResults = $this->executeToolCalls($resolvedCalls, $messageId);

        foreach (collect($executionResults)->keys()->sort() as $index) {
            $result = $executionResults[$index];

            $toolResults[] = $result['toolResult'];

            foreach ($result['events'] as $event) {
                yield $event;
            }
        }
    }

    /**
     * Resolve tool calls to Tool+ToolCall pairs, filtering client-executed tools
     * and grouping by concurrency. Each tool is resolved exactly once.
     *
     * Unresolvable tools (not found, duplicates) are stored with the caught
     * exception so executeSingleToolCall can produce a proper error result.
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @return array{concurrent: array<int, array{tool: Tool, toolCall: ToolCall}>, sequential: array<int, array{tool: ?Tool, toolCall: ToolCall, error?: PrismException}>}
     */
    protected function resolveToolCalls(array $tools, array $toolCalls, bool &$hasPendingToolCalls): array
    {
        $concurrent = [];
        $sequential = [];

        foreach ($toolCalls as $index => $toolCall) {
            try {
                $tool = $this->resolveTool($toolCall->name, $tools);
            } catch (PrismException $e) {
                $sequential[$index] = ['tool' => null, 'toolCall' => $toolCall, 'error' => $e];

                continue;
            }

            if ($tool->isClientExecuted()) {
                $hasPendingToolCalls = true;

                continue;
            }

            $pair = ['tool' => $tool, 'toolCall' => $toolCall];

            if ($tool->isConcurrent()) {
                $concurrent[$index] = $pair;
            } else {
                $sequential[$index] = $pair;
            }
        }

        return [
            'concurrent' => $concurrent,
            'sequential' => $sequential,
        ];
    }

    /**
     * @param  array{concurrent: array<int, array{tool: Tool, toolCall: ToolCall}>, sequential: array<int, array{tool: ?Tool, toolCall: ToolCall, error?: PrismException}>}  $resolvedCalls
     * @return array<int, array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>}>
     */
    protected function executeToolCalls(array $resolvedCalls, string $messageId): array
    {
        $results = [];

        if ($resolvedCalls['concurrent'] !== []) {
            $closures = [];
            foreach ($resolvedCalls['concurrent'] as $index => $pair) {
                $closures[$index] = static fn (): array => self::executeResolvedToolCall($pair['tool'], $pair['toolCall'], $messageId);
            }

            foreach (Concurrency::run($closures) as $index => $result) {
                $results[$index] = $result;
            }
        }

        foreach ($resolvedCalls['sequential'] as $index => $pair) {
            $results[$index] = self::executeResolvedToolCall($pair['tool'], $pair['toolCall'], $messageId, $pair['error'] ?? null);
        }

        $this->emitToolCallTelemetry($resolvedCalls, $results);

        return $results;
    }

    /**
     * Emit telemetry spans for all executed tool calls uniformly.
     *
     * Uses captured startNanos/endNanos from inside each closure for accurate
     * per-tool timing, even for concurrent executions where the actual work
     * happens in separate processes.
     *
     * @param  array{concurrent: array<int, array{tool: Tool, toolCall: ToolCall}>, sequential: array<int, array{tool: ?Tool, toolCall: ToolCall, error?: PrismException}>}  $resolvedCalls
     * @param  array<int, array{toolResult: ToolResult, startNanos: int, endNanos: int, events: array<int, ToolResultEvent|ArtifactEvent>}>  $results
     */
    protected function emitToolCallTelemetry(array $resolvedCalls, array $results): void
    {
        if (! config('prism.telemetry.enabled', false)) {
            return;
        }

        $traceId = Context::getHidden('prism.telemetry.trace_id') ?? bin2hex(random_bytes(16));
        $parentSpanId = Context::getHidden('prism.telemetry.current_span_id');

        Context::addHidden('prism.telemetry.trace_id', $traceId);

        $allCalls = $resolvedCalls['concurrent'] + $resolvedCalls['sequential'];

        foreach ($allCalls as $index => $pair) {
            if (! isset($results[$index])) {
                continue;
            }

            $result = $results[$index];
            $toolCall = $pair['toolCall'];
            $spanId = bin2hex(random_bytes(8));

            try {
                Event::dispatch(new ToolCallStarted(
                    spanId: $spanId,
                    traceId: $traceId,
                    parentSpanId: $parentSpanId,
                    toolCall: $toolCall,
                    timeNanos: $result['startNanos'],
                ));

                Event::dispatch(new ToolCallCompleted(
                    spanId: $spanId,
                    traceId: $traceId,
                    parentSpanId: $parentSpanId,
                    toolCall: $toolCall,
                    toolResult: $result['toolResult'],
                    timeNanos: $result['endNanos'],
                ));
            } catch (\Throwable $e) {
                Event::dispatch(new SpanException($spanId, $e));
            }
        }
    }

    /**
     * Execute a tool call without capturing $this — safe for serialization
     * by Laravel's ProcessDriver which uses SerializableClosure.
     *
     * When $error is provided (tool resolution failed), skips execution and
     * returns a failed result directly.
     *
     * @return array{toolResult: ToolResult, startNanos: int, endNanos: int, events: array<int, ToolResultEvent|ArtifactEvent>}
     */
    protected static function executeResolvedToolCall(?Tool $tool, ToolCall $toolCall, string $messageId, ?PrismException $error = null): array
    {
        $startNanos = now_nanos();
        $events = [];

        try {
            if ($error instanceof PrismException) {
                throw $error;
            }

            if (! $tool instanceof Tool) {
                throw new PrismException("Tool [{$toolCall->name}] could not be resolved");
            }

            $output = call_user_func_array(
                $tool->handle(...),
                $toolCall->arguments()
            );

            if (is_string($output)) {
                $output = new ToolOutput(result: $output);
            }

            $toolResult = new ToolResult(
                toolCallId: $toolCall->id,
                toolName: $toolCall->name,
                args: $toolCall->arguments(),
                result: $output->result,
                toolCallResultId: $toolCall->resultId,
                artifacts: $output->artifacts,
            );

            $events[] = new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $toolResult,
                messageId: $messageId,
                success: true
            );

            foreach ($toolResult->artifacts as $artifact) {
                $events[] = new ArtifactEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    artifact: $artifact,
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    messageId: $messageId,
                );
            }

            return [
                'toolResult' => $toolResult,
                'startNanos' => $startNanos,
                'endNanos' => now_nanos(),
                'events' => $events,
            ];
        } catch (PrismException $e) {
            $toolResult = new ToolResult(
                toolCallId: $toolCall->id,
                toolName: $toolCall->name,
                args: $toolCall->arguments(),
                result: $e->getMessage(),
                toolCallResultId: $toolCall->resultId,
            );

            $events[] = new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $toolResult,
                messageId: $messageId,
                success: false,
                error: $e->getMessage()
            );

            return [
                'toolResult' => $toolResult,
                'events' => $events,
                'startNanos' => $startNanos,
                'endNanos' => now_nanos(),
            ];
        }
    }

    /**
     * Yield stream completion events when client-executed tools are pending.
     *
     * @return Generator<StepFinishEvent|StreamEndEvent>
     */
    protected function yieldToolCallsFinishEvents(StreamState $state): Generator
    {
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        yield new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: FinishReason::ToolCalls,
            usage: $state->usage(),
            citations: $state->citations(),
        );
    }

    /**
     * @param  Tool[]  $tools
     *
     * @throws PrismException
     */
    protected function resolveTool(string $name, array $tools): Tool
    {
        try {
            return collect($tools)
                ->sole(fn (Tool $tool): bool => $tool->name() === $name);
        } catch (ItemNotFoundException $e) {
            throw PrismException::toolNotFound($name, $e);
        } catch (MultipleItemsFoundException $e) {
            throw PrismException::multipleToolsFound($name, $e);
        }
    }
}
