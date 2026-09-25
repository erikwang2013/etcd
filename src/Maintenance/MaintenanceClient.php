<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Maintenance;

use Erikwang2013\Etcd\Support\Int64;
use Erikwang2013\Etcd\Transport\TransportInterface;
use Erikwang2013\Etcd\Exception\EtcdException;

class MaintenanceClient
{
    /** etcd's AlarmType enum: the gateway sends the name, a caller may pass either. */
    private const ALARM_TYPES = ['NONE' => 0, 'NOSPACE' => 1, 'CORRUPT' => 2];

    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Get the status of the connected etcd member.
     *
     * Member ids are uint64 and do not fit in a PHP int: `leader` (and `dbSize`,
     * `raftIndex`, ...) is an int when the value fits and the server's decimal
     * string when it does not — never a saturated int.
     *
     * An unhealthy member is reported through `errors`, not by throwing: etcd
     * answers this RPC with a 200 and a list of problems (an active NOSPACE
     * alarm, no leader yet), which is exactly the state a caller is asking about.
     *
     * @return array{header: array, version: string, dbSize: int|string, dbSizeInUse: int|string, leader: int|string, raftIndex: int|string, raftTerm: int|string, raftAppliedIndex: int|string, isLearner: bool, errors: list<string>}
     */
    public function status(): array
    {
        $response = $this->transport->send('/v3/maintenance/status', []);
        return [
            'header'           => $response['header'] ?? [],
            'version'          => $response['version'] ?? '',
            'dbSize'           => Int64::decode($response['dbSize'] ?? 0),
            'dbSizeInUse'      => Int64::decode($response['dbSizeInUse'] ?? 0),
            'leader'           => Int64::decode($response['leader'] ?? 0),
            'raftIndex'        => Int64::decode($response['raftIndex'] ?? 0),
            'raftTerm'         => Int64::decode($response['raftTerm'] ?? 0),
            'raftAppliedIndex' => Int64::decode($response['raftAppliedIndex'] ?? 0),
            'isLearner'        => (bool) ($response['isLearner'] ?? false),
            'errors'           => $response['errors'] ?? [],
        ];
    }

    /**
     * Manage etcd alarms.
     *
     * The wire carries the alarm type as its enum name ("NOSPACE") and omits
     * memberID when it is 0. Both notations are accepted here, and the result
     * carries the type both ways.
     *
     * Deactivate matches the exact (memberID, alarm) pair — memberID 0 is not a
     * wildcard. Measured on 3.5.16/3.5.17: action 2 with memberID 0 answers 200
     * and leaves the alarm set, as does an alarm type with no memberID; naming
     * both clears it (`etcdctl alarm disarm` clears it too; a bare 0 does not).
     * So clear an alarm by naming the member that raised it:
     *
     *     alarm(2, $alarm['name'], $alarm['memberID'])
     *
     * @param int        $action   0=get, 1=activate, 2=deactivate
     * @param int|string $alarm    0|'NONE', 1|'NOSPACE', 2|'CORRUPT' (0 for all)
     * @param int|string $memberID Member id (uint64; pass the string form from
     *                             memberList()); 0 for all members on a get
     * @return array{header: array, alarms: list<array{memberID: int|string, alarm: int, name: string}>}
     *         `alarm` is the numeric enum, `name` the wire string; an alarm this
     *         client does not know maps to -1 with `name` left as sent.
     */
    public function alarm(int $action = 0, int|string $alarm = 0, int|string $memberID = 0): array
    {
        $body = ['action' => $action];
        if (!self::isZero($memberID)) {
            $body['memberID'] = $memberID;
        }
        if (!self::isZero($alarm)) {
            $body['alarm'] = $alarm;
        }
        $response = $this->transport->send('/v3/maintenance/alarm', $body);

        $alarms = [];
        foreach ($response['alarms'] ?? [] as $entry) {
            $alarms[] = self::alarmEntry($entry);
        }

        return [
            'header' => $response['header'] ?? [],
            'alarms' => $alarms,
        ];
    }

    /**
     * Defragment the etcd database to reclaim storage.
     *
     * Rewrites the whole backend, so on a large database it takes far longer
     * than the transport's 5s default, which is meant for point queries.
     *
     * @param ?float $timeout per-call timeout in seconds; null allows this call 300s
     */
    public function defragment(?float $timeout = null): array
    {
        // Bulk disk work: the 5s default is wrong for it, so give it room.
        $response = $this->transport->send('/v3/maintenance/defragment', [], $timeout ?? 300.0);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Get the hash of the KV store (for integrity checking).
     *
     * @param int $revision kept for forward-compatibility only: etcd 3.5 ignores
     *                      it (measured: {}, {"revision":222} and {"revision":99}
     *                      return the same hash at the same revision, and a
     *                      non-numeric value is accepted as an unknown field).
     *                      A newer server may honour it.
     * @return array{header: array, hash: int}
     */
    public function hash(int $revision = 0): array
    {
        $body = [];
        if ($revision > 0) {
            $body['revision'] = $revision;
        }
        $response = $this->transport->send('/v3/maintenance/hash', $body);
        if (!array_key_exists('hash', $response)) {
            throw new EtcdException('hash field missing in response');
        }
        return [
            'header' => $response['header'] ?? [],
            'hash'   => (int) $response['hash'],
        ];
    }

    /**
     * Download a snapshot of the etcd database.
     *
     * The transport reassembles the streamed base64 frames, so this is a whole
     * bbolt database file — the bytes `etcdctl snapshot save` writes, with the
     * stream's trailing 32-byte sha256 of everything before it included. Write
     * it to disk as-is; `snapshot restore`/`status` verify that trailer.
     *
     * Buffering a multi-gigabyte snapshot in memory is the transport's current
     * ceiling, and it takes longer than the 5s default that point queries use.
     *
     * @param ?float $timeout per-call timeout in seconds; null allows this call 300s
     */
    public function snapshot(?float $timeout = null): string
    {
        // A database dump is arbitrarily large; default to 5 minutes rather
        // than the request timeout meant for point queries.
        return $this->transport->sendRaw('/v3/maintenance/snapshot', $timeout ?? 300.0);
    }

    /**
     * @param array<string, mixed> $entry raw wire alarm
     * @return array{memberID: int|string, alarm: int, name: string}
     */
    private static function alarmEntry(array $entry): array
    {
        $type = $entry['alarm'] ?? 0;
        if (is_string($type) && !ctype_digit($type)) {
            $name = strtoupper($type);
            $type = self::ALARM_TYPES[$name] ?? -1;
        } else {
            $type = (int) $type;
            $name = array_search($type, self::ALARM_TYPES, true) ?: 'UNKNOWN';
        }

        return [
            'memberID' => Int64::decode($entry['memberID'] ?? 0),
            'alarm'    => $type,
            'name'     => $name,
        ];
    }

    /** proto3 omits zero-valued fields; a caller writing 0 means "all". */
    private static function isZero(int|string $value): bool
    {
        return $value === 0 || $value === '0' || $value === '';
    }
}
