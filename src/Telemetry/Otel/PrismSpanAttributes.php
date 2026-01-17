<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Otel;

use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
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
 * Extracts Prism telemetry attributes from SpanData.
 *
 * Converts typed domain objects (Request, Response, ToolCall, etc.) into a
 * flat array of `prism.*` attributes suitable for OTel spans.
 *
 * These standardized attributes are what custom SpanProcessors read and
 * remap to their target convention (OpenInference, GenAI semconv, etc.).
 */
class PrismSpanAttributes
{
    /**
     * Extract all prism.* attributes from span data.
     *
     * @param  array<string, mixed>  $driverMetadata  Additional metadata from the driver (e.g., config tags)
     * @return array<string, string|int|float|bool|null>
     */
    public static function extract(SpanData $span, array $driverMetadata = []): array
    {
        $attrs = [PrismSemanticConventions::OPERATION => $span->operation->value];

        $attrs = array_merge($attrs, match ($span->operation) {
            TelemetryOperation::TextGeneration => self::extractTextGeneration($span),
            TelemetryOperation::Streaming => self::extractStreaming($span),
            TelemetryOperation::StreamStep => self::extractStreamStep($span),
            TelemetryOperation::TextStep => self::extractTextStep($span),
            TelemetryOperation::ToolCall => self::extractToolCall($span),
            TelemetryOperation::Embeddings => self::extractEmbedding($span),
            TelemetryOperation::StructuredOutput => self::extractStructuredOutput($span),
        });

        self::addMetadata($attrs, array_merge($span->metadata, $driverMetadata));

        return self::filterNulls($attrs);
    }

    // ========================================================================
    // Operation Extractors
    // ========================================================================

    /**
     * @return array<string, string|int|float|bool|null>
     */
    protected static function extractTextGeneration(SpanData $span): array
    {
        /** @var TextGenerationStarted $start */
        $start = $span->startEvent;
        /** @var TextGenerationCompleted $end */
        $end = $span->endEvent;

        $request = $start->request;
        $response = $end->response;

        $attrs = self::extractRequestAttributes($request->model(), $request->provider(), $request->temperature(), $request->maxTokens(), $request->topP(), $request->maxSteps());

        $attrs[PrismSemanticConventions::PROMPT_MESSAGES] = self::encodeMessages($request->systemPrompts(), $request->messages());
        $attrs[PrismSemanticConventions::TOOLS] = self::encodeTools($request->tools());
        $attrs[PrismSemanticConventions::TOOL_CHOICE] = self::encodeToolChoice($request->toolChoice());

        $attrs[PrismSemanticConventions::RESPONSE_TEXT] = $response->text;
        $attrs[PrismSemanticConventions::RESPONSE_MODEL] = $response->meta->model;
        $attrs[PrismSemanticConventions::RESPONSE_ID] = $response->meta->id;
        $attrs[PrismSemanticConventions::RESPONSE_SERVICE_TIER] = $response->meta->serviceTier;
        $attrs[PrismSemanticConventions::RESPONSE_FINISH_REASON] = $response->finishReason->name;
        $attrs[PrismSemanticConventions::USAGE_PROMPT_TOKENS] = $response->usage->promptTokens;
        $attrs[PrismSemanticConventions::USAGE_COMPLETION_TOKENS] = $response->usage->completionTokens;
        $attrs[PrismSemanticConventions::RESPONSE_TOOL_CALLS] = self::encodeToolCalls($response->toolCalls);

        return $attrs;
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    protected static function extractStreaming(SpanData $span): array
    {
        /** @var StreamingStarted $start */
        $start = $span->startEvent;
        /** @var StreamingCompleted $end */
        $end = $span->endEvent;

        $request = $start->request;

        $attrs = self::extractRequestAttributes($request->model(), $request->provider(), $request->temperature(), $request->maxTokens(), $request->topP(), $request->maxSteps());

        $attrs[PrismSemanticConventions::PROMPT_MESSAGES] = self::encodeMessages($request->systemPrompts(), $request->messages());
        $attrs[PrismSemanticConventions::TOOLS] = self::encodeTools($request->tools());
        $attrs[PrismSemanticConventions::TOOL_CHOICE] = self::encodeToolChoice($request->toolChoice());

        $attrs[PrismSemanticConventions::RESPONSE_MODEL] = $start->streamStart->model;

        // Reconstruct output from streaming events
        $text = self::collectTextFromEvents($end->events);
        $toolCalls = self::collectToolCallsFromEvents($end->events);

        if ($text !== '') {
            $attrs[PrismSemanticConventions::RESPONSE_TEXT] = $text;
        }

        $attrs[PrismSemanticConventions::RESPONSE_TOOL_CALLS] = self::encodeToolCalls($toolCalls);
        $attrs[PrismSemanticConventions::RESPONSE_ID] = $end->streamEnd->id;
        $attrs[PrismSemanticConventions::RESPONSE_FINISH_REASON] = $end->streamEnd->finishReason->name;
        $attrs[PrismSemanticConventions::USAGE_PROMPT_TOKENS] = $end->streamEnd->usage?->promptTokens;
        $attrs[PrismSemanticConventions::USAGE_COMPLETION_TOKENS] = $end->streamEnd->usage?->completionTokens;

        return $attrs;
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    protected static function extractStreamStep(SpanData $span): array
    {
        /** @var StreamStepStarted $start */
        $start = $span->startEvent;
        /** @var StreamStepCompleted $end */
        $end = $span->endEvent;

        $request = $start->request;

        $attrs = self::extractRequestAttributes($request->model(), $request->provider(), $request->temperature(), $request->maxTokens(), $request->topP(), $request->maxSteps());

        $attrs[PrismSemanticConventions::PROMPT_MESSAGES] = self::encodeMessages($request->systemPrompts(), $request->messages());
        $attrs[PrismSemanticConventions::TOOLS] = self::encodeTools($request->tools());
        $attrs[PrismSemanticConventions::TOOL_CHOICE] = self::encodeToolChoice($request->toolChoice());

        // Reconstruct output from streaming events
        $text = self::collectTextFromEvents($end->events);
        $toolCalls = self::collectToolCallsFromEvents($end->events);

        if ($text !== '') {
            $attrs[PrismSemanticConventions::RESPONSE_TEXT] = $text;
        }

        $attrs[PrismSemanticConventions::RESPONSE_TOOL_CALLS] = self::encodeToolCalls($toolCalls);

        return $attrs;
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    protected static function extractTextStep(SpanData $span): array
    {
        /** @var TextStepStarted $start */
        $start = $span->startEvent;
        /** @var TextStepCompleted $end */
        $end = $span->endEvent;

        $request = $start->request;
        $step = $end->step;

        $attrs = self::extractRequestAttributes($request->model(), $request->provider(), $request->temperature(), $request->maxTokens(), $request->topP(), $request->maxSteps());

        $attrs[PrismSemanticConventions::PROMPT_MESSAGES] = self::encodeMessages($step->systemPrompts, $step->messages);
        $attrs[PrismSemanticConventions::TOOLS] = self::encodeTools($request->tools());
        $attrs[PrismSemanticConventions::TOOL_CHOICE] = self::encodeToolChoice($request->toolChoice());

        $attrs[PrismSemanticConventions::RESPONSE_TEXT] = $step->text;
        $attrs[PrismSemanticConventions::RESPONSE_MODEL] = $step->meta->model;
        $attrs[PrismSemanticConventions::RESPONSE_ID] = $step->meta->id;
        $attrs[PrismSemanticConventions::RESPONSE_SERVICE_TIER] = $step->meta->serviceTier;
        $attrs[PrismSemanticConventions::RESPONSE_FINISH_REASON] = $step->finishReason->name;
        $attrs[PrismSemanticConventions::USAGE_PROMPT_TOKENS] = $step->usage->promptTokens;
        $attrs[PrismSemanticConventions::USAGE_COMPLETION_TOKENS] = $step->usage->completionTokens;
        $attrs[PrismSemanticConventions::RESPONSE_TOOL_CALLS] = self::encodeToolCalls($step->toolCalls);

        return $attrs;
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    protected static function extractToolCall(SpanData $span): array
    {
        /** @var ToolCallStarted $start */
        $start = $span->startEvent;
        /** @var ToolCallCompleted $end */
        $end = $span->endEvent;

        $toolCall = $start->toolCall;
        $toolResult = $end->toolResult;

        $output = $toolResult->result;

        return [
            PrismSemanticConventions::TOOL_NAME => $toolCall->name,
            PrismSemanticConventions::TOOL_CALL_ID => $toolCall->id,
            PrismSemanticConventions::TOOL_ARGUMENTS => json_encode($toolCall->arguments()) ?: null,
            PrismSemanticConventions::TOOL_RESULT => is_string($output) ? $output : (json_encode($output) ?: null),
        ];
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    protected static function extractEmbedding(SpanData $span): array
    {
        /** @var EmbeddingGenerationStarted $start */
        $start = $span->startEvent;
        /** @var EmbeddingGenerationCompleted $end */
        $end = $span->endEvent;

        $request = $start->request;
        $response = $end->response;

        return [
            PrismSemanticConventions::MODEL => $request->model(),
            PrismSemanticConventions::PROVIDER => $request->provider(),
            PrismSemanticConventions::EMBEDDING_INPUTS => json_encode($request->inputs()) ?: null,
            PrismSemanticConventions::EMBEDDING_COUNT => count($response->embeddings),
            PrismSemanticConventions::EMBEDDING_USAGE_TOKENS => $response->usage->tokens,
        ];
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    protected static function extractStructuredOutput(SpanData $span): array
    {
        /** @var StructuredOutputStarted $start */
        $start = $span->startEvent;
        /** @var StructuredOutputCompleted $end */
        $end = $span->endEvent;

        $request = $start->request;
        $response = $end->response;

        $attrs = self::extractRequestAttributes($request->model(), $request->provider(), $request->temperature(), $request->maxTokens(), $request->topP(), $request->maxSteps());

        $attrs[PrismSemanticConventions::PROMPT_MESSAGES] = self::encodeMessages($request->systemPrompts(), $request->messages());
        $attrs[PrismSemanticConventions::TOOLS] = self::encodeTools($request->tools());
        $attrs[PrismSemanticConventions::TOOL_CHOICE] = self::encodeToolChoice($request->toolChoice());

        $attrs[PrismSemanticConventions::SCHEMA_NAME] = $request->schema()->name();
        $attrs[PrismSemanticConventions::SCHEMA_DEFINITION] = json_encode($request->schema()->toArray()) ?: null;

        $attrs[PrismSemanticConventions::RESPONSE_OBJECT] = is_array($response->structured)
            ? (json_encode($response->structured) ?: null)
            : null;
        $attrs[PrismSemanticConventions::RESPONSE_MODEL] = $response->meta->model;
        $attrs[PrismSemanticConventions::RESPONSE_ID] = $response->meta->id;
        $attrs[PrismSemanticConventions::RESPONSE_SERVICE_TIER] = $response->meta->serviceTier;
        $attrs[PrismSemanticConventions::RESPONSE_FINISH_REASON] = $response->finishReason->name;
        $attrs[PrismSemanticConventions::USAGE_PROMPT_TOKENS] = $response->usage->promptTokens;
        $attrs[PrismSemanticConventions::USAGE_COMPLETION_TOKENS] = $response->usage->completionTokens;

        return $attrs;
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * @return array<string, string|int|float|null>
     */
    protected static function extractRequestAttributes(
        string $model,
        string $provider,
        ?float $temperature,
        ?int $maxTokens,
        ?float $topP,
        int $maxSteps,
    ): array {
        return [
            PrismSemanticConventions::MODEL => $model,
            PrismSemanticConventions::PROVIDER => $provider,
            PrismSemanticConventions::SETTINGS_TEMPERATURE => $temperature,
            PrismSemanticConventions::SETTINGS_MAX_TOKENS => $maxTokens,
            PrismSemanticConventions::SETTINGS_TOP_P => $topP,
            PrismSemanticConventions::SETTINGS_MAX_STEPS => $maxSteps,
        ];
    }

    /**
     * @param  SystemMessage[]  $systemPrompts
     * @param  Message[]  $messages
     */
    protected static function encodeMessages(array $systemPrompts, array $messages): ?string
    {
        $result = [];

        foreach ($systemPrompts as $prompt) {
            $result[] = ['role' => 'system', 'content' => $prompt->content];
        }

        foreach ($messages as $message) {
            foreach (self::formatMessage($message) as $formatted) {
                $result[] = $formatted;
            }
        }

        return $result !== [] ? (json_encode($result) ?: null) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function formatMessage(Message $message): array
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

    /**
     * @param  Tool[]  $tools
     */
    protected static function encodeTools(array $tools): ?string
    {
        if ($tools === []) {
            return null;
        }

        $definitions = array_map(fn (Tool $tool): array => [
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
        ], $tools);

        return json_encode($definitions) ?: null;
    }

    protected static function encodeToolChoice(string|ToolChoice|null $toolChoice): ?string
    {
        if ($toolChoice === null) {
            return null;
        }

        $formatted = is_string($toolChoice)
            ? ['type' => 'tool', 'tool_name' => $toolChoice]
            : ['type' => strtolower($toolChoice->name)];

        return json_encode($formatted) ?: null;
    }

    /**
     * @param  ToolCall[]|null  $toolCalls
     */
    protected static function encodeToolCalls(?array $toolCalls): ?string
    {
        if (! $toolCalls) {
            return null;
        }

        $encoded = array_map(fn (ToolCall $tc): array => [
            'id' => $tc->id,
            'name' => $tc->name,
            'arguments' => $tc->arguments(),
        ], $toolCalls);

        return json_encode($encoded) ?: null;
    }

    /**
     * @param  array<StreamEvent>  $events
     */
    protected static function collectTextFromEvents(array $events): string
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
    protected static function collectToolCallsFromEvents(array $events): array
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
     * @param  array<string, string|int|float|bool|null>  $attrs
     * @param  array<string, mixed>  $metadata
     */
    protected static function addMetadata(array &$attrs, array $metadata): void
    {
        if ($metadata === []) {
            return;
        }

        // Store the list of metadata keys so processors can discover them
        // without needing to iterate all span attributes.
        $attrs[PrismSemanticConventions::METADATA_KEYS] = json_encode(array_keys($metadata)) ?: null;

        foreach ($metadata as $key => $value) {
            $attrKey = PrismSemanticConventions::METADATA_PREFIX.$key;
            $attrs[$attrKey] = is_string($value) || is_int($value) || is_float($value) || is_bool($value)
                ? $value
                : json_encode($value);
        }
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $array
     * @return array<string, string|int|float|bool>
     */
    protected static function filterNulls(array $array): array
    {
        return array_filter($array, fn ($v): bool => $v !== null);
    }
}
