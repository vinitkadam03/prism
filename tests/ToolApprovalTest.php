<?php

declare(strict_types=1);

use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolApprovalResponseMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class ToolApprovalTestHandler
{
    use CallsTools;

    public function execute(array $tools, array $toolCalls, bool &$hasPendingToolCalls = false): array
    {
        return $this->callTools($tools, $toolCalls, $hasPendingToolCalls);
    }

    public function stream(array $tools, array $toolCalls, string $messageId, array &$toolResults, bool &$hasPendingToolCalls = false): Generator
    {
        return $this->callToolsAndYieldEvents($tools, $toolCalls, $messageId, $toolResults, $hasPendingToolCalls);
    }

    public function resolve(Request $request): void
    {
        $this->resolveToolApprovals($request);
    }

    public function resolveStream(Request $request, string $messageId): Generator
    {
        return $this->resolveToolApprovalsAndYieldEvents($request, $messageId);
    }
}

function getResolvedToolResults(Request $request): array
{
    foreach (array_reverse($request->messages()) as $message) {
        if ($message instanceof ToolResultMessage) {
            return $message->toolResults;
        }
    }

    return [];
}

function createTextRequest(array $messages = [], array $tools = []): Request
{
    return new Request(
        model: 'test-model',
        providerKey: 'test',
        systemPrompts: [],
        prompt: null,
        messages: $messages,
        maxSteps: 5,
        maxTokens: null,
        temperature: null,
        topP: null,
        tools: $tools,
        clientOptions: [],
        clientRetry: [0],
        toolChoice: ToolChoice::Auto,
    );
}

describe('Tool::requiresApproval()', function (): void {
    it('defaults to not requiring approval', function (): void {
        $tool = (new Tool)
            ->as('test')
            ->for('Test tool')
            ->using(fn (): string => 'result');

        expect($tool->needsApproval())->toBeFalse();
    });

    it('can be marked as requiring approval with static true', function (): void {
        $tool = (new Tool)
            ->as('test')
            ->for('Test tool')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        expect($tool->needsApproval())->toBeTrue();
    });

    it('can be marked as requiring approval with explicit true', function (): void {
        $tool = (new Tool)
            ->as('test')
            ->for('Test tool')
            ->using(fn (): string => 'result')
            ->requiresApproval(true);

        expect($tool->needsApproval())->toBeTrue();
    });

    it('can be set to not require approval with false', function (): void {
        $tool = (new Tool)
            ->as('test')
            ->for('Test tool')
            ->using(fn (): string => 'result')
            ->requiresApproval(false);

        expect($tool->needsApproval())->toBeFalse();
    });

    it('supports dynamic approval via closure', function (): void {
        $tool = (new Tool)
            ->as('transfer')
            ->for('Transfer money')
            ->withNumberParameter('amount', 'Amount')
            ->using(fn (float $amount): string => "Transferred {$amount}")
            ->requiresApproval(fn (array $args): bool => $args['amount'] > 1000);

        expect($tool->needsApproval(['amount' => 500]))->toBeFalse();
        expect($tool->needsApproval(['amount' => 1500]))->toBeTrue();
    });
});

describe('Phase 1: filterServerExecutedToolCalls with approval tools', function (): void {
    it('skips approval-required tools and sets pending flag', function (): void {
        $normalTool = (new Tool)
            ->as('normal_tool')
            ->for('A normal tool')
            ->using(fn (): string => 'result');

        $approvalTool = (new Tool)
            ->as('approval_tool')
            ->for('Needs approval')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $toolCalls = [
            new ToolCall(id: 'call-1', name: 'normal_tool', arguments: []),
            new ToolCall(id: 'call-2', name: 'approval_tool', arguments: []),
        ];

        $handler = new ToolApprovalTestHandler;
        $hasPendingToolCalls = false;
        $results = $handler->execute([$normalTool, $approvalTool], $toolCalls, $hasPendingToolCalls);

        expect($results)->toHaveCount(1)
            ->and($results[0]->toolName)->toBe('normal_tool')
            ->and($hasPendingToolCalls)->toBeTrue();
    });

    it('emits ToolApprovalRequestEvent in streaming for approval-required tools', function (): void {
        $approvalTool = (new Tool)
            ->as('dangerous_tool')
            ->for('Dangerous operation')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $toolCalls = [
            new ToolCall(id: 'call-1', name: 'dangerous_tool', arguments: ['action' => 'delete']),
        ];

        $handler = new ToolApprovalTestHandler;
        $toolResults = [];
        $hasPendingToolCalls = false;
        $events = [];

        foreach ($handler->stream([$approvalTool], $toolCalls, 'msg-123', $toolResults, $hasPendingToolCalls) as $event) {
            $events[] = $event;
        }

        expect($events)->toHaveCount(1)
            ->and($events[0])->toBeInstanceOf(ToolApprovalRequestEvent::class)
            ->and($events[0]->toolCall->id)->toBe('call-1')
            ->and($events[0]->toolCall->name)->toBe('dangerous_tool')
            ->and($events[0]->messageId)->toBe('msg-123')
            ->and($hasPendingToolCalls)->toBeTrue()
            ->and($toolResults)->toBeEmpty();
    });

    it('handles mixed tools: normal, client-executed, and approval-required', function (): void {
        $normalTool = (new Tool)
            ->as('normal')
            ->for('Normal tool')
            ->using(fn (): string => 'normal result');

        $clientTool = (new Tool)
            ->as('client')
            ->for('Client tool');

        $approvalTool = (new Tool)
            ->as('approval')
            ->for('Approval tool')
            ->using(fn (): string => 'should not run')
            ->requiresApproval();

        $toolCalls = [
            new ToolCall(id: 'call-1', name: 'normal', arguments: []),
            new ToolCall(id: 'call-2', name: 'client', arguments: []),
            new ToolCall(id: 'call-3', name: 'approval', arguments: []),
        ];

        $handler = new ToolApprovalTestHandler;
        $hasPendingToolCalls = false;
        $results = $handler->execute([$normalTool, $clientTool, $approvalTool], $toolCalls, $hasPendingToolCalls);

        expect($results)->toHaveCount(1)
            ->and($results[0]->toolName)->toBe('normal')
            ->and($hasPendingToolCalls)->toBeTrue();
    });

    it('skips tool with dynamic approval only when closure returns true', function (): void {
        $tool = (new Tool)
            ->as('transfer')
            ->for('Transfer money')
            ->withNumberParameter('amount', 'Amount')
            ->using(fn (float $amount): string => "Transferred {$amount}")
            ->requiresApproval(fn (array $args): bool => $args['amount'] > 1000);

        $smallTransfer = new ToolCall(id: 'call-1', name: 'transfer', arguments: ['amount' => 500]);
        $largeTransfer = new ToolCall(id: 'call-2', name: 'transfer', arguments: ['amount' => 2000]);

        $handler = new ToolApprovalTestHandler;

        $hasPending = false;
        $results = $handler->execute([$tool], [$smallTransfer], $hasPending);
        expect($results)->toHaveCount(1)
            ->and($results[0]->result)->toBe('Transferred 500')
            ->and($hasPending)->toBeFalse();

        $hasPending = false;
        $results = $handler->execute([$tool], [$largeTransfer], $hasPending);
        expect($results)->toBeEmpty()
            ->and($hasPending)->toBeTrue();
    });
});

describe('Phase 2: resolveToolApprovals', function (): void {
    it('returns empty when no ToolApprovalResponseMessage exists', function (): void {
        $tool = (new Tool)
            ->as('test')
            ->for('Test')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $request = createTextRequest(
            messages: [new UserMessage('hello')],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        expect(getResolvedToolResults($request))->toBeEmpty();
    });

    it('executes approved tools', function (): void {
        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = createTextRequest(
            messages: [
                new UserMessage('delete the file'),
                new AssistantMessage(
                    content: 'I will delete the file.',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'delete_file', arguments: ['path' => '/tmp/test.txt']),
                    ],
                ),
                new ToolApprovalResponseMessage([
                    new ToolApprovalResponse(
                        toolCallId: 'call-1',
                        toolName: 'delete_file',
                        approved: true,
                    ),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(1)
            ->and($results[0]->toolName)->toBe('delete_file')
            ->and($results[0]->result)->toBe('Deleted: /tmp/test.txt')
            ->and($results[0]->toolCallId)->toBe('call-1');

        $messages = $request->messages();
        $lastMessage = end($messages);
        expect($lastMessage)->toBeInstanceOf(ToolResultMessage::class);
    });

    it('creates denial result for denied tools', function (): void {
        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = createTextRequest(
            messages: [
                new UserMessage('delete the file'),
                new AssistantMessage(
                    content: 'I will delete the file.',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'delete_file', arguments: ['path' => '/tmp/test.txt']),
                    ],
                ),
                new ToolApprovalResponseMessage([
                    new ToolApprovalResponse(
                        toolCallId: 'call-1',
                        toolName: 'delete_file',
                        approved: false,
                        reason: 'Too dangerous',
                    ),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(1)
            ->and($results[0]->toolName)->toBe('delete_file')
            ->and($results[0]->result)->toBe('Too dangerous')
            ->and($results[0]->toolCallId)->toBe('call-1');
    });

    it('treats missing approval responses as denied', function (): void {
        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = createTextRequest(
            messages: [
                new UserMessage('delete the file'),
                new AssistantMessage(
                    content: 'I will delete the file.',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'delete_file', arguments: ['path' => '/tmp/test.txt']),
                    ],
                ),
                new ToolApprovalResponseMessage([]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(1)
            ->and($results[0]->result)->toBe('User denied tool execution');
    });

    it('replaces ToolApprovalResponseMessage with ToolResultMessage in request', function (): void {
        $tool = (new Tool)
            ->as('test_tool')
            ->for('Test')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $request = createTextRequest(
            messages: [
                new UserMessage('test'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'test_tool', arguments: []),
                    ],
                ),
                new ToolApprovalResponseMessage([
                    new ToolApprovalResponse(
                        toolCallId: 'call-1',
                        toolName: 'test_tool',
                        approved: true,
                    ),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $hasApprovalMessage = false;
        $hasToolResultMessage = false;

        foreach ($request->messages() as $message) {
            if ($message instanceof ToolApprovalResponseMessage) {
                $hasApprovalMessage = true;
            }
            if ($message instanceof ToolResultMessage) {
                $hasToolResultMessage = true;
            }
        }

        expect($hasApprovalMessage)->toBeFalse()
            ->and($hasToolResultMessage)->toBeTrue();
    });

    it('yields ToolResultEvent for each resolved tool in streaming', function (): void {
        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file')
            ->withStringParameter('path', 'File path')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $request = createTextRequest(
            messages: [
                new UserMessage('delete'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'delete_file', arguments: ['path' => '/tmp/a.txt']),
                        new ToolCall(id: 'call-2', name: 'delete_file', arguments: ['path' => '/tmp/b.txt']),
                    ],
                ),
                new ToolApprovalResponseMessage([
                    new ToolApprovalResponse(toolCallId: 'call-1', toolName: 'delete_file', approved: true),
                    new ToolApprovalResponse(toolCallId: 'call-2', toolName: 'delete_file', approved: false, reason: 'Keep this file'),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $events = [];
        $toolResults = [];

        foreach ($handler->resolveStream($request, 'msg-456') as $event) {
            $events[] = $event;
            if ($event instanceof ToolResultEvent) {
                $toolResults[] = $event->toolResult;
            }
        }

        expect($events)->toHaveCount(2)
            ->and($events[0])->toBeInstanceOf(ToolResultEvent::class)
            ->and($events[0]->toolResult->result)->toBe('Deleted: /tmp/a.txt')
            ->and($events[0]->success)->toBeTrue()
            ->and($events[1])->toBeInstanceOf(ToolResultEvent::class)
            ->and($events[1]->toolResult->result)->toBe('Keep this file')
            ->and($events[1]->success)->toBeFalse();

        expect($toolResults)->toHaveCount(2);
    });

    it('handles denial without explicit reason using default message', function (): void {
        $tool = (new Tool)
            ->as('test_tool')
            ->for('Test')
            ->using(fn (): string => 'result')
            ->requiresApproval();

        $request = createTextRequest(
            messages: [
                new UserMessage('test'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call-1', name: 'test_tool', arguments: []),
                    ],
                ),
                new ToolApprovalResponseMessage([
                    new ToolApprovalResponse(
                        toolCallId: 'call-1',
                        toolName: 'test_tool',
                        approved: false,
                    ),
                ]),
            ],
            tools: [$tool],
        );

        $handler = new ToolApprovalTestHandler;
        $handler->resolve($request);

        $results = getResolvedToolResults($request);
        expect($results)->toHaveCount(1)
            ->and($results[0]->result)->toBe('User denied tool execution');
    });
});

describe('ToolApprovalResponse value object', function (): void {
    it('serializes to array correctly', function (): void {
        $response = new ToolApprovalResponse(
            toolCallId: 'call-123',
            toolName: 'test_tool',
            approved: true,
            reason: 'User confirmed',
        );

        expect($response->toArray())->toBe([
            'tool_call_id' => 'call-123',
            'tool_name' => 'test_tool',
            'approved' => true,
            'reason' => 'User confirmed',
        ]);
    });
});

describe('ToolApprovalResponseMessage', function (): void {
    it('finds responses by tool call ID', function (): void {
        $message = new ToolApprovalResponseMessage([
            new ToolApprovalResponse(toolCallId: 'call-1', toolName: 'tool_a', approved: true),
            new ToolApprovalResponse(toolCallId: 'call-2', toolName: 'tool_b', approved: false),
        ]);

        $found = $message->findByToolCallId('call-1');
        expect($found)->not->toBeNull()
            ->and($found->toolName)->toBe('tool_a')
            ->and($found->approved)->toBeTrue();

        $found2 = $message->findByToolCallId('call-2');
        expect($found2)->not->toBeNull()
            ->and($found2->approved)->toBeFalse();

        expect($message->findByToolCallId('nonexistent'))->toBeNull();
    });

    it('serializes to array correctly', function (): void {
        $message = new ToolApprovalResponseMessage([
            new ToolApprovalResponse(toolCallId: 'call-1', toolName: 'tool_a', approved: true),
        ]);

        $array = $message->toArray();
        expect($array['type'])->toBe('tool_approval_response')
            ->and($array['tool_approval_responses'])->toHaveCount(1);
    });
});

describe('ToolApprovalRequestEvent', function (): void {
    it('has correct event type', function (): void {
        $event = new ToolApprovalRequestEvent(
            id: 'evt-1',
            timestamp: 1234567890,
            toolCall: new ToolCall(id: 'call-1', name: 'test_tool', arguments: ['key' => 'value']),
            messageId: 'msg-1',
        );

        expect($event->type())->toBe(\Prism\Prism\Enums\StreamEventType::ToolApprovalRequest);

        $array = $event->toArray();
        expect($array['tool_name'])->toBe('test_tool')
            ->and($array['tool_id'])->toBe('call-1')
            ->and($array['arguments'])->toBe(['key' => 'value'])
            ->and($array['message_id'])->toBe('msg-1');
    });
});
