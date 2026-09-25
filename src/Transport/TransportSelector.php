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

use Erikwang2013\Etcd\Exception\ConnectionException;

class TransportSelector
{
    /**
     * `auto` still answers with the HTTP transport, and `grpc` still has to be
     * asked for by name.
     *
     * The gRPC transport is no longer a skeleton: it builds real messages from
     * etcd's own protos (protos/) and can issue unary RPCs. But no machine this
     * package is developed or tested on has ext-grpc loaded, so not one RPC has
     * been observed end to end, and its streaming calls (watch, snapshot,
     * lease keep-alive) are refused rather than half-built. `auto` means "the
     * transport that has been measured against a real etcd", and that is HTTP.
     *
     * Switching `auto` over is a one-line change to make once someone has run
     * `transport: grpc` against a live cluster — that is how to try it today.
     *
     * @param array{endpoints: list<string>, transport?: string, timeout?: float, retry?: int, auth?: array{user: string, password: string}, options?: array} $config
     */
    public static function select(array $config): TransportInterface
    {
        $transport = $config['transport'] ?? 'auto';
        $endpoints = $config['endpoints'] ?? ['127.0.0.1:2379'];

        if (empty($endpoints)) {
            throw new ConnectionException('No etcd endpoints configured');
        }

        if ($transport === 'grpc') {
            return new GrpcTransport($endpoints, $config);
        }

        if ($transport === 'http' || $transport === 'auto') {
            return new HttpTransport($endpoints, $config);
        }

        throw new ConnectionException("Unknown transport: {$transport}");
    }
}
