<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Election;

use Erikwang2013\Etcd\Support\Int64;
use Erikwang2013\Etcd\Support\KeyValue;
use Erikwang2013\Etcd\Transport\TransportInterface;
use Erikwang2013\Etcd\Watch\WatchClient;
use Erikwang2013\Etcd\Exception\EtcdException;

/**
 * Leader election — etcd's v3 Election service.
 *
 * The HTTP gateway is not a transparent proxy for the gRPC service, and the
 * differences bite, so they are written down here (all measured on 3.5.17):
 *
 *  - On the server, Campaign, Leader and Observe are streaming RPCs. The gateway
 *    serves Campaign and Leader as plain buffered responses (`Content-Length`
 *    set, no `{"result": ...}` envelope) carrying their single message, so
 *    send() works for them. Campaign blocks for as long as the election is
 *    contested and answers when it is won — a contended campaign is a long HTTP
 *    request, and $timeout is how long you are willing to wait for it. Observe
 *    is forwarded as a real stream (`{"result": ...}` frames, chunked, never
 *    closed); TransportInterface has no way to read that back, so observe()
 *    below is built from a prefix watch instead.
 *
 *  - A leader is an ordinary key `<name>/<hex-lease>` bound to the campaign's
 *    lease, not to the HTTP request. The campaign response closing does not
 *    resign (measured: still leader 3 s later, and for as long as the lease is
 *    kept alive) but a campaign that is still *waiting* is withdrawn server-side
 *    when its connection drops (measured: after a client timeout and the
 *    holder's lease being revoked, the election had no leader at all) — so a
 *    campaign that timed out has not left a hidden leader behind.
 *
 *  - resign() and proclaim() are fence-checked on name, key AND rev together.
 *    etcd answers 200 while doing nothing when one of them is missing (measured:
 *    a resign carrying only the key looks successful and leaves the leader in
 *    place), so both refuse an incomplete descriptor here instead of reporting a
 *    release that never happened.
 *
 * The leader descriptor handed around by this class is flat — the same array
 * comes back from campaign() and leader() and goes straight into resign() and
 * proclaim():
 *
 *     ['header' => [...], 'name' => string, 'key' => string, 'rev' => int,
 *      'lease' => int|string, 'value' => ?string]
 *
 * `name`, `key` and `value` are base64-decoded (like every other key and value
 * this library returns) and are re-encoded on the way out; `rev` is the leader
 * key's create revision — the revision the election was won at.
 */
class ElectionClient
{
    private TransportInterface $transport;
    private ?WatchClient $watchClient = null;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Campaign for leadership of $name and block until it is won.
     *
     * The value is what the leader key holds — etcd's own clients put an identity
     * in it (host, pid, random suffix) so a stuck leader can be traced back to a
     * process.
     *
     * @param string     $name    election name; unix-style paths ("/locks/x/") keep etcd's own tools readable
     * @param string     $value   value to campaign with, readable through leader()
     * @param int|string $lease   lease id from LeaseClient::grant(); the leader key is bound to it
     * @param ?float     $timeout seconds to wait for the election to be won, null = transport default.
     *                            On expiry the transport throws a ConnectionException and you do NOT
     *                            hold the election: the queued campaign is withdrawn server-side.
     * @return array{header: array, name: string, key: string, rev: int, lease: int|string, value: ?string}
     * @throws EtcdException when the lease does not exist ("requested lease not found")
     */
    public function campaign(string $name, string $value, int|string $lease, ?float $timeout = null): array
    {
        $response = $this->transport->send('/v3/election/campaign', [
            'name'  => base64_encode($name),
            'value' => base64_encode($value),
            'lease' => $lease,
        ], $timeout);

        // The campaign response carries a LeaderKey, which has no value field —
        // the value that was sent is what the leader key now holds.
        return self::describe($response, $name, $value);
    }

    /**
     * Replace the value of the leader key without a new election.
     *
     * @param string $value  new value for the leader key
     * @param array  $leader leader descriptor from campaign() or leader()
     * @return array{header: array}
     * @throws EtcdException when the descriptor is incomplete (see leaderKey())
     */
    public function proclaim(string $value, array $leader): array
    {
        [$name, $key, $rev] = self::leaderKey($leader, __FUNCTION__);

        $response = $this->transport->send('/v3/election/proclaim', [
            'leader' => [
                'name'  => base64_encode($name),
                'key'   => base64_encode($key),
                'rev'   => $rev,
                'lease' => $leader['lease'] ?? 0,
            ],
            'value'  => base64_encode($value),
        ]);

        return ['header' => $response['header'] ?? []];
    }

    /**
     * The current leader, or null when nobody holds the election.
     *
     * etcd answers HTTP 500 `{"error":"election: no leader"}` for an election
     * nobody has won (measured), which is an empty answer rather than a failure.
     *
     * @return array{header: array, name: string, key: string, rev: int, lease: int|string, value: ?string}|null
     */
    public function leader(string $name): ?array
    {
        try {
            $response = $this->transport->send('/v3/election/leader', ['name' => base64_encode($name)]);
        } catch (EtcdException $e) {
            if (str_contains($e->getMessage(), 'election: no leader')) {
                return null;
            }
            throw $e;
        }

        $descriptor = self::describe($response, $name, null);

        return $descriptor['key'] === '' ? null : $descriptor;
    }

    /**
     * Follow the election's leader until the caller stops.
     *
     * The server offers this as an observe stream, but the gateway forwards it as
     * chunked `{"result": ...}` frames that never close, and the only
     * frame-consuming method on TransportInterface (sendStream) hands back
     * `result.blob` frames only — an observe frame would be dropped in silence.
     * So this is the equivalent assembled from parts that do work: watch the
     * election prefix and re-read the leader whenever the keys under it change.
     *
     * The cost is one extra `leader` request per watch event under the prefix,
     * but $onLeader only fires when the leader actually changed — a candidate
     * queueing up is not a new leader. Pass a WatchHandle in $options['handle']
     * to stop the loop — see WatchHandle.
     *
     * @param callable(?array):void $onLeader current leader descriptor, null while the election has no leader
     * @param array                 $options  watch options, forwarded to WatchClient::watch()
     */
    public function observe(string $name, callable $onLeader, array $options = []): void
    {
        $last = null;
        $readAt = 0;
        $publish = function () use ($name, $onLeader, &$last, &$readAt): void {
            $leader = $this->leader($name);
            // Identified by what it holds, not by the key alone: proclaim()
            // rewrites the value without moving the key.
            $fingerprint = ($leader['key'] ?? '') . "\0" . ($leader['value'] ?? '');
            if ($fingerprint === $last) {
                return;
            }
            $last = $fingerprint;
            $readAt = (int) ($leader['header']['revision'] ?? 0);
            $onLeader($leader);
        };

        // As the server's stream does: report the state before watching changes.
        $publish();
        // ...and watch from the revision that state was read at, so a leader
        // elected in between is replayed instead of missed. With no leader at all
        // there is no revision to resume from (etcd answers the lookup with an
        // error, not a header) and the watch starts from the current one.
        if ($readAt > 0 && !isset($options['startRevision'])) {
            $options['startRevision'] = $readAt + 1;
        }

        $this->watch()->watchPrefix($name, static function (array $events) use ($publish): void {
            $publish();
        }, $options);
    }

    /**
     * Give up leadership so the next candidate can take it.
     *
     * The leader key is deleted; the lease stays alive (revoke it if you are done
     * with it). Only the acquisition described by $leader is affected — a stale
     * descriptor deletes a key that is already gone and leaves the current
     * holder alone.
     *
     * @param array $leader leader descriptor from campaign() or leader()
     * @return array{header: array}
     * @throws EtcdException when the descriptor is incomplete (see leaderKey())
     */
    public function resign(array $leader): array
    {
        [$name, $key, $rev] = self::leaderKey($leader, __FUNCTION__);

        $response = $this->transport->send('/v3/election/resign', [
            'leader' => [
                'name' => base64_encode($name),
                'key'  => base64_encode($key),
                'rev'  => $rev,
            ],
        ]);

        return ['header' => $response['header'] ?? []];
    }

    /**
     * Normalize the two leader shapes the gateway sends into one descriptor:
     * campaign answers with a `LeaderKey` ({name, key, rev, lease}), leader
     * answers with a `KeyValue` ({key, create_revision, value, lease}) and no
     * name.
     *
     * @param array<string, mixed> $response
     * @return array{header: array, name: string, key: string, rev: int, lease: int|string, value: ?string}
     */
    private static function describe(array $response, string $name, ?string $value): array
    {
        $leader = $response['leader'] ?? null;
        if (is_array($leader)) {
            return [
                'header' => $response['header'] ?? [],
                'name'   => self::decodeB64((string) ($leader['name'] ?? $name)),
                'key'    => self::decodeB64((string) ($leader['key'] ?? '')),
                'rev'    => (int) ($leader['rev'] ?? 0),
                'lease'  => Int64::decode($leader['lease'] ?? 0),
                'value'  => $value,
            ];
        }

        $kv = KeyValue::decode(is_array($response['kv'] ?? null) ? $response['kv'] : []);

        return [
            'header' => $response['header'] ?? [],
            'name'   => $name,
            'key'    => $kv['key'],
            // create_revision, not mod_revision: it is the revision the election
            // was won at, and the one resign()/proclaim() fence against.
            'rev'    => $kv['create_revision'],
            'lease'  => $kv['lease'],
            'value'  => $kv['value'],
        ];
    }

    /**
     * The name, key and rev a resign/proclaim request needs.
     *
     * @return array{0: string, 1: string, 2: int}
     * @throws EtcdException
     */
    private static function leaderKey(array $leader, string $method): array
    {
        $name = (string) ($leader['name'] ?? '');
        $key  = (string) ($leader['key'] ?? '');
        $rev  = (int) ($leader['rev'] ?? 0);

        if ($name === '' || $key === '' || $rev === 0) {
            throw new EtcdException(
                "{$method}() needs the full leader descriptor from campaign()/leader() (name, key and rev): "
                . 'etcd accepts an incomplete one with HTTP 200 and silently does nothing.'
            );
        }

        return [$name, $key, $rev];
    }

    private static function decodeB64(string $value): string
    {
        $decoded = base64_decode($value, true);

        return $decoded !== false ? $decoded : $value;
    }

    private function watch(): WatchClient
    {
        return $this->watchClient ??= new WatchClient($this->transport);
    }
}
