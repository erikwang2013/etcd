<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Adapter\Laravel;

use Illuminate\Support\Facades\Facade as BaseFacade;

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
    protected static function getFacadeAccessor(): string
    {
        return 'etcd';
    }
}
