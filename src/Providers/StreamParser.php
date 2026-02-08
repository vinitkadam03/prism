<?php

declare(strict_types=1);

namespace Prism\Prism\Providers;

use Generator;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\TurnResult;
use Prism\Prism\Text\Request;

interface StreamParser
{
    /**
     * Send request and yield content events for a single API turn.
     *
     * @return Generator<int, StreamEvent, mixed, TurnResult>
     */
    public function parse(Request $request): Generator;

    public function providerName(): string;
}
