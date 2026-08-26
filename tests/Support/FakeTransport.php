<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Support;

use Erikwang2013\Etcd\Transport\TransportInterface;

/**
 * Scripted fake TransportInterface for unit tests.
 *
 * Queue responses via addResponse(); send() returns them in FIFO order.
 * sendRaw() returns $rawResponse; watch() records its arguments and invokes
 * $onEvent with each entry of $watchEventBatches.
 */
class FakeTransport implements TransportInterface
{
    /** @var list<array{0: string, 1: array}> */
    public array $sent = [];

    /** @var list<array> */
    public array $responses = [];

    public string $rawResponse = '';

    /** @var list<list<array>> */
    public array $watchEventBatches = [];

    /** @var list<array{0: string, 1: string, 2: int, 3: array}> */
    public array $watchCalls = [];

    public ?\Throwable $sendException = null;

    public ?\Throwable $rawException = null;

    public ?\Throwable $watchException = null;

    public function addResponse(array $response): self
    {
        $this->responses[] = $response;
        return $this;
    }

    public function send(string $path, array $body): array
    {
        $this->sent[] = [$path, $body];
        if ($this->sendException !== null) {
            throw $this->sendException;
        }
        return array_shift($this->responses) ?? [];
    }

    public function sendRaw(string $path): string
    {
        $this->sent[] = [$path, []];
        if ($this->rawException !== null) {
            throw $this->rawException;
        }
        return $this->rawResponse;
    }

    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent, array $options = []): void
    {
        $this->watchCalls[] = [$key, $rangeEnd, $startRevision, $options];
        if ($this->watchException !== null) {
            throw $this->watchException;
        }
        foreach ($this->watchEventBatches as $batch) {
            $onEvent($batch);
        }
    }
}
