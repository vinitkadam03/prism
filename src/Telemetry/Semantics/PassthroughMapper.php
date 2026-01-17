<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Semantics;

use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Telemetry\Contracts\SemanticMapperInterface;
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
use Prism\Prism\Telemetry\SpanData;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Passthrough mapper that extracts Prism attributes in a human-readable format.
 *
 * Use this for debugging, logging, or when you want raw Prism attributes
 * without OpenInference semantic conversion.
 */
class PassthroughMapper implements SemanticMapperInterface
{
    /**
     * Extract attributes from span data in a human-readable format.
     *
     * @return array<string, mixed>
     */
    public function map(SpanData $span): array
    {
        $attrs = match ($span->operation) {
            TelemetryOperation::TextGeneration => $this->mapTextGeneration($span),
            TelemetryOperation::StreamStep => $this->mapStreamStep($span),
            TelemetryOperation::TextStep => $this->mapTextStep($span),
            TelemetryOperation::ToolCall => $this->mapToolCall($span),
            TelemetryOperation::Embeddings => $this->mapEmbedding($span),
            TelemetryOperation::Streaming => $this->mapStreaming($span),
            TelemetryOperation::StructuredOutput => $this->mapStructuredOutput($span),
        };

        if ($span->metadata !== []) {
            $attrs['metadata'] = $span->metadata;
        }

        return $this->filterNulls($attrs);
    }

    /**
     * @param  array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>  $events
     * @return array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>
     */
    public function mapEvents(array $events): array
    {
        return $events;
    }

    // ========================================================================
    // Span Type Mappers
    // ========================================================================

    /**
     * @return array<string, mixed>
     */
    protected function mapTextGeneration(SpanData $span): array
    {
        /** @var TextGenerationStarted $start */
        $start = $span->startEvent;
        /** @var TextGenerationCompleted $end */
        $end = $span->endEvent;

        $request = $start->request;
        $response = $end->response;

        return [
            'model' => $request->model(),
            'provider' => $request->provider(),
            'temperature' => $request->temperature(),
            'max_tokens' => $request->maxTokens(),
            'top_p' => $request->topP(),
            'max_steps' => $request->maxSteps(),
            'input' => $request->prompt(),
            'messages' => $this->buildMessages($request->systemPrompts(), $request->messages()),
            'tools' => $request->tools() ? $this->buildToolDefinitions($request->tools()) : null,
            'tool_choice' => $request->toolChoice() ? $this->formatToolChoice($request->toolChoice()) : null,
            'output' => $response->text,
            'response_model' => $response->meta->model,
            'response_id' => $response->meta->id,
            'service_tier' => $response->meta->serviceTier,
            'finish_reason' => $response->finishReason->name,
            'usage' => [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
            ],
            'tool_calls' => $response->toolCalls ? array_map(fn (ToolCall $tc): array => [
                'id' => $tc->id,
                'name' => $tc->name,
                'arguments' => $tc->arguments(),
            ], $response->toolCalls) : null,
        ];
    }

    /**
     * Map streaming step spans.
     *
     * @return array<string, mixed>
     */
    protected function mapStreamStep(SpanData $span): array
    {
        /** @var StreamStepStarted $start */
        $start = $span->startEvent;
        /** @var StreamStepCompleted $end */
        $end = $span->endEvent;

        $request = $start->request;

        $attrs = [
            'model' => $request->model(),
            'provider' => $request->provider(),
            'temperature' => $request->temperature(),
            'max_tokens' => $request->maxTokens(),
            'top_p' => $request->topP(),
            'max_steps' => $request->maxSteps(),
            'input' => $request->prompt(),
            'messages' => $this->buildMessages($request->systemPrompts(), $request->messages()),
            'tools' => $request->tools() ? $this->buildToolDefinitions($request->tools()) : null,
            'tool_choice' => $request->toolChoice() ? $this->formatToolChoice($request->toolChoice()) : null,
        ];

        // Add step start metadata (id, timestamp)
        $attrs = array_merge($attrs, $start->stepStart->toArray());

        // Extract output from streaming events
        $text = $this->collectTextFromEvents($end->events);
        $toolCalls = $this->collectToolCallsFromEvents($end->events);

        if ($text !== '') {
            $attrs['output'] = $text;
        }

        if ($toolCalls !== []) {
            $attrs['tool_calls'] = array_map(fn (ToolCall $tc): array => [
                'id' => $tc->id,
                'name' => $tc->name,
                'arguments' => $tc->arguments(),
            ], $toolCalls);
        }

        return $attrs;
    }

    /**
     * Map text step spans.
     *
     * @return array<string, mixed>
     */
    protected function mapTextStep(SpanData $span): array
    {
        /** @var TextStepStarted $start */
        $start = $span->startEvent;
        /** @var TextStepCompleted $end */
        $end = $span->endEvent;

        $request = $start->request;
        $step = $end->step;

        return [
            'model' => $request->model(),
            'provider' => $request->provider(),
            'temperature' => $request->temperature(),
            'max_tokens' => $request->maxTokens(),
            'top_p' => $request->topP(),
            'max_steps' => $request->maxSteps(),
            'input' => $request->prompt(),
            'messages' => $this->buildMessages($step->systemPrompts, $step->messages),
            'tools' => $request->tools() ? $this->buildToolDefinitions($request->tools()) : null,
            'tool_choice' => $request->toolChoice() ? $this->formatToolChoice($request->toolChoice()) : null,
            'output' => $step->text,
            'response_model' => $step->meta->model,
            'response_id' => $step->meta->id,
            'service_tier' => $step->meta->serviceTier,
            'finish_reason' => $step->finishReason->name,
            'usage' => [
                'prompt_tokens' => $step->usage->promptTokens,
                'completion_tokens' => $step->usage->completionTokens,
            ],
            'tool_calls' => $step->toolCalls ? array_map(fn (ToolCall $tc): array => [
                'id' => $tc->id,
                'name' => $tc->name,
                'arguments' => $tc->arguments(),
            ], $step->toolCalls) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapToolCall(SpanData $span): array
    {
        /** @var ToolCallStarted $start */
        $start = $span->startEvent;
        /** @var ToolCallCompleted $end */
        $end = $span->endEvent;

        $toolCall = $start->toolCall;
        $toolResult = $end->toolResult;

        return [
            'tool' => [
                'name' => $toolCall->name,
                'call_id' => $toolCall->id,
                'arguments' => $toolCall->arguments(),
            ],
            'input' => $toolCall->arguments(),
            'output' => $toolResult->result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapEmbedding(SpanData $span): array
    {
        /** @var EmbeddingGenerationStarted $start */
        $start = $span->startEvent;
        /** @var EmbeddingGenerationCompleted $end */
        $end = $span->endEvent;

        $request = $start->request;
        $response = $end->response;

        return [
            'model' => $request->model(),
            'provider' => $request->provider(),
            'inputs' => $request->inputs(),
            'usage' => ['tokens' => $response->usage->tokens],
            'embedding_count' => count($response->embeddings),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapStreaming(SpanData $span): array
    {
        /** @var StreamingStarted $start */
        $start = $span->startEvent;
        /** @var StreamingCompleted $end */
        $end = $span->endEvent;

        $request = $start->request;

        $attrs = [
            'model' => $request->model(),
            'provider' => $request->provider(),
            'temperature' => $request->temperature(),
            'max_tokens' => $request->maxTokens(),
            'top_p' => $request->topP(),
            'max_steps' => $request->maxSteps(),
            'input' => $request->prompt(),
            'messages' => $this->buildMessages($request->systemPrompts(), $request->messages()),
            'tools' => $request->tools() ? $this->buildToolDefinitions($request->tools()) : null,
            'tool_choice' => $request->toolChoice() ? $this->formatToolChoice($request->toolChoice()) : null,
        ];

        $attrs['response_model'] = $start->streamStart->model;

        // Extract output from streaming events
        $text = $this->collectTextFromEvents($end->events);
        $toolCalls = $this->collectToolCallsFromEvents($end->events);

        if ($text !== '') {
            $attrs['output'] = $text;
        }

        if ($toolCalls !== []) {
            $attrs['tool_calls'] = array_map(fn (ToolCall $tc): array => [
                'id' => $tc->id,
                'name' => $tc->name,
                'arguments' => $tc->arguments(),
            ], $toolCalls);
        }

        // Add stream end metadata
        $streamEndData = $end->streamEnd->toArray();
        $attrs['finish_reason'] = $streamEndData['finish_reason'] ?? null;
        $attrs['usage'] = $streamEndData['usage'] ?? null;
        $attrs['response_id'] = $streamEndData['id'] ?? null;

        return $attrs;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapStructuredOutput(SpanData $span): array
    {
        /** @var StructuredOutputStarted $start */
        $start = $span->startEvent;
        /** @var StructuredOutputCompleted $end */
        $end = $span->endEvent;

        $request = $start->request;
        $response = $end->response;

        return [
            'model' => $request->model(),
            'provider' => $request->provider(),
            'temperature' => $request->temperature(),
            'max_tokens' => $request->maxTokens(),
            'top_p' => $request->topP(),
            'max_steps' => $request->maxSteps(),
            'input' => $request->prompt(),
            'messages' => $this->buildMessages($request->systemPrompts(), $request->messages()),
            'schema' => [
                'name' => $request->schema()->name(),
                'definition' => $request->schema()->toArray(),
            ],
            'tools' => $request->tools() ? $this->buildToolDefinitions($request->tools()) : null,
            'tool_choice' => $request->toolChoice() ? $this->formatToolChoice($request->toolChoice()) : null,
            'output' => $response->structured,
            'response_model' => $response->meta->model,
            'response_id' => $response->meta->id,
            'service_tier' => $response->meta->serviceTier,
            'finish_reason' => $response->finishReason->name,
            'usage' => [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
            ],
        ];
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * @param  array<StreamEvent>  $events
     */
    protected function collectTextFromEvents(array $events): string
    {
        $text = '';
        foreach ($events as $event) {
            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }
        }

        return $text;
    }

    /**
     * @param  array<StreamEvent>  $events
     * @return array<ToolCall>
     */
    protected function collectToolCallsFromEvents(array $events): array
    {
        $toolCalls = [];
        foreach ($events as $event) {
            if ($event instanceof ToolCallEvent) {
                $toolCalls[] = $event->toolCall;
            }
        }

        return $toolCalls;
    }

    /**
     * @param  SystemMessage[]  $systemPrompts
     * @param  Message[]  $messages
     * @return array<int, array{role: string, content: string, tool_calls?: array<int, array{id: string, name: string, arguments: array<string, mixed>}>}>
     */
    protected function buildMessages(array $systemPrompts, array $messages): array
    {
        $result = [];

        foreach ($systemPrompts as $prompt) {
            $result[] = ['role' => 'system', 'content' => $prompt->content];
        }

        foreach ($messages as $message) {
            foreach ($this->formatMessage($message) as $formatted) {
                $result[] = $formatted;
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{role: string, content: string, tool_calls?: array<int, array{id: string, name: string, arguments: array<string, mixed>}>}>
     */
    protected function formatMessage(Message $message): array
    {
        return match (true) {
            $message instanceof UserMessage => [['role' => 'user', 'content' => $message->text()]],
            $message instanceof AssistantMessage => [array_filter([
                'role' => 'assistant',
                'content' => $message->content,
                'tool_calls' => $message->toolCalls ? array_map(fn (ToolCall $tc): array => [
                    'id' => $tc->id,
                    'name' => $tc->name,
                    'arguments' => $tc->arguments(),
                ], $message->toolCalls) : null,
            ], fn ($v): bool => $v !== null)],
            $message instanceof ToolResultMessage => array_map(function (ToolResult $tr): array {
                $content = is_string($tr->result) ? $tr->result : json_encode($tr->result);

                return [
                    'role' => 'tool',
                    'tool_call_id' => $tr->toolCallId,
                    'content' => $content !== false ? $content : '',
                ];
            }, $message->toolResults),
            $message instanceof SystemMessage => [['role' => 'system', 'content' => $message->content]],
            default => [],
        };
    }

    /**
     * @param  Tool[]  $tools
     * @return array<int, array{name: string, description: string, parameters: array{type: string, properties: array<string, mixed>, required: array<int, string>}}>
     */
    protected function buildToolDefinitions(array $tools): array
    {
        return array_map(fn (Tool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => [
                'type' => 'object',
                'properties' => $tool->parametersAsArray(),
                'required' => $tool->requiredParameters(),
            ],
        ], $tools);
    }

    /**
     * @return array{type: string, tool_name?: string}
     */
    protected function formatToolChoice(string|ToolChoice $toolChoice): array
    {
        return is_string($toolChoice)
            ? ['type' => 'tool', 'tool_name' => $toolChoice]
            : ['type' => strtolower($toolChoice->name)];
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    protected function filterNulls(array $array): array
    {
        return array_filter($array, fn ($v): bool => $v !== null);
    }
}
