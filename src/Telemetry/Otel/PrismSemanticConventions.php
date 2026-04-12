<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Otel;

/**
 * Semantic conventions for Prism telemetry attributes.
 *
 * These are the standardized `prism.*` attribute keys set on OTel spans.
 * Custom SpanProcessors (like OpenInferenceBatchSpanProcessor) read these
 * attributes and remap them to their target convention before export.
 */
class PrismSemanticConventions
{
    // ========================================================================
    // Core
    // ========================================================================

    public const OPERATION = 'prism.operation';

    public const MODEL = 'prism.model';

    public const PROVIDER = 'prism.provider';

    // ========================================================================
    // Settings (invocation parameters)
    // ========================================================================

    public const SETTINGS_TEMPERATURE = 'prism.settings.temperature';

    public const SETTINGS_MAX_TOKENS = 'prism.settings.max_tokens';

    public const SETTINGS_TOP_P = 'prism.settings.top_p';

    public const SETTINGS_MAX_STEPS = 'prism.settings.max_steps';

    // ========================================================================
    // Prompt / Input
    // ========================================================================

    /** JSON-encoded array of messages (system + user + assistant + tool result). */
    public const PROMPT_MESSAGES = 'prism.prompt.messages';

    // ========================================================================
    // Response / Output
    // ========================================================================

    public const RESPONSE_TEXT = 'prism.response.text';

    public const RESPONSE_MODEL = 'prism.response.model';

    public const RESPONSE_ID = 'prism.response.id';

    public const RESPONSE_FINISH_REASON = 'prism.response.finish_reason';

    public const RESPONSE_SERVICE_TIER = 'prism.response.service_tier';

    /** JSON-encoded array of structured output. */
    public const RESPONSE_OBJECT = 'prism.response.object';

    // ========================================================================
    // Usage
    // ========================================================================

    public const USAGE_PROMPT_TOKENS = 'prism.usage.prompt_tokens';

    public const USAGE_COMPLETION_TOKENS = 'prism.usage.completion_tokens';

    // ========================================================================
    // Tools
    // ========================================================================

    /** JSON-encoded array of tool definitions. */
    public const TOOLS = 'prism.tools';

    /** JSON-encoded tool choice configuration. */
    public const TOOL_CHOICE = 'prism.tool_choice';

    /** JSON-encoded array of tool calls from the response. */
    public const RESPONSE_TOOL_CALLS = 'prism.response.tool_calls';

    // ========================================================================
    // Tool Span (for individual tool execution spans)
    // ========================================================================

    public const TOOL_NAME = 'prism.tool.name';

    public const TOOL_CALL_ID = 'prism.tool.call_id';

    /** JSON-encoded tool call arguments. */
    public const TOOL_ARGUMENTS = 'prism.tool.arguments';

    /** Tool execution result (string or JSON-encoded). */
    public const TOOL_RESULT = 'prism.tool.result';

    // ========================================================================
    // Embeddings
    // ========================================================================

    /** JSON-encoded array of embedding input texts. */
    public const EMBEDDING_INPUTS = 'prism.embedding.inputs';

    public const EMBEDDING_COUNT = 'prism.embedding.count';

    public const EMBEDDING_USAGE_TOKENS = 'prism.embedding.usage.tokens';

    // ========================================================================
    // Structured Output
    // ========================================================================

    public const SCHEMA_NAME = 'prism.schema.name';

    /** JSON-encoded schema definition. */
    public const SCHEMA_DEFINITION = 'prism.schema.definition';

    // ========================================================================
    // Metadata
    // ========================================================================

    /** Prefix for user-provided metadata attributes. */
    public const METADATA_PREFIX = 'prism.metadata.';

    /** JSON-encoded tags array. */
    public const METADATA_TAGS = 'prism.metadata.tags';

    /** JSON-encoded array of metadata key names (used by processors to discover metadata attributes). */
    public const METADATA_KEYS = 'prism.metadata._keys';
}
