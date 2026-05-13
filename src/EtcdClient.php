<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd;

use Erikwang2013\Etcd\Transport\TransportInterface;
use Erikwang2013\Etcd\Transport\TransportSelector;
use Erikwang2013\Etcd\Kv\KvClient;
use Erikwang2013\Etcd\Watch\WatchClient;
use Erikwang2013\Etcd\Lease\LeaseClient;
use Erikwang2013\Etcd\Auth\AuthClient;
use Erikwang2013\Etcd\Cluster\ClusterClient;
use Erikwang2013\Etcd\Maintenance\MaintenanceClient;

class EtcdClient
{
    private TransportInterface $transport;
    private ?KvClient $kvClient = null;
    private ?WatchClient $watchClient = null;
    private ?LeaseClient $leaseClient = null;
    private ?AuthClient $authClient = null;
    private ?ClusterClient $clusterClient = null;
    private ?MaintenanceClient $maintenanceClient = null;
    private array $config;

    private static ?self $instance = null;

    /**
     * @param array{endpoints: list<string>, transport?: string, timeout?: float, retry?: int, auth?: array{user: string, password: string}, options?: array} $config
     */
    public function __construct(array $config = [])
    {
        $this->config = \array_merge(
            ['endpoints' => ['127.0.0.1:2379'], 'transport' => 'http', 'timeout' => 5.0, 'retry' => 2],
            $config
        );
        $this->transport = TransportSelector::select($this->config);
    }

    /**
     * Get or create the singleton instance (useful for Webman / non-DI frameworks).
     */
    public static function instance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Reset the singleton instance (useful for testing).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function kv(): KvClient
    {
        return $this->kvClient ??= new KvClient($this->transport);
    }

    public function watch(): WatchClient
    {
        return $this->watchClient ??= new WatchClient($this->transport);
    }

    public function lease(): LeaseClient
    {
        return $this->leaseClient ??= new LeaseClient($this->transport);
    }

    public function auth(): AuthClient
    {
        return $this->authClient ??= new AuthClient($this->transport);
    }

    public function cluster(): ClusterClient
    {
        return $this->clusterClient ??= new ClusterClient($this->transport);
    }

    public function maintenance(): MaintenanceClient
    {
        return $this->maintenanceClient ??= new MaintenanceClient($this->transport, $this->config);
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function config(): array
    {
        return $this->config;
    }
}
