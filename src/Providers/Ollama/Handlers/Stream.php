<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Handlers;

use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Ollama\Concerns\MapsFinishReason;
use Prism\Prism\Providers\Ollama\Maps\MessageMap;
use Prism\Prism\Providers\Ollama\Maps\ToolMap;
use Prism\Prism\Providers\Ollama\ValueObjects\OllamaStreamState;
use Prism\Prism\Providers\StreamHandler;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream extends StreamHandler
{
    use MapsFinishReason;

    protected function providerName(): string
    {
        return 'ollama';
    }

    protected function createState(): StreamState
    {
        return new OllamaStreamState;
    }

    protected function ollamaState(): OllamaStreamState
    {
        if (! $this->state instanceof OllamaStreamState) {
            throw new \LogicException('Expected OllamaStreamState.');
        }

        return $this->state;
    }

    /**
     * Ollama uses JSON lines (no SSE).
     *
     * @return array<string, mixed>|null
     */
    protected function parseNextChunk(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (in_array(trim($line), ['', '0'], true)) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Ollama', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<StreamEvent>
     */
    protected function processChunk(array $data, Request $request): Generator
    {
        yield from $this->yieldStreamStartIfNeeded($request->model());
        yield from $this->yieldStepStartIfNeeded();

        // Accumulate token counts
        $this->ollamaState()->addPromptTokens((int) data_get($data, 'prompt_eval_count', 0));
        $this->ollamaState()->addCompletionTokens((int) data_get($data, 'eval_count', 0));

        // Handle thinking content first
        $thinking = data_get($data, 'message.thinking', '');
        if ($thinking !== '') {
            yield from $this->yieldThinkingDelta($thinking);

            return;
        }

        // If we were emitting thinking and it's now stopped, mark it complete
        if ($this->state->hasThinkingStarted()) {
            yield from $this->yieldThinkingCompleteIfNeeded();
        }

        // Accumulate tool calls if present (don't emit events yet)
        if ($this->hasToolCalls($data)) {
            $toolCalls = $this->extractToolCalls($data, $this->state->toolCalls());
            foreach ($toolCalls as $index => $toolCall) {
                $this->state->addToolCall($index, $toolCall);
            }
        }

        // Handle text content
        $content = data_get($data, 'message.content', '');
        if ($content !== '') {
            yield from $this->yieldTextDelta($content);
        }

        // Handle tool call completion when stream is done
        if ((bool) data_get($data, 'done', false) && $this->state->hasToolCalls()) {
            yield from $this->yieldTextCompleteIfNeeded();

            // Signal to finalize that tool calls are ready
            return;
        }

        // Handle regular completion (no tool calls)
        if ((bool) data_get($data, 'done', false)) {
            yield from $this->yieldTextCompleteIfNeeded();
            yield from $this->yieldStepFinish();
            yield $this->emitStreamEndEvent();
        }
    }

    /**
     * Override finalize: Ollama's completion is handled in processChunk (on 'done' flag).
     * If we get here without tool calls, the stream ended naturally within processChunk.
     * If we get here with tool calls, the tool calls need to be processed.
     *
     * @return Generator<StreamEvent>
     */
    protected function finalize(Request $request, int $depth): Generator
    {
        if ($this->state->hasToolCalls()) {
            yield from $this->processToolCallResults(
                $request,
                $this->state->currentText(),
                $this->mapToolCalls($this->state->toolCalls()),
                $depth,
            );
        }

        // Normal completion is handled in processChunk's 'done' path
    }

    /**
     * Override to use Ollama-specific usage.
     */
    protected function emitStreamEndEvent(): StreamEndEvent
    {
        return new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: FinishReason::Stop,
            usage: new Usage(
                promptTokens: $this->ollamaState()->promptTokens(),
                completionTokens: $this->ollamaState()->completionTokens()
            )
        );
    }

    /**
     * Override to reset Ollama state on next turn.
     */
    protected function resetStateForNextTurn(): void
    {
        $this->state->reset();
    }

    // ──────────────────────────────────────────────────────────
    //  Ollama data extraction
    // ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return (bool) data_get($data, 'message.tool_calls');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        foreach (data_get($data, 'message.tool_calls', []) as $index => $toolCall) {
            if ($name = data_get($toolCall, 'function.name')) {
                $toolCalls[$index]['name'] = $name;
                $toolCalls[$index]['arguments'] = '';
                $toolCalls[$index]['id'] = data_get($toolCall, 'id');
            }

            if ($arguments = data_get($toolCall, 'function.arguments')) {
                $argumentValue = is_array($arguments) ? json_encode($arguments) : $arguments;
                $toolCalls[$index]['arguments'] .= $argumentValue;
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
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id') ?? '',
            name: data_get($toolCall, 'name') ?? '',
            arguments: data_get($toolCall, 'arguments'),
        ), $toolCalls);
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post('api/chat', [
                'model' => $request->model(),
                'messages' => (new MessageMap(array_merge(
                    $request->systemPrompts(),
                    $request->messages()
                )))->map(),
                'tools' => ToolMap::map($request->tools()),
                'stream' => true,
                ...Arr::whereNotNull([
                    'think' => $request->providerOptions('thinking'),
                    'keep_alive' => $request->providerOptions('keep_alive'),
                ]),
                'options' => Arr::whereNotNull(array_merge([
                    'temperature' => $request->temperature(),
                    'num_predict' => $request->maxTokens() ?? 2048,
                    'top_p' => $request->topP(),
                ], $request->providerOptions())),
            ]);

        return $response;
    }
}
