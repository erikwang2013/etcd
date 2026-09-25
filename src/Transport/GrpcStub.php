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

use Google\Protobuf\Internal\Message;

/**
 * The thin per-RPC wiring `grpc_php_plugin` emits with --grpc_out: a class
 * extending Grpc\BaseStub whose methods call _simpleRequest with the method
 * descriptor ("/package.Service/Method"), the request, a deserializer, metadata
 * and options. The plugin is not installed on the machine this was written on,
 * so the wiring is written out by hand; when it is available, generate the
 * service classes into protos/generated and delete this one — GrpcTransport
 * only wants a unary() behind it.
 *
 * It lives in its own file because `extends \Grpc\BaseStub` is resolved when the
 * file is loaded: next to GrpcTransport it made every GrpcTransport use fatal
 * with "Class Grpc\BaseStub not found" wherever ext-grpc is absent, which is
 * most machines (measured). Here, nothing loads it until invoke() has already
 * established that grpc is there.
 */
class GrpcStub extends \Grpc\BaseStub
{
    public function __construct(string $endpoint, object $credentials, object $channel)
    {
        parent::__construct($endpoint, ['credentials' => $credentials], $channel);
    }

    /**
     * @param callable(string): Message $deserialize
     * @param array<string, string>     $metadata
     * @param ?float                    $timeout seconds, as the transport interface defines it
     * @return array{0: Message, 1: object} [response, status] — grpc's own shape
     */
    public function unary(string $method, Message $request, callable $deserialize, array $metadata, ?float $timeout): array
    {
        /** @var \Grpc\UnaryCall $call */
        $call = $this->_simpleRequest($method, $request, $deserialize, $metadata);

        return $call->wait($timeout);
    }
}
