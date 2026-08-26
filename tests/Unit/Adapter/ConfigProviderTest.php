<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Adapter;

use Erikwang2013\Etcd\Adapter\Hyperf\ConfigProvider;
use Erikwang2013\Etcd\EtcdClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfigProviderTest extends TestCase
{
    #[Test]
    public function invokeReturnsDependenciesAndPublish(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', '/tmp/etcd-test-base');
        }
        $config = (new ConfigProvider())();

        $this->assertArrayHasKey('dependencies', $config);
        $this->assertArrayHasKey('publish', $config);
        $this->assertIsArray($config['publish']);
    }

    #[Test]
    public function dependenciesMapEtcdClientToClosure(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', '/tmp/etcd-test-base');
        }
        $dependencies = (new ConfigProvider())()['dependencies'];

        $this->assertArrayHasKey(EtcdClient::class, $dependencies);
        $this->assertInstanceOf(\Closure::class, $dependencies[EtcdClient::class]);
    }

    #[Test]
    public function publishContainsConfigIdSourceAndDestination(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', '/tmp/etcd-test-base');
        }
        $publish = (new ConfigProvider())()['publish'];

        $this->assertCount(1, $publish);
        $this->assertSame('config', $publish[0]['id']);
        $this->assertSame(
            realpath(__DIR__ . '/../../../config/etcd.php'),
            realpath($publish[0]['source'])
        );
        $this->assertSame('/tmp/etcd-test-base/config/autoload/etcd.php', $publish[0]['destination']);
    }
}
