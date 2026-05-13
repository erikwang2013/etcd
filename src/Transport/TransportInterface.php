<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Transport;

interface TransportInterface
{
    public function send(string $path, array $body): array;

    /**
     * @param callable $onEvent  function(array $events): void
     * @param array    $options  'prevKv' => bool, 'progressNotify' => bool
     */
    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent, array $options = []): void;
}
