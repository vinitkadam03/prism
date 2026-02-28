<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\ToolApprovalResponse;

/**
 * @implements Arrayable<string, mixed>
 */
class ToolApprovalResponseMessage implements Arrayable, Message
{
    /**
     * @param  ToolApprovalResponse[]  $toolApprovalResponses
     */
    public function __construct(
        public readonly array $toolApprovalResponses
    ) {}

    public function findByToolCallId(string $id): ?ToolApprovalResponse
    {
        foreach ($this->toolApprovalResponses as $response) {
            if ($response->toolCallId === $id) {
                return $response;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'type' => 'tool_approval_response',
            'tool_approval_responses' => array_map(
                fn (ToolApprovalResponse $response): array => $response->toArray(),
                $this->toolApprovalResponses
            ),
        ];
    }
}
