<?php

declare(strict_types=1);

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

    public function send(string $path, array $body): array
    {
        throw new ConnectionException('gRPC transport send() not yet implemented. Use HTTP transport or implement gRPC service stubs.');
    }

    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent): void
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
            if (!\class_exists('\Grpc\Channel')) {
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
        $this->currentEndpoint = $this->endpoints[\array_rand($this->endpoints)];
        return $this->currentEndpoint;
    }
}
