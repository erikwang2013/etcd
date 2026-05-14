<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Adapter\Laravel;

use Erikwang2013\Etcd\EtcdClient;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../../../config/etcd.php', 'etcd'
        );

        $this->app->singleton(EtcdClient::class, function ($app) {
            return new EtcdClient($app['config']->get('etcd', []));
        });

        $this->app->alias(EtcdClient::class, 'etcd');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../../../config/etcd.php' => config_path('etcd.php'),
        ], 'etcd-config');
    }
}
