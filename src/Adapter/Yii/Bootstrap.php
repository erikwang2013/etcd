<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Adapter\Yii;

use Erikwang2013\Etcd\EtcdClient;
use Yii;
use yii\base\BootstrapInterface;

/**
 * Binds EtcdClient in Yii's DI container to the "etcd" component, so that
 *
 *     class MyService
 *     {
 *         public function __construct(private EtcdClient $etcd) {}
 *     }
 *
 * resolves the configured client instead of a default one:
 *
 *     'bootstrap'  => [\Erikwang2013\Etcd\Adapter\Yii\Bootstrap::class],
 *     'components' => ['etcd' => ['class' => Component::class, 'options' => [...]]],
 *
 * composer.json also lists it under `extra.bootstrap`, which yii2-composer
 * picks up on its own — applications with that plugin installed get the
 * binding without touching the `bootstrap` array.
 */
class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app)
    {
        Yii::$container->setSingleton(EtcdClient::class, function () use ($app) {
            return $app->get('etcd')->getClient();
        });
    }
}
