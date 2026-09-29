<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

/*
 * Yii3 DI definitions, merged into the `di` group by yiisoft/config.
 * yiisoft/di keeps only shared instances, so every injection of EtcdClient
 * gets the same client.
 */

use Erikwang2013\Etcd\EtcdClient;

/** @psalm-var array{erikwang2013/etcd?: array} $params */

return [
    EtcdClient::class => static function () use ($params): EtcdClient {
        return new EtcdClient($params['erikwang2013/etcd'] ?? []);
    },
];
