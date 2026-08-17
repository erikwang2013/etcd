<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Lease;

use Erikwang2013\Etcd\Transport\TransportInterface;
use Erikwang2013\Etcd\Exception\EtcdException;

class LeaseClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Grant a lease with a TTL in seconds.
     *
     * @return array ['header' => [...], 'ID' => int, 'TTL' => int]
     */
    public function grant(int $ttl, int $id = 0): array
    {
        $body = ['TTL' => $ttl];
        if ($id > 0) {
            $body['ID'] = $id;
        }
        $response = $this->transport->send('/v3/lease/grant', $body);
        return [
            'header' => $response['header'] ?? [],
            'ID'     => (int) ($response['ID'] ?? 0),
            'TTL'    => (int) ($response['TTL'] ?? $ttl),
        ];
    }

    /**
     * Revoke a lease. All keys attached to the lease will be deleted.
     *
     * @return array ['header' => [...]]
     */
    public function revoke(int $id): array
    {
        $response = $this->transport->send('/v3/lease/revoke', ['ID' => $id]);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Keep a lease alive with a single keep-alive request.
     * For continuous keep-alive, call this periodically.
     *
     * @return array ['header' => [...], 'ID' => int, 'TTL' => int]
     */
    public function keepAlive(int $id): array
    {
        $response = $this->transport->send('/v3/lease/keepalive', ['ID' => $id]);
        $leaseId = (int) ($response['ID'] ?? 0);
        if ($leaseId === 0) {
            throw new EtcdException("Lease {$id} expired or not found; keepalive failed");
        }
        return [
            'header' => $response['header'] ?? [],
            'ID'     => $leaseId,
            'TTL'    => (int) ($response['TTL'] ?? 0),
        ];
    }

    /**
     * Get TTL and attached keys for a lease.
     *
     * @return array ['header' => [...], 'ID' => int, 'TTL' => int, 'grantedTTL' => int, 'keys' => list<string>]
     */
    public function timeToLive(int $id, bool $keys = false): array
    {
        $response = $this->transport->send('/v3/lease/timetolive', [
            'ID'   => $id,
            'keys' => $keys,
        ]);
        $decodedKeys = [];
        foreach ($response['keys'] ?? [] as $k) {
            $d = base64_decode($k, true);
            $decodedKeys[] = $d !== false ? $d : $k;
        }
        return [
            'header'     => $response['header'] ?? [],
            'ID'         => (int) ($response['ID'] ?? 0),
            'TTL'        => (int) ($response['TTL'] ?? 0),
            'grantedTTL' => (int) ($response['grantedTTL'] ?? 0),
            'keys'       => $decodedKeys,
        ];
    }

    /**
     * List all active leases.
     *
     * @return array ['header' => [...], 'leases' => [['ID' => int], ...]]
     */
    public function list(): array
    {
        $response = $this->transport->send('/v3/lease/leases', []);
        $leases = [];
        foreach ($response['leases'] ?? [] as $ls) {
            $leases[] = ['ID' => (int) ($ls['ID'] ?? 0)];
        }
        return [
            'header' => $response['header'] ?? [],
            'leases' => $leases,
        ];
    }
}
