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

use Erikwang2013\Etcd\Support\Int64;
use Erikwang2013\Etcd\Transport\TransportInterface;
use Erikwang2013\Etcd\Exception\EtcdException;

/**
 * Lease ids are int64 and may exceed PHP_INT_MAX, so an id is `int|string` here:
 * an id read out of list() or grant() can be passed straight back to revoke(),
 * keepAlive() or timeToLive() whichever form it came in as.
 */
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
     * A TTL below 1 second is refused before the request is sent: etcd accepts
     * it and answers TTL "2", but the lease is expired by the time the response
     * is parsed (measured), so a caller that keys on granted TTL would attach
     * keys to a lease that no longer exists.
     *
     * @param int        $ttl lease lifetime in seconds, >= 1
     * @param int|string $id  requested lease id; 0 lets the server pick
     * @return array{header: array, ID: int|string, TTL: int}
     * @throws EtcdException when $ttl < 1
     */
    public function grant(int $ttl, int|string $id = 0): array
    {
        if ($ttl < 1) {
            throw new EtcdException("Lease TTL must be at least 1 second, got {$ttl}.");
        }

        $body = ['TTL' => $ttl];
        if ((string) $id !== '' && (string) $id !== '0') {
            $body['ID'] = $id;
        }
        $response = $this->transport->send('/v3/lease/grant', $body);
        return [
            'header' => $response['header'] ?? [],
            'ID'     => Int64::decode($response['ID'] ?? 0),
            'TTL'    => (int) ($response['TTL'] ?? $ttl),
        ];
    }

    /**
     * Revoke a lease. All keys attached to the lease will be deleted.
     *
     * @param int|string $id lease id, as returned by grant() or list()
     * @return array{header: array}
     */
    public function revoke(int|string $id): array
    {
        $response = $this->transport->send('/v3/lease/revoke', ['ID' => $id]);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Keep a lease alive with a single keep-alive request.
     * For continuous keep-alive, call this periodically.
     *
     * @param int|string $id lease id, as returned by grant() or list()
     * @return array{header: array, ID: int|string, TTL: int}
     */
    public function keepAlive(int|string $id): array
    {
        $response = $this->transport->send('/v3/lease/keepalive', ['ID' => $id]);
        $leaseId = Int64::decode($response['ID'] ?? 0);
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
     * @param int|string $id lease id, as returned by grant() or list()
     * @return array{header: array, ID: int|string, TTL: int, grantedTTL: int, keys: list<string>}
     */
    public function timeToLive(int|string $id, bool $keys = false): array
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
            'ID'         => Int64::decode($response['ID'] ?? 0),
            'TTL'        => (int) ($response['TTL'] ?? 0),
            'grantedTTL' => (int) ($response['grantedTTL'] ?? 0),
            'keys'       => $decodedKeys,
        ];
    }

    /**
     * List all active leases.
     *
     * @return array{header: array, leases: list<array{ID: int|string}>}
     */
    public function list(): array
    {
        $response = $this->transport->send('/v3/lease/leases', []);
        $leases = [];
        foreach ($response['leases'] ?? [] as $ls) {
            $leases[] = ['ID' => Int64::decode($ls['ID'] ?? 0)];
        }
        return [
            'header' => $response['header'] ?? [],
            'leases' => $leases,
        ];
    }
}
