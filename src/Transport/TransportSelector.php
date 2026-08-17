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
