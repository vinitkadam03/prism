<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Text\Request;

readonly class StepStartEvent extends StreamEvent
{
    public function __construct(
        string $id,
        int $timestamp,
        public Request $request,
    ) {
        parent::__construct($id, $timestamp);
    }

    public function type(): StreamEventType
    {
        return StreamEventType::StepStart;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
        ];
    }
}
