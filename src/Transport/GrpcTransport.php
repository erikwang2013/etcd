<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

/**
 * NOTE: implementing this transport needs message stubs generated from etcd's
 * rpc.proto (protoc --php_out). The hand-written src/Protobuf/ stubs were removed:
 * no wire (de)serialization, 64-bit fields typed int where the gateway sends
 * strings (uint64 overflows PHP int), enums typed int where the gateway sends names.
 */

namespace Erikwang2013\Etcd\Transport;

use Erikwang2013\Etcd\Exception\ConnectionException;

class GrpcTransport implements TransportInterface
{
    private array $endpoints;
    private array $config;
    private string $currentEndpoint;

    public function __construct(array $endpoints, array $config = [])
    {
        $this->endpoints = $endpoints;
        $this->config = $config;
        $this->currentEndpoint = $endpoints[0];
    }

    public function send(string $path, array $body, ?float $timeout = null): array
    {
        throw new ConnectionException('gRPC transport send() not yet implemented. Use HTTP transport or implement gRPC service stubs.');
    }

    public function sendRaw(string $path, ?float $timeout = null): string
    {
        throw new ConnectionException('gRPC transport sendRaw() not yet implemented. Use HTTP transport.');
    }

    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent, array $options = []): void
    {
        throw new ConnectionException('gRPC transport watch() not yet implemented. Use HTTP transport.');
    }

    /**
     * @return mixed \Grpc\Channel
     */
    public function getChannel(string $endpoint)
    {
        static $channels = [];
        if (!isset($channels[$endpoint])) {
            if (!class_exists('\Grpc\Channel')) {
                throw new ConnectionException('gRPC extension not available. Install grpc/grpc or use HTTP transport.');
            }
            $channels[$endpoint] = new \Grpc\Channel(
                $endpoint,
                ['credentials' => \Grpc\ChannelCredentials::createInsecure()]
            );
        }
        return $channels[$endpoint];
    }

    public function getCurrentEndpoint(): string
    {
        return $this->currentEndpoint;
    }

    private function pickEndpoint(): string
    {
        $this->currentEndpoint = $this->endpoints[array_rand($this->endpoints)];
        return $this->currentEndpoint;
    }
}
