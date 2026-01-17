<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Semantics;

use JsonException;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Structured\Request as StructuredRequest;
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
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

/**
 * Maps Prism span data to OpenInference semantic conventions.
 *
 * OpenInference is the semantic convention used by Phoenix/Arize for AI observability.
 *
 * @see https://github.com/Arize-ai/openinference/blob/main/spec/semantic_conventions.md
 */
class OpenInferenceMapper implements SemanticMapperInterface
{
    /**
     * Map Prism span data to OpenInference format.
     */
    public function map(SpanData $span): array
    {
        $attrs = match ($span->operation) {
            TelemetryOperation::Streaming => $this->mapStreaming($span),
            TelemetryOperation::StreamStep => $this->mapStreamStep($span),
            TelemetryOperation::TextStep => $this->mapTextStep($span),
            TelemetryOperation::ToolCall => $this->mapToolCall($span),
            TelemetryOperation::TextGeneration => $this->mapTextGeneration($span),
            TelemetryOperation::Embeddings => $this->mapEmbedding($span),
            TelemetryOperation::StructuredOutput => $this->mapStructuredOutput($span),
        };

        $this->addMetadata($attrs, $span->metadata);

        return $this->filterNulls($attrs);
    }

    /**
     * Convert events to OpenInference format.
     *
     * @param  array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>  $events
     * @return array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>
     */
    public function mapEvents(array $events): array
    {
        return array_map(fn (array $e): array => [
            'name' => $e['name'],
            'timeNanos' => $e['timeNanos'],
            'attributes' => $e['name'] === 'exception' ? [
                'exception.type' => $e['attributes']['type'] ?? 'Unknown',
                'exception.message' => $e['attributes']['message'] ?? '',
                'exception.stacktrace' => $e['attributes']['stacktrace'] ?? '',
                'exception.escaped' => true,
            ] : $e['attributes'],
        ], $events);
    }

    // ========================================================================
    // Span Type Mappers
    // ========================================================================

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
            'openinference.span.kind' => 'CHAIN',
            'llm.model_name' => $request->model(),
            'llm.provider' => $request->provider(),
            // TODO: add invocation parameters here as well similar to step
        ];

        $attrs['llm.response.model'] = $start->streamStart->model;

        $messages = $this->buildMessages($request->systemPrompts(), $request->messages());
        $this->addInputMessages($attrs, $messages);
        $this->addToolDefinitions($attrs, $request->tools());

        // Extract output from streaming events
        $text = $this->collectTextFromEvents($end->events);
        $toolCalls = $this->collectToolCallsFromEvents($end->events);

        $this->addInputOutput($attrs, $messages, $text !== '' ? $text : null);

        $attrs['llm.response.id'] = $end->streamEnd->id;
        $this->addTokenUsage($attrs, $end->streamEnd->usage);
        $this->addOutputMessages($attrs, $text, $toolCalls, $end->streamEnd->finishReason->name);

        return $attrs;
    }

    /**
     * Map streaming step spans (LLM span kind).
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
            'openinference.span.kind' => 'LLM',
            'llm.model_name' => $request->model(),
            'llm.provider' => $request->provider(),
            'llm.invocation_parameters' => $this->buildInvocationParams($request),
            'llm.tool_choice' => $request->toolChoice() ? $this->formatToolChoice($request->toolChoice()) : null,
        ];

        // StepStartEvent doesn't include model in toArray(), so no response model to add here // TODO: check this again

        $messages = $this->buildMessages($request->systemPrompts(), $request->messages());
        $this->addInputMessages($attrs, $messages);
        $this->addToolDefinitions($attrs, $request->tools());

        // Extract output from streaming events
        $text = $this->collectTextFromEvents($end->events);
        $toolCalls = $this->collectToolCallsFromEvents($end->events);

        $this->addInputOutput($attrs, $messages, $text !== '' ? $text : null);

        $this->addOutputMessages($attrs, $text, $toolCalls, null);

        // TODO: Add usage for steps if available in future

        return $attrs;
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

        $output = $toolResult->result;

        return [
            'openinference.span.kind' => 'TOOL',
            'tool.name' => $toolCall->name,
            'tool.parameters' => json_encode($toolCall->arguments()),
            'input.value' => json_encode($toolCall->arguments()),
            'input.mime_type' => 'application/json',
            'output.value' => is_string($output) ? $output : json_encode($output),
            'output.mime_type' => is_string($output) ? 'text/plain' : 'application/json',
        ];
    }

    /**
     * Map text generation spans (CHAIN span kind).
     *
     * Text generation is a CHAIN that may contain multiple TextStep LLM spans.
     *
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

        $attrs = [
            'openinference.span.kind' => 'CHAIN',
            'llm.model_name' => $request->model(),
            'llm.response.model' => $response->meta->model,
            'llm.provider' => $request->provider(),
            'llm.service_tier' => $response->meta->serviceTier,
            'llm.invocation_parameters' => $this->buildInvocationParams($request),
            'llm.response.id' => $response->meta->id,
            'llm.tool_choice' => $request->toolChoice() ? $this->formatToolChoice($request->toolChoice()) : null,
        ];

        $messages = $this->buildMessages($request->systemPrompts(), $request->messages());
        $this->addInputMessages($attrs, $messages);
        $this->addInputOutput($attrs, $messages, $response->text);
        $this->addTokenUsage($attrs, $response->usage);
        $this->addOutputMessages($attrs, $response->text, $response->toolCalls, $response->finishReason->name);
        $this->addToolDefinitions($attrs, $request->tools());

        return $attrs;
    }

    /**
     * Map text step spans (LLM span kind).
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

        $attrs = [
            'openinference.span.kind' => 'LLM',
            'llm.model_name' => $request->model(),
            'llm.response.model' => $step->meta->model,
            'llm.provider' => $request->provider(),
            'llm.service_tier' => $step->meta->serviceTier,
            'llm.invocation_parameters' => $this->buildInvocationParams($request),
            'llm.response.id' => $step->meta->id,
            'llm.tool_choice' => $request->toolChoice() ? $this->formatToolChoice($request->toolChoice()) : null,
        ];

        $messages = $this->buildMessages($step->systemPrompts, $step->messages);
        $this->addInputMessages($attrs, $messages);
        $this->addInputOutput($attrs, $messages, $step->text);
        $this->addTokenUsage($attrs, $step->usage);
        $this->addOutputMessages($attrs, $step->text, $step->toolCalls, $step->finishReason->name);
        $this->addToolDefinitions($attrs, $request->tools());

        return $attrs;
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

        $attrs = [
            'openinference.span.kind' => 'EMBEDDING',
            'embedding.model_name' => $request->model(),
            'embedding.provider' => $request->provider(),
        ];

        // Per OpenInference spec: embedding.embeddings.{i}.embedding.text
        foreach ($request->inputs() as $i => $text) {
            $attrs["embedding.embeddings.{$i}.embedding.text"] = $text;
        }

        // Also set input.value as per spec for span-level input
        if ($request->inputs() !== []) {
            $attrs['input.value'] = count($request->inputs()) === 1
                ? $request->inputs()[0]
                : json_encode($request->inputs());
            $attrs['input.mime_type'] = count($request->inputs()) === 1
                ? 'text/plain'
                : 'application/json';
        }

        if ($response->usage->tokens !== null) {
            $this->addEmbeddingTokenUsage($attrs, $response->usage->tokens);
        }

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

        $attrs = [
            'openinference.span.kind' => 'CHAIN',
            'llm.model_name' => $request->model(),
            'llm.response.model' => $response->meta->model,
            'llm.provider' => $request->provider(),
            'llm.service_tier' => $response->meta->serviceTier,
            'llm.response.id' => $response->meta->id,
            'output.schema.name' => $request->schema()->name(),
            'output.schema' => json_encode($request->schema()->toArray()),
        ];

        $messages = $this->buildMessages($request->systemPrompts(), $request->messages());
        $this->addInputMessages($attrs, $messages);
        $this->addInputOutput($attrs, $messages, $response->structured);
        $this->addTokenUsage($attrs, $response->usage);
        $structuredOutput = is_array($response->structured) ? json_encode($response->structured) : '';
        $this->addOutputMessages($attrs, $structuredOutput !== false ? $structuredOutput : '', [], $response->finishReason->name);
        $this->addToolDefinitions($attrs, $request->tools());

        return $attrs;
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

    protected function buildInvocationParams(TextRequest|StructuredRequest $request): ?string
    {
        $params = $this->filterNulls([
            'temperature' => $request->temperature(),
            'max_tokens' => $request->maxTokens(),
            'top_p' => $request->topP(),
        ]);

        return $params !== [] ? (json_encode($params) ?: null) : null;
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
     *
     * @throws JsonException
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
                    'name' => $tr->toolName,
                    'content' => $content !== false ? $content : '',
                ];
            }, $message->toolResults),
            $message instanceof SystemMessage => [['role' => 'system', 'content' => $message->content]],
            default => [],
        };
    }

    protected function formatToolChoice(string|ToolChoice $toolChoice): ?string
    {
        $formatted = is_string($toolChoice)
            ? ['type' => 'tool', 'tool_name' => $toolChoice]
            : ['type' => strtolower($toolChoice->name)];

        return json_encode($formatted) ?: null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $messages
     */
    protected function addInputOutput(array &$attrs, array $messages, mixed $output): void
    {
        if ($messages !== []) {
            $attrs['input.value'] = json_encode($messages);
            $attrs['input.mime_type'] = 'application/json';
        }

        if ($output !== null) {
            $attrs['output.value'] = is_string($output) ? $output : json_encode($output);
            $attrs['output.mime_type'] = is_string($output) ? 'text/plain' : 'application/json';
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function addTokenUsage(array &$attrs, ?Usage $usage): void
    {
        if (! $usage instanceof Usage) {
            return;
        }

        $attrs['llm.token_count.prompt'] = $usage->promptTokens;
        $attrs['llm.token_count.completion'] = $usage->completionTokens;
        $attrs['llm.token_count.total'] = $usage->promptTokens + $usage->completionTokens;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function addEmbeddingTokenUsage(array &$attrs, int $tokens): void
    {
        $attrs['llm.token_count.prompt'] = $tokens;
        $attrs['llm.token_count.total'] = $tokens;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $messages
     */
    protected function addInputMessages(array &$attrs, array $messages): void
    {
        foreach ($messages as $i => $msg) {
            $attrs["llm.input_messages.{$i}.message.role"] = $msg['role'];
            $attrs["llm.input_messages.{$i}.message.content"] = $msg['content'];

            foreach ($msg['tool_calls'] ?? [] as $j => $tc) {
                $prefix = "llm.input_messages.{$i}.message.tool_calls.{$j}.tool_call";
                $attrs["{$prefix}.id"] = $tc['id'] ?? null;
                $attrs["{$prefix}.function.name"] = $tc['name'];
                $attrs["{$prefix}.function.arguments"] = json_encode($tc['arguments']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<ToolCall>  $toolCalls
     */
    protected function addOutputMessages(array &$attrs, string $text, array $toolCalls, ?string $finishReason): void
    {
        if ($text === '' && $toolCalls === []) {
            return;
        }

        $attrs['llm.output_messages.0.message.role'] = 'assistant';
        $attrs['llm.output_messages.0.message.content'] = $text;

        if ($finishReason !== null) {
            $attrs['llm.output_messages.0.message.finish_reason'] = $finishReason;
        }

        foreach ($toolCalls as $i => $tc) {
            $prefix = "llm.output_messages.0.message.tool_calls.{$i}.tool_call";
            $attrs["{$prefix}.id"] = $tc->id;
            $attrs["{$prefix}.function.name"] = $tc->name;
            $attrs["{$prefix}.function.arguments"] = json_encode($tc->arguments());
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  Tool[]  $tools
     */
    protected function addToolDefinitions(array &$attrs, array $tools): void
    {
        foreach ($tools as $i => $tool) {
            $attrs["llm.tools.{$i}.tool.json_schema"] = json_encode([
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $tool->parametersAsArray(),
                        'required' => $tool->requiredParameters(),
                    ],
                ],
            ]);
        }
    }

    /**
     * Add metadata to attributes, handling special OpenInference user/session attributes.
     *
     * @see https://github.com/Arize-ai/openinference/blob/main/spec/semantic_conventions.md#user-attributes
     *
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $metadata
     */
    protected function addMetadata(array &$attrs, array $metadata): void
    {
        if (isset($metadata['user_id'])) {
            $attrs['user.id'] = (string) $metadata['user_id'];
            unset($metadata['user_id']);
        }

        if (isset($metadata['session_id'])) {
            $attrs['session.id'] = (string) $metadata['session_id'];
            unset($metadata['session_id']);
        }

        if (isset($metadata['agent'])) {
            $attrs['agent.name'] = (string) $metadata['agent'];
            unset($metadata['agent']);
        }

        if (isset($metadata['tags']) && is_array($metadata['tags'])) {
            $tagList = [];
            foreach ($metadata['tags'] as $tagKey => $tagValue) {
                $tagList[] = is_int($tagKey)
                    ? (string) $tagValue
                    : "{$tagKey}:{$tagValue}";
            }
            $attrs['tag.tags'] = json_encode($tagList);
            unset($metadata['tags']);
        }

        foreach ($metadata as $key => $value) {
            $attrs["metadata.{$key}"] = is_string($value) ? $value : json_encode($value);
        }
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
