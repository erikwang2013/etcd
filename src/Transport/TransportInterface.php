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
    public function send(string $path, array $body, ?float $timeout = null): array;

    /**
     * Send a request and return the raw response body (for binary endpoints like snapshot).
     */
    public function sendRaw(string $path, ?float $timeout = null): string;

    /**
     * @param callable $onEvent  function(array $events): void
     * @param array    $options  'prevKv' => bool, 'progressNotify' => bool
     */
    /**
     * Blocking watch. $options may carry prevKv, progressNotify and a
     * Support\WatchHandle under 'handle' to stop the loop from outside.
     */
    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent, array $options = []): void;

    /**
     * Read a byte-stream RPC without buffering it whole.
     *
     * /v3/maintenance/snapshot answers as NDJSON frames of base64 blobs; each
     * decoded blob is handed to $onBlob as it arrives, so a multi-gigabyte
     * database never has to fit in memory.
     *
     * @param callable(string):void $onBlob
     * @param ?float $timeout per-call timeout in seconds; null = configured default
     */
    public function sendStream(string $path, callable $onBlob, ?float $timeout = null): void;
}
