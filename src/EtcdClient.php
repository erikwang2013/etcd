<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

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
     * @param array{endpoints: list<string>, transport?: string, scheme?: string, timeout?: float, retry?: int, auth?: array{user: string, password: string}, options?: array} $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge(
            ['endpoints' => ['127.0.0.1:2379'], 'transport' => 'auto', 'scheme' => 'http', 'timeout' => 5.0, 'retry' => 2],
            $config
        );
        $this->transport = TransportSelector::select($this->config);
    }

    /**
     * Get or create the singleton instance (useful for Webman / non-DI frameworks).
     *
     * @throws \LogicException if called with new config after singleton already initialized
     */
    public static function instance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        } elseif (!empty($config)) {
            throw new \LogicException('EtcdClient::instance() called with config after singleton already initialized. Call resetInstance() first if you need to reinitialize.');
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

    /**
     * Calculate the range_end for a given prefix, so that a range query
     * on [prefix, range_end) returns all keys with that prefix.
     */
    public static function prefixToRangeEnd(string $prefix): string
    {
        if ($prefix === '') {
            return "\x00";
        }
        $len = strlen($prefix);
        for ($i = $len - 1; $i >= 0; $i--) {
            $c = ord($prefix[$i]);
            if ($c < 0xFF) {
                return substr($prefix, 0, $i) . chr($c + 1);
            }
        }
        // prefix is all 0xFF: range [prefix, "\x00") covers every possible byte after it
        return "\x00";
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
        return $this->maintenanceClient ??= new MaintenanceClient($this->transport);
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
