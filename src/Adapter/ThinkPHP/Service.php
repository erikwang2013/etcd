<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Adapter\ThinkPHP;

use Erikwang2013\Etcd\EtcdClient;
use think\Service as BaseService;

class Service extends BaseService
{
    public function register(): void
    {
        $this->app->bind('etcd', function () {
            $config = $this->app->config->get('etcd', []);
            return new EtcdClient($config);
        });

        $this->app->bind(EtcdClient::class, function () {
            return $this->app->get('etcd');
        });
    }
}
