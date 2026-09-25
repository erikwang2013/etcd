<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Support;

use Erikwang2013\Etcd\Transport\TransportInterface;

/** Scripted fake TransportInterface; responses are consumed in FIFO order. */
class FakeTransport implements TransportInterface
{
    /** @var list<array{0: string, 1: array}> */
    public array $sent = [];
    /** Per-call timeouts, parallel to $sent (kept out of it so tuple assertions stay valid). */
    public array $timeouts = [];

    /** @var list<array> */
    public array $responses = [];

    public string $rawResponse = '';

    /** @var list<string> blobs replayed by sendStream() */
    public array $streamBlobs = [];

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

    public function send(string $path, array $body, ?float $timeout = null): array
    {
        $this->sent[] = [$path, $body];
        $this->timeouts[] = $timeout;
        if ($this->sendException !== null) {
            throw $this->sendException;
        }
        return array_shift($this->responses) ?? [];
    }

    public function sendStream(string $path, callable $onBlob, ?float $timeout = null): void
    {
        $this->sent[] = [$path, []];
        $this->timeouts[] = $timeout;
        if ($this->rawException !== null) {
            throw $this->rawException;
        }
        foreach ($this->streamBlobs as $blob) {
            $onBlob($blob);
        }
    }

    public function sendRaw(string $path, ?float $timeout = null): string
    {
        $this->sent[] = [$path, []];
        $this->timeouts[] = $timeout;
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
