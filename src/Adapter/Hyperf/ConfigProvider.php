<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Adapter\Hyperf;

use Erikwang2013\Etcd\EtcdClient;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                EtcdClient::class => function () {
                    $config = [];
                    if (function_exists('\Hyperf\Support\env')) {
                        $config = \Hyperf\Support\env('etcd', []);
                    }
                    return new EtcdClient($config);
                },
            ],
            'publish' => [
                [
                    'id'          => 'config',
                    'description' => 'etcd client config',
                    'source'      => __DIR__ . '/../../../../config/etcd.php',
                    'destination' => BASE_PATH . '/config/autoload/etcd.php',
                ],
            ],
        ];
    }
}
