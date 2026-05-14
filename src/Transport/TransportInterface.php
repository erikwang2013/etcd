<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

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
