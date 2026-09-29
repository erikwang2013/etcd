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
use yii\base\Component as BaseComponent;

/**
 * Yii2 application component.
 *
 *     'components' => [
 *         'etcd' => [
 *             'class'   => \Erikwang2013\Etcd\Adapter\Yii\Component::class,
 *             'options' => ['endpoints' => ['10.0.0.1:2379'], 'timeout' => 3.0],
 *         ],
 *     ],
 *
 * Then `Yii::$app->etcd->kv()->put('/foo', 'bar')` — the eight subsystem
 * accessors are forwarded to the client, and the client itself is reachable
 * as `Yii::$app->etcd->client`.
 *
 * @method \Erikwang2013\Etcd\Kv\KvClient kv()
 * @method \Erikwang2013\Etcd\Watch\WatchClient watch()
 * @method \Erikwang2013\Etcd\Lease\LeaseClient lease()
 * @method \Erikwang2013\Etcd\Auth\AuthClient auth()
 * @method \Erikwang2013\Etcd\Cluster\ClusterClient cluster()
 * @method \Erikwang2013\Etcd\Maintenance\MaintenanceClient maintenance()
 * @method \Erikwang2013\Etcd\Election\ElectionClient election()
 * @method \Erikwang2013\Etcd\Lock\LockClient lock()
 */
class Component extends BaseComponent
{
    /** Client config; keys left out fall back to config/etcd.php (the ETCD_* env vars). */
    public array $options = [];

    private ?EtcdClient $client = null;

    public function getClient(): EtcdClient
    {
        return $this->client ??= new EtcdClient(array_merge(
            require __DIR__ . '/../../../config/etcd.php',
            $this->options
        ));
    }

    /**
     * Forward calls the component itself does not answer (kv(), watch(), …) to
     * the client. Behaviors still win, so Yii's own attachBehavior() keeps working.
     */
    public function __call($name, $params)
    {
        if (!$this->hasMethod($name)) {
            $client = $this->getClient();
            if (is_callable([$client, $name])) {
                return $client->$name(...$params);
            }
        }

        return parent::__call($name, $params);
    }
}
