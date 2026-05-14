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

use think\Facade as BaseFacade;

/**
 * @method static \Erikwang2013\Etcd\Kv\KvClient kv()
 * @method static \Erikwang2013\Etcd\Watch\WatchClient watch()
 * @method static \Erikwang2013\Etcd\Lease\LeaseClient lease()
 * @method static \Erikwang2013\Etcd\Auth\AuthClient auth()
 * @method static \Erikwang2013\Etcd\Cluster\ClusterClient cluster()
 * @method static \Erikwang2013\Etcd\Maintenance\MaintenanceClient maintenance()
 */
class Facade extends BaseFacade
{
    protected static function getFacadeClass(): string
    {
        return 'etcd';
    }
}
