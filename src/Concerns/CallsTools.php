<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Generator;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\MultipleItemsFoundException;
use JsonException;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Structured\Request;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolApprovalResponseMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
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
     * @return Generator<ToolResultEvent|ArtifactEvent|ToolApprovalRequestEvent>
     */
    protected function callToolsAndYieldEvents(array $tools, array $toolCalls, string $messageId, array &$toolResults, bool &$hasPendingToolCalls): Generator
    {
        $approvalRequiredToolCalls = [];
        $serverToolCalls = $this->filterServerExecutedToolCalls($tools, $toolCalls, $hasPendingToolCalls, $approvalRequiredToolCalls);

        foreach ($approvalRequiredToolCalls as $toolCall) {
            yield new ToolApprovalRequestEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolCall: $toolCall,
                messageId: $messageId,
            );
        }

        $groupedToolCalls = $this->groupToolCallsByConcurrency($tools, $serverToolCalls);

        $executionResults = $this->executeToolsWithConcurrency($tools, $groupedToolCalls, $messageId);

        foreach (collect($executionResults)->keys()->sort() as $index) {
            $result = $executionResults[$index];

            $toolResults[] = $result['toolResult'];

            foreach ($result['events'] as $event) {
                yield $event;
            }
        }
    }

    /**
     * Filter out client-executed and approval-required tool calls, setting the pending flag if any are found.
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @param  ToolCall[]  $approvalRequiredToolCalls  Collected approval-required tool calls (by reference)
     * @return array<int, ToolCall> Server-executed tool calls with original indices preserved
     */
    protected function filterServerExecutedToolCalls(array $tools, array $toolCalls, bool &$hasPendingToolCalls, array &$approvalRequiredToolCalls = []): array
    {
        $serverToolCalls = [];

        foreach ($toolCalls as $index => $toolCall) {
            try {
                $tool = $this->resolveTool($toolCall->name, $tools);

                if ($tool->isClientExecuted()) {
                    $hasPendingToolCalls = true;

                    continue;
                }

                if ($tool->needsApproval($toolCall->arguments())) {
                    $hasPendingToolCalls = true;
                    $approvalRequiredToolCalls[] = $toolCall;

                    continue;
                }

                $serverToolCalls[$index] = $toolCall;
            } catch (PrismException) {
                // Unknown tool - keep it so error handling works in executeToolCall
                $serverToolCalls[$index] = $toolCall;
            }
        }

        return $serverToolCalls;
    }

    /**
     * @param  Tool[]  $tools
     * @param  array<int, ToolCall>  $toolCalls
     * @return array{concurrent: array<int, ToolCall>, sequential: array<int, ToolCall>}
     */
    protected function groupToolCallsByConcurrency(array $tools, array $toolCalls): array
    {
        $concurrent = [];
        $sequential = [];

        foreach ($toolCalls as $index => $toolCall) {
            try {
                $tool = $this->resolveTool($toolCall->name, $tools);

                if ($tool->isConcurrent()) {
                    $concurrent[$index] = $toolCall;
                } else {
                    $sequential[$index] = $toolCall;
                }
            } catch (PrismException) {
                $sequential[$index] = $toolCall;
            }
        }

        return [
            'concurrent' => $concurrent,
            'sequential' => $sequential,
        ];
    }

    /**
     * @param  Tool[]  $tools
     * @param  array{concurrent: array<int, ToolCall>, sequential: array<int, ToolCall>}  $groupedToolCalls
     * @return array<int, array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>}>
     */
    protected function executeToolsWithConcurrency(array $tools, array $groupedToolCalls, string $messageId): array
    {
        $results = [];

        $concurrentClosures = [];

        foreach ($groupedToolCalls['concurrent'] as $index => $toolCall) {
            $concurrentClosures[$index] = fn () => $this->executeToolCall($tools, $toolCall, $messageId);
        }

        if ($concurrentClosures !== []) {
            foreach (Concurrency::run($concurrentClosures) as $index => $result) {
                $results[$index] = $result;
            }
        }

        foreach ($groupedToolCalls['sequential'] as $index => $toolCall) {
            $results[$index] = $this->executeToolCall($tools, $toolCall, $messageId);
        }

        return $results;
    }

    /**
     * @param  Tool[]  $tools
     * @return array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>}
     */
    protected function executeToolCall(array $tools, ToolCall $toolCall, string $messageId): array
    {
        $events = [];

        try {
            $tool = $this->resolveTool($toolCall->name, $tools);
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
     * Resolve pending tool approvals from a previous request (non-streaming).
     *
     * Scans request messages for a ToolApprovalResponseMessage. If found, executes
     * approved tools, creates denial results for denied/missing tools, and replaces
     * the ToolApprovalResponseMessage with a ToolResultMessage in the request.
     *
     */
    protected function resolveToolApprovals(\Prism\Prism\Structured\Request|\Prism\Prism\Text\Request $request): array
    {
        $toolResults = [];

        foreach ($this->resolveToolApprovalsAndYieldEvents($request, EventID::generate(), $toolResults) as $event) {
            // Events are discarded for non-streaming handlers
        }

        return $toolResults;
    }

    /**
     * Resolve pending tool approvals and yield events (streaming variant).
     */
    protected function resolveToolApprovalsAndYieldEvents(\Prism\Prism\Structured\Request|\Prism\Prism\Text\Request $request, string $messageId, array &$toolResults): Generator
    {
        $messages = $request->messages();

        $approvalMessageIndex = null;
        $approvalMessage = null;

        foreach ($messages as $index => $message) {
            if ($message instanceof ToolApprovalResponseMessage) {
                $approvalMessageIndex = $index;
                $approvalMessage = $message;
            }
        }

        if ($approvalMessage === null) {
            return;
        }

        $assistantMessage = null;

        for ($i = $approvalMessageIndex - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof AssistantMessage && $messages[$i]->toolCalls !== []) {
                $assistantMessage = $messages[$i];

                break;
            }
        }

        if ($assistantMessage === null) {
            return;
        }

        foreach ($assistantMessage->toolCalls as $toolCall) {
            $approval = $approvalMessage->findByToolCallId($toolCall->id);

            if ($approval !== null && $approval->approved) {
                $result = $this->executeToolCall($request->tools(), $toolCall, $messageId);

                $toolResults[] = $result['toolResult'];

                foreach ($result['events'] as $event) {
                    yield $event;
                }

                continue;
            }

            $reason = $approval?->reason ?? 'User denied tool execution';

            $toolResult = new ToolResult(
                toolCallId: $toolCall->id,
                toolName: $toolCall->name,
                args: $toolCall->arguments(),
                result: $reason,
                toolCallResultId: $toolCall->resultId,
            );

            $toolResults[] = $toolResult;

            yield new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $toolResult,
                messageId: $messageId,
                success: false,
                error: $reason,
            );
        }

        $updatedMessages = array_values(array_filter(
            $messages,
            fn (int $index): bool => $index !== $approvalMessageIndex,
            ARRAY_FILTER_USE_KEY,
        ));

        $request->setMessages($updatedMessages);
        $request->addMessage(new ToolResultMessage($toolResults));
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
