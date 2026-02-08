<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Providers\ChatCompletionsStreamHandler;
use Prism\Prism\Providers\Mistral\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Mistral\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Mistral\Concerns\ValidatesResponse;
use Prism\Prism\Providers\Mistral\Maps\MessageMap;
use Prism\Prism\Providers\Mistral\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Mistral\Maps\ToolMap;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class Stream extends ChatCompletionsStreamHandler
{
    use MapsFinishReason, ProcessRateLimits, ValidatesResponse;

    public function __construct(
        protected PendingRequest $client,
        #[\SensitiveParameter] protected string $apiKey,
    ) {
        parent::__construct($client);
    }

    protected function providerName(): string
    {
        return 'mistral';
    }

    /**
     * Override to extract usage from chunks without finish_reason.
     *
     * Mistral may send usage in every chunk or only in the final chunk.
     * The parent already handles usage extraction on finish_reason chunks, so we only
     * extract from chunks without finish_reason to avoid double-counting.
     *
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    protected function processChunk(array $data, Request $request): Generator
    {
        $rawFinishReason = data_get($data, 'choices.0.finish_reason');

        if ($rawFinishReason === null) {
            $usage = $this->extractUsage($data);
            if ($usage instanceof Usage) {
                $this->state->addUsage($usage);
            }
        }

        yield from parent::processChunk($data, $request);
    }

    /**
     * Extract thinking content from array-format content blocks.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractThinkingDelta(array $data, Request $request): string
    {
        $content = data_get($data, 'choices.0.delta.content');

        if (! is_array($content)) {
            return '';
        }

        $thinking = '';

        foreach ($content as $block) {
            if (data_get($block, 'type') === 'thinking') {
                foreach (data_get($block, 'thinking', []) as $thinkingBlock) {
                    $thinking .= data_get($thinkingBlock, 'text', '');
                }
            }
        }

        return $thinking;
    }

    /**
     * Extract text content, handling both string and array formats.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractContentDelta(array $data): string
    {
        $content = data_get($data, 'choices.0.delta.content');

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            $text = '';

            foreach ($content as $block) {
                if (data_get($block, 'type') === 'text') {
                    $text .= data_get($block, 'text', '');
                }
            }

            return $text;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        $parts = data_get($data, 'choices.0.delta.tool_calls', []);

        foreach ($parts as $part) {
            if (isset($part['function'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        $parts = data_get($data, 'choices.0.delta.tool_calls', []);

        foreach ($parts as $index => $part) {
            if (isset($part['function'])) {
                $toolCalls[$index]['id'] = data_get($part, 'id', Str::random(8));
                $toolCalls[$index]['name'] = data_get($part, 'function.name');
                $toolCalls[$index]['arguments'] = data_get($part, 'function.arguments', '');
            }
        }

        return $toolCalls;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return ToolCall[]
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return collect($toolCalls)
            ->map(function ($toolCall): ToolCall {
                $arguments = data_get($toolCall, 'arguments', '');

                if (is_string($arguments) && $arguments !== '') {
                    try {
                        $parsedArguments = json_decode($arguments, true, flags: JSON_THROW_ON_ERROR);
                        $arguments = $parsedArguments;
                    } catch (Throwable) {
                        $arguments = ['raw' => $arguments];
                    }
                }

                return new ToolCall(
                    data_get($toolCall, 'id'),
                    data_get($toolCall, 'name'),
                    $arguments,
                );
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): ?Usage
    {
        $usage = data_get($data, 'usage');

        if (! $usage) {
            return null;
        }

        return new Usage(
            promptTokens: (int) data_get($usage, 'prompt_tokens', 0),
            completionTokens: (int) data_get($usage, 'completion_tokens', 0)
        );
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post('chat/completions',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'max_tokens' => $request->maxTokens(),
                ], Arr::whereNotNull([
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'tools' => ToolMap::map($request->tools()),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                ]))
            );

        return $response;
    }
}
