<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Telemetry\Events\ToolCallCompleted;
use Prism\Prism\Telemetry\Events\ToolCallStarted;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;

it('emits telemetry events for tool calls when enabled', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.driver' => 'null',
    ]);

    Event::fake();

    // Create a test class that uses the CallsTools trait
    $testHandler = new class
    {
        use CallsTools;

        public function testCallTools(array $tools, array $toolCalls): array
        {
            $hasPendingToolCalls = false;

            return $this->callTools($tools, $toolCalls, $hasPendingToolCalls);
        }
    };

    // Create a mock tool
    $tool = (new Tool)
        ->as('test_tool')
        ->for('Testing tool calls')
        ->withStringParameter('input', 'Test input')
        ->using(fn (string $input): string => "Processed: {$input}");

    // Create a tool call
    $toolCall = new ToolCall(
        id: 'tool-123',
        name: 'test_tool',
        arguments: ['input' => 'test value'],
        resultId: 'result-123'
    );

    // Execute the tool call
    $results = $testHandler->testCallTools([$tool], [$toolCall]);

    // Verify tool call telemetry events were dispatched
    Event::assertDispatched(ToolCallStarted::class);
    Event::assertDispatched(ToolCallCompleted::class);

    expect($results)->toHaveCount(1);
});

it('does not emit tool call events when telemetry is disabled', function (): void {
    config([
        'prism.telemetry.enabled' => false,
    ]);

    Event::fake();

    // Create a test class that uses the CallsTools trait
    $testHandler = new class
    {
        use CallsTools;

        public function testCallTools(array $tools, array $toolCalls): array
        {
            $hasPendingToolCalls = false;

            return $this->callTools($tools, $toolCalls, $hasPendingToolCalls);
        }
    };

    // Create a mock tool
    $tool = (new Tool)
        ->as('test_tool')
        ->for('Testing tool calls')
        ->withStringParameter('input', 'Test input')
        ->using(fn (string $input): string => "Processed: {$input}");

    // Create a tool call
    $toolCall = new ToolCall(
        id: 'tool-123',
        name: 'test_tool',
        arguments: ['input' => 'test value'],
        resultId: 'result-123'
    );

    // Execute the tool call
    $results = $testHandler->testCallTools([$tool], [$toolCall]);

    // Verify tool call events were not dispatched when telemetry is disabled
    Event::assertNotDispatched(ToolCallStarted::class);
    Event::assertNotDispatched(ToolCallCompleted::class);

    expect($results)->toHaveCount(1);
});

it('includes traceId and parentSpanId in tool call telemetry events', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.driver' => 'null',
    ]);

    Event::fake();

    // Create a test class that uses the CallsTools trait
    $testHandler = new class
    {
        use CallsTools;

        public function testCallTools(array $tools, array $toolCalls): array
        {
            $hasPendingToolCalls = false;

            return $this->callTools($tools, $toolCalls, $hasPendingToolCalls);
        }
    };

    // Create a mock tool
    $tool = (new Tool)
        ->as('test_tool')
        ->for('Testing tool calls')
        ->withStringParameter('input', 'Test input')
        ->using(fn (string $input): string => "Processed: {$input}");

    // Create a tool call
    $toolCall = new ToolCall(
        id: 'tool-123',
        name: 'test_tool',
        arguments: ['input' => 'test value'],
        resultId: 'result-123'
    );

    // Execute the tool call
    $results = $testHandler->testCallTools([$tool], [$toolCall]);

    // Verify tool call events contain traceId and parentSpanId as properties
    Event::assertDispatched(ToolCallStarted::class, function (ToolCallStarted $event): bool {
        return ! empty($event->spanId)
            && ! empty($event->traceId)
            && $event->parentSpanId === null; // No parent when called directly
    });

    Event::assertDispatched(ToolCallCompleted::class, fn (ToolCallCompleted $event): bool => ! empty($event->spanId)
        && ! empty($event->traceId)
        && $event->parentSpanId === null);

    expect($results)->toHaveCount(1);
});

it('emits telemetry events for concurrent tool calls', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.driver' => 'null',
    ]);

    Event::fake();

    $testHandler = new class
    {
        use CallsTools;

        public function testCallTools(array $tools, array $toolCalls): array
        {
            $hasPendingToolCalls = false;

            return $this->callTools($tools, $toolCalls, $hasPendingToolCalls);
        }
    };

    $toolA = (new Tool)
        ->as('tool_a')
        ->for('First concurrent tool')
        ->withStringParameter('input', 'Input')
        ->using(fn (string $input): string => "A: {$input}")
        ->concurrent();

    $toolB = (new Tool)
        ->as('tool_b')
        ->for('Second concurrent tool')
        ->withStringParameter('input', 'Input')
        ->using(fn (string $input): string => "B: {$input}")
        ->concurrent();

    $toolCalls = [
        new ToolCall(id: 'call-a', name: 'tool_a', arguments: ['input' => 'alpha'], resultId: 'res-a'),
        new ToolCall(id: 'call-b', name: 'tool_b', arguments: ['input' => 'beta'], resultId: 'res-b'),
    ];

    $results = $testHandler->testCallTools([$toolA, $toolB], $toolCalls);

    expect($results)->toHaveCount(2);

    Event::assertDispatched(ToolCallStarted::class, 2);
    Event::assertDispatched(ToolCallCompleted::class, 2);

    Event::assertDispatched(ToolCallStarted::class, fn (ToolCallStarted $e): bool => $e->toolCall->name === 'tool_a'
        && ! empty($e->spanId)
        && ! empty($e->traceId));

    Event::assertDispatched(ToolCallStarted::class, fn (ToolCallStarted $e): bool => $e->toolCall->name === 'tool_b'
        && ! empty($e->spanId)
        && ! empty($e->traceId));

    Event::assertDispatched(ToolCallCompleted::class, fn (ToolCallCompleted $e): bool => $e->toolCall->name === 'tool_a'
        && $e->toolResult->result === 'A: alpha');

    Event::assertDispatched(ToolCallCompleted::class, fn (ToolCallCompleted $e): bool => $e->toolCall->name === 'tool_b'
        && $e->toolResult->result === 'B: beta');
});

it('shares trace ID between concurrent tool call telemetry spans', function (): void {
    config([
        'prism.telemetry.enabled' => true,
        'prism.telemetry.driver' => 'null',
    ]);

    Event::fake();

    $testHandler = new class
    {
        use CallsTools;

        public function testCallTools(array $tools, array $toolCalls): array
        {
            $hasPendingToolCalls = false;

            return $this->callTools($tools, $toolCalls, $hasPendingToolCalls);
        }
    };

    $toolA = (new Tool)
        ->as('tool_a')
        ->for('First tool')
        ->withStringParameter('input', 'Input')
        ->using(fn (string $input): string => "A: {$input}")
        ->concurrent();

    $toolB = (new Tool)
        ->as('tool_b')
        ->for('Second tool')
        ->withStringParameter('input', 'Input')
        ->using(fn (string $input): string => "B: {$input}")
        ->concurrent();

    $toolCalls = [
        new ToolCall(id: 'call-a', name: 'tool_a', arguments: ['input' => 'alpha']),
        new ToolCall(id: 'call-b', name: 'tool_b', arguments: ['input' => 'beta']),
    ];

    $testHandler->testCallTools([$toolA, $toolB], $toolCalls);

    $startedEvents = [];
    Event::assertDispatched(ToolCallStarted::class, function (ToolCallStarted $e) use (&$startedEvents): bool {
        $startedEvents[] = $e;

        return true;
    });

    $completedEvents = [];
    Event::assertDispatched(ToolCallCompleted::class, function (ToolCallCompleted $e) use (&$completedEvents): bool {
        $completedEvents[] = $e;

        return true;
    });

    // All spans share the same traceId
    $traceIds = array_unique(array_merge(
        array_map(fn (ToolCallStarted $e): string => $e->traceId, $startedEvents),
        array_map(fn (ToolCallCompleted $e): string => $e->traceId, $completedEvents),
    ));
    expect($traceIds)->toHaveCount(1);

    // Each tool call gets its own spanId
    $startSpanIds = array_map(fn (ToolCallStarted $e): string => $e->spanId, $startedEvents);
    expect(array_unique($startSpanIds))->toHaveCount(2);

    // Started and completed events use matching spanIds per tool
    foreach ($startedEvents as $started) {
        $matching = array_filter(
            $completedEvents,
            fn (ToolCallCompleted $c): bool => $c->spanId === $started->spanId && $c->toolCall->name === $started->toolCall->name
        );
        expect($matching)->toHaveCount(1);
    }
});

it('does not emit telemetry for concurrent tool calls when disabled', function (): void {
    config([
        'prism.telemetry.enabled' => false,
    ]);

    Event::fake();

    $testHandler = new class
    {
        use CallsTools;

        public function testCallTools(array $tools, array $toolCalls): array
        {
            $hasPendingToolCalls = false;

            return $this->callTools($tools, $toolCalls, $hasPendingToolCalls);
        }
    };

    $tool = (new Tool)
        ->as('tool_a')
        ->for('Concurrent tool')
        ->withStringParameter('input', 'Input')
        ->using(fn (string $input): string => "Result: {$input}")
        ->concurrent();

    $toolCalls = [
        new ToolCall(id: 'call-a', name: 'tool_a', arguments: ['input' => 'value']),
    ];

    $results = $testHandler->testCallTools([$tool], $toolCalls);

    expect($results)->toHaveCount(1)
        ->and($results[0]->result)->toBe('Result: value');

    Event::assertNotDispatched(ToolCallStarted::class);
    Event::assertNotDispatched(ToolCallCompleted::class);
});
