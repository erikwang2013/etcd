<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Transport;

interface TransportInterface
{
    public function send(string $path, array $body): array;

    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent): void;
}
