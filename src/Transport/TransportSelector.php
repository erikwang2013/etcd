<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Transport;

use Erikwang2013\Etcd\Exception\ConnectionException;

class TransportSelector
{
    /**
     * @param array{endpoints: list<string>, transport?: string, timeout?: float, retry?: int, auth?: array{user: string, password: string}, options?: array} $config
     */
    public static function select(array $config): TransportInterface
    {
        $transport = $config['transport'] ?? 'http';
        $endpoints = $config['endpoints'] ?? ['127.0.0.1:2379'];

        if (empty($endpoints)) {
            throw new ConnectionException('No etcd endpoints configured');
        }

        if ($transport === 'grpc') {
            return new GrpcTransport($endpoints, $config);
        }

        if ($transport === 'http') {
            return new HttpTransport($endpoints, $config);
        }

        if (extension_loaded('grpc')) {
            return new GrpcTransport($endpoints, $config);
        }

        return new HttpTransport($endpoints, $config);
    }
}
