<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\Anthropic\Maps\CitationsMapper;
use Prism\Prism\Providers\Concerns\ParsesSSE;
use Prism\Prism\Providers\StreamParser;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\CitationEvent;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\TurnResult;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream implements StreamParser
{
    use ParsesSSE;

    public function __construct(protected PendingRequest $client) {}

    public function providerName(): string
    {
        return 'anthropic';
    }

    /**
     * @return Generator<int, StreamEvent, mixed, TurnResult>
     */
    public function parse(Request $request): Generator
    {
        $response = $this->sendRequest($request);
        $stream = $response->getBody();

        $messageId = EventID::generate();
        $reasoningId = null;
        $currentBlockIndex = null;
        $currentBlockType = null;
        $thinkingSignature = '';
        $thinking = '';
        $text = '';
        $toolCalls = [];
        $providerToolCalls = [];
        $finishReason = FinishReason::Stop;
        $usage = null;
        $model = $request->model();

        while (! $stream->eof()) {
            $data = $this->parseNextChunk($stream);

            if ($data === null) {
                continue;
            }

            $type = $data['type'] ?? null;

            if ($type === 'message_start') {
                $message = $data['message'] ?? [];
                $messageId = $message['id'] ?? $messageId;
                $model = $message['model'] ?? $model;

                $usageData = $message['usage'] ?? [];
                if (! empty($usageData)) {
                    $usage = new Usage(
                        promptTokens: $usageData['input_tokens'] ?? 0,
                        completionTokens: $usageData['output_tokens'] ?? 0,
                        cacheWriteInputTokens: $usageData['cache_creation_input_tokens'] ?? null,
                        cacheReadInputTokens: $usageData['cache_read_input_tokens'] ?? null,
                    );
                }

                continue;
            }

            if ($type === 'content_block_start') {
                $contentBlock = $data['content_block'] ?? [];
                $currentBlockIndex = $data['index'] ?? 0;
                $currentBlockType = $contentBlock['type'] ?? '';

                if ($currentBlockType === 'tool_use') {
                    $toolCalls[$currentBlockIndex] = [
                        'id' => $contentBlock['id'] ?? EventID::generate(),
                        'name' => $contentBlock['name'] ?? 'unknown',
                        'input' => '',
                    ];
                } elseif ($currentBlockType === 'server_tool_use') {
                    $providerToolCalls[$currentBlockIndex] = [
                        'type' => $contentBlock['type'] ?? 'server_tool_use',
                        'id' => $contentBlock['id'] ?? EventID::generate(),
                        'name' => $contentBlock['name'] ?? 'unknown',
                        'input' => '',
                    ];

                    yield new ProviderToolEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolType: $contentBlock['name'] ?? 'unknown',
                        status: 'started',
                        itemId: $contentBlock['id'],
                        data: $contentBlock,
                    );
                } elseif (in_array($currentBlockType, ['web_search_tool_result', 'web_fetch_tool_result'], true)) {
                    yield new ProviderToolEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolType: $currentBlockType,
                        status: 'result_received',
                        itemId: $contentBlock['tool_use_id'] ?? EventID::generate(),
                        data: $contentBlock,
                    );
                }

                continue;
            }

            if ($type === 'content_block_delta') {
                $delta = $data['delta'] ?? [];
                $deltaType = $delta['type'] ?? null;

                if ($currentBlockType === 'thinking' && $deltaType === 'thinking_delta') {
                    $thinkingDelta = $delta['thinking'] ?? '';

                    if ($thinkingDelta !== '') {
                        $reasoningId ??= EventID::generate();
                        $thinking .= $thinkingDelta;

                        yield new ThinkingEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            delta: $thinkingDelta,
                            reasoningId: $reasoningId,
                        );
                    }
                } elseif ($currentBlockType === 'thinking' && $deltaType === 'signature_delta') {
                    $thinkingSignature .= $delta['signature'] ?? '';
                } elseif ($currentBlockType === 'text' && $deltaType === 'text_delta') {
                    $textDelta = $delta['text'] ?? '';

                    if ($textDelta !== '') {
                        $text .= $textDelta;

                        yield new TextDeltaEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            delta: $textDelta,
                            messageId: $messageId,
                        );
                    }
                } elseif ($currentBlockType === 'text' && $deltaType === 'citations_delta') {
                    $citationData = $delta['citation'] ?? null;

                    if ($citationData !== null) {
                        yield new CitationEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            citation: CitationsMapper::mapCitationFromAnthropic($citationData),
                            messageId: $messageId,
                            blockIndex: $currentBlockIndex,
                        );
                    }
                } elseif ($currentBlockType === 'tool_use' && $deltaType === 'input_json_delta') {
                    $partialJson = $delta['partial_json'] ?? '';

                    if ($currentBlockIndex !== null && isset($toolCalls[$currentBlockIndex])) {
                        $toolCalls[$currentBlockIndex]['input'] .= $partialJson;

                        yield new ToolCallDeltaEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            toolId: $toolCalls[$currentBlockIndex]['id'],
                            toolName: $toolCalls[$currentBlockIndex]['name'],
                            delta: $partialJson,
                            messageId: $messageId,
                        );
                    }
                } elseif ($currentBlockType === 'server_tool_use' && $deltaType === 'input_json_delta') {
                    $partialJson = $delta['partial_json'] ?? '';

                    if ($currentBlockIndex !== null && isset($providerToolCalls[$currentBlockIndex])) {
                        $providerToolCalls[$currentBlockIndex]['input'] .= $partialJson;
                    }
                }

                continue;
            }

            if ($type === 'content_block_stop') {
                if ($currentBlockType === 'tool_use' && $currentBlockIndex !== null && isset($toolCalls[$currentBlockIndex])) {
                    $tc = $toolCalls[$currentBlockIndex];

                    yield new ToolCallEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolCall: new ToolCall(
                            id: $tc['id'],
                            name: $tc['name'],
                            arguments: $this->parseToolInput($tc['input']),
                            reasoningId: $reasoningId,
                        ),
                        messageId: $messageId,
                    );
                } elseif ($currentBlockType === 'server_tool_use' && $currentBlockIndex !== null && isset($providerToolCalls[$currentBlockIndex])) {
                    $ptc = $providerToolCalls[$currentBlockIndex];

                    yield new ProviderToolEvent(
                        id: EventID::generate(),
                        timestamp: time(),
                        toolType: $ptc['name'],
                        status: 'completed',
                        itemId: $ptc['id'],
                        data: $ptc,
                    );
                }

                $currentBlockIndex = null;
                $currentBlockType = null;

                continue;
            }

            if ($type === 'message_delta') {
                $usageData = $data['usage'] ?? [];
                if (! empty($usageData) && $usage instanceof Usage && isset($usageData['output_tokens'])) {
                    $usage = new Usage(
                        promptTokens: $usage->promptTokens,
                        completionTokens: $usageData['output_tokens'],
                        cacheWriteInputTokens: $usage->cacheWriteInputTokens,
                        cacheReadInputTokens: $usage->cacheReadInputTokens,
                    );
                }

                $stopReason = data_get($data, 'delta.stop_reason');
                if ($stopReason === 'tool_use') {
                    $finishReason = FinishReason::ToolCalls;
                } elseif ($stopReason === 'end_turn' || $stopReason === 'stop_sequence') {
                    $finishReason = FinishReason::Stop;
                }

                continue;
            }

            if ($type === 'error') {
                yield new ErrorEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    errorType: $data['error']['type'] ?? 'unknown_error',
                    message: $data['error']['message'] ?? 'Unknown error occurred',
                    recoverable: true,
                );
            }
        }

        $thinkingContent = in_array($thinking, ['', '0'], true) ? null : $thinking;

        return new TurnResult(
            finishReason: $finishReason,
            usage: $usage,
            model: $model,
            additionalContent: [
                'thinking' => $thinkingContent,
                'thinking_signature' => $thinkingSignature !== '' ? $thinkingSignature : null,
            ],
            toolCallAdditionalContent: $thinkingContent !== null ? [
                'thinking' => $thinkingContent,
                'thinking_signature' => $thinkingSignature,
            ] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseToolInput(mixed $input): array
    {
        if (is_string($input) && json_validate($input)) {
            return json_decode($input, true);
        }

        if (is_string($input) && $input !== '') {
            return ['input' => $input];
        }

        return [];
    }

    // ──────────────────────────────────────────────────────────
    //  SSE parsing (Anthropic event+data format)
    // ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    protected function parseNextChunk(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);
        $line = trim($line);

        if ($line === '' || $line === '0') {
            return null;
        }

        if (str_starts_with($line, 'event:')) {
            return $this->parseEventChunk($line, $stream);
        }

        if (str_starts_with($line, 'data:')) {
            return $this->parseDataChunk($line);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseEventChunk(string $line, StreamInterface $stream): ?array
    {
        $eventType = trim(substr($line, strlen('event:')));

        if ($eventType === 'ping') {
            return ['type' => 'ping'];
        }

        $dataLine = $this->readLine($stream);
        $dataLine = trim($dataLine);

        if ($dataLine === '' || $dataLine === '0' || ! str_starts_with($dataLine, 'data:')) {
            return ['type' => $eventType];
        }

        return $this->parseJsonData($dataLine, $eventType);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseDataChunk(string $line): ?array
    {
        $jsonData = trim(substr($line, strlen('data:')));

        if ($jsonData === '' || $jsonData === '0' || str_contains($jsonData, 'DONE')) {
            return null;
        }

        return $this->parseJsonData($jsonData);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseJsonData(string $jsonDataLine, ?string $eventType = null): ?array
    {
        $jsonData = trim(str_starts_with($jsonDataLine, 'data:')
            ? substr($jsonDataLine, strlen('data:'))
            : $jsonDataLine);

        if ($jsonData === '' || $jsonData === '0') {
            return $eventType ? ['type' => $eventType] : null;
        }

        try {
            $data = json_decode($jsonData, true, flags: JSON_THROW_ON_ERROR);

            if ($eventType) {
                $data['type'] = $eventType;
            }

            return $data;
        } catch (Throwable $e) {
            throw new PrismStreamDecodeException('Anthropic', $e);
        }
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this->client
            ->withOptions(['stream' => true])
            ->post('messages', Arr::whereNotNull([
                'stream' => true,
                ...Text::buildHttpRequestPayload($request),
            ]));

        return $response;
    }
}
