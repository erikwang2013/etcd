<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Lock;

use Erikwang2013\Etcd\Election\ElectionClient;
use Erikwang2013\Etcd\Lease\LeaseClient;
use Erikwang2013\Etcd\Transport\TransportInterface;
use Erikwang2013\Etcd\Exception\EtcdException;

/**
 * A distributed lock: mutual exclusion with FIFO fairness between waiters.
 *
 * It is implemented here, client-side, on the Election service — etcd's HTTP
 * gateway serves no lock service at all (measured on 3.5.17: both
 * /v3/lock/acquire and /v3/lock/release answer 404), so there is no server-side
 * lock to call. acquire() is a campaign on a lock-specific prefix with a fresh
 * lease, release() is a resign plus a lease revoke, and the holder is whoever
 * owns the smallest key under the prefix — the same arrangement as etcd's own Go
 * client (concurrency.NewMutex), with the same consequences: the lock is only as
 * long-lived as its lease — if the holder dies, or stops keeping the lease
 * alive, the lock expires on its own and the next waiter takes it — and it is
 * advisory, since a leader is identified by its key and anyone able to write
 * under the prefix can free the lock.
 *
 *     $lock = $etcd->lock()->acquire('/locks/report/', ttl: 30, timeout: 10.0);
 *     try { ... } finally { $etcd->lock()->release($lock); }
 *
 * Holding for longer than the TTL means renewing the lease yourself:
 * `$etcd->lease()->keepAlive($lock['lease'])` before it expires.
 */
class LockClient
{
    private ElectionClient $election;
    private LeaseClient $leases;

    public function __construct(TransportInterface $transport)
    {
        $this->election = new ElectionClient($transport);
        $this->leases = new LeaseClient($transport);
    }

    /**
     * Take the lock, waiting for the current holder if there is one.
     *
     * @param string $name    lock prefix, e.g. '/locks/report/'. Every acquire() on the same
     *                        prefix competes for it; ending in '/' keeps the keys readable.
     * @param int    $ttl     lease lifetime in seconds (>= 1). The lock is lost this long after
     *                        the holder's last keep-alive, so a crashed holder frees it.
     * @param ?float $timeout seconds to wait to acquire, null = transport default (typically 5).
     *                        On expiry a ConnectionException is thrown and the lock is NOT held;
     *                        the queued campaign is withdrawn server-side when the request drops.
     * @return array{header: array, name: string, key: string, rev: int, lease: int|string, value: ?string, owner: string}
     * @throws EtcdException
     */
    public function acquire(string $name, int $ttl = 30, ?float $timeout = null): array
    {
        $lease = $this->leases->grant($ttl)['ID'];
        $owner = self::owner();

        try {
            $lock = $this->election->campaign($name, $owner, $lease, $timeout);
        } catch (\Throwable $e) {
            // The lease is what keeps the leader key alive, and this attempt may
            // even have won the campaign without its response reaching us — so
            // the lease is revoked before the failure is reported, otherwise the
            // caller would hold a lock it never heard about.
            try {
                $this->leases->revoke($lease);
            } catch (\Throwable) {
                // the original failure is the one worth surfacing
            }
            throw $e;
        }

        // Same string as 'value' (it is what was campaigned with); named for
        // what it means here.
        $lock['owner'] = $owner;

        return $lock;
    }

    /**
     * Release the lock so the next waiter can acquire it.
     *
     * Only the acquisition described by $lock is affected: revoking its lease
     * deletes exactly its leader key, so a stale descriptor cannot release the
     * lock out from under its current holder.
     *
     * @param array $lock the array returned by acquire()
     * @throws EtcdException
     */
    public function release(array $lock): void
    {
        if (!isset($lock['lease'])) {
            throw new EtcdException('release() needs the array returned by acquire().');
        }

        try {
            $this->election->resign($lock);
        } finally {
            // Resign hands the lock over immediately; the revoke is what frees
            // the lease, and it releases the lock too should the resign fail.
            $this->revokeQuietly($lock['lease']);
        }
    }

    /**
     * Who holds the lock, or null while it is free.
     *
     * Same shape as the array acquire() returns, 'owner' included: the two are
     * the same descriptor seen at different times.
     *
     * @return array{header: array, name: string, key: string, rev: int, lease: int|string, value: ?string, owner: ?string}|null
     */
    public function leader(string $name): ?array
    {
        $leader = $this->election->leader($name);
        if ($leader !== null) {
            $leader['owner'] = $leader['value'];
        }

        return $leader;
    }

    /**
     * An expired lease is not a release failure: etcd answers HTTP 404
     * "requested lease not found" for a lease that has already gone (measured),
     * and by then the lock it held is gone with it.
     */
    private function revokeQuietly(int|string $lease): void
    {
        try {
            $this->leases->revoke($lease);
        } catch (EtcdException $e) {
            if (!str_contains($e->getMessage(), 'requested lease not found')) {
                throw $e;
            }
        }
    }

    /** Unique per acquisition, and readable in `etcdctl get` when tracing a stuck holder. */
    private static function owner(): string
    {
        return sprintf('%s-%d-%s', gethostname() ?: 'php', getmypid(), bin2hex(random_bytes(8)));
    }
}
