<?php

declare(strict_types=1);

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
