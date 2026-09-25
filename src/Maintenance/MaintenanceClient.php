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
        // `revision` is deliberately NOT sent. HashRequest does not declare it
        // (it belongs to HashKVRequest), and etcd 3.5 ignores it anyway — {}
        // and {"revision":222} return the identical hash. Over the gRPC
        // transport an undeclared field is a hard error rather than a silent
        // no-op, so sending it would make one call behave differently per
        // transport. The parameter is kept for signature compatibility.
        $body = [];
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
     * Download a snapshot of the etcd database into a string.
     *
     * The transport reassembles the streamed base64 frames, so this is a whole
     * bbolt database file — the bytes `etcdctl snapshot save` writes, with the
     * stream's trailing 32-byte sha256 of everything before it included. Write
     * it to disk as-is; `etcdutl snapshot restore` verifies that trailer (and
     * refuses a file without one, unless --skip-hash-check is passed).
     *
     * The whole database is held in memory here, so on a multi-gigabyte one this
     * exhausts the process memory limit: use snapshotTo() instead, which streams
     * the same bytes to a file in constant memory.
     *
     * @param ?float $timeout per-call timeout in seconds; null allows this call 300s
     */
    public function snapshot(?float $timeout = null): string
    {
        // A database dump is arbitrarily large; default to 5 minutes rather
        // than the request timeout meant for point queries.
        $bytes = $this->transport->sendRaw('/v3/maintenance/snapshot', $timeout ?? 300.0);

        // The payload ends with the sha256 of everything before it. A body cut
        // short still looks like a database (bbolt header and all) and
        // `etcdutl snapshot status` will happily describe it — only restore
        // checks this trailer, so check it here too. snapshotTo() enforces the
        // same rule when writing to disk.
        self::assertHashTrailer($bytes);

        return $bytes;
    }

    /**
     * @throws EtcdException when the trailing 32 bytes are not the sha256 of the payload
     */
    private static function assertHashTrailer(string $bytes): void
    {
        if (strlen($bytes) <= 32) {
            throw new EtcdException('Snapshot payload is too short to contain a hash trailer.');
        }
        $payload = substr($bytes, 0, -32);
        $trailer = substr($bytes, -32);
        if (!hash_equals(hash('sha256', $payload, true), $trailer)) {
            throw new EtcdException('Snapshot hash trailer does not match: the payload is truncated or corrupt.');
        }
    }

    /**
     * Save a snapshot of the etcd database to $path, in constant memory.
     *
     * The blobs are written to a temporary file as they arrive, and the file is
     * only renamed onto $path once the stream ended with a matching sha256
     * trailer. So $path is never a partial database: on any failure — a timeout,
     * a dropped connection, a full disk, a stream cut at a frame boundary — this
     * throws and $path keeps whatever it held before, and no half-written
     * snapshot is left where a backup would be picked up from. A caller needs no
     * truncation check of its own: this enforces the same sha256 the official
     * `etcdutl snapshot restore` does. (`etcdutl snapshot status` only reports —
     * measured on 3.5.17, it describes a truncated file without complaint.)
     *
     * $path is a path rather than a file handle on purpose: a handle cannot be
     * renamed, so the atomicity above would be the caller's problem again. To
     * stream into a pipe, socket or object store instead, the transport's
     * sendStream() takes the callback directly:
     *
     *     $client->transport()->sendStream('/v3/maintenance/snapshot', $onBlob);
     *
     * @param string $path   destination file; its directory must exist and be
     *                       writable, and any existing file is replaced — a
     *                       symlink is replaced by the file itself, not followed
     * @param ?float $timeout per-call timeout in seconds; null allows this call
     *                       300s, since a database dump outlives the 5s default
     *                       meant for point queries. A snapshot that does not
     *                       finish in time is cut off, not truncated silently.
     * @return int bytes written — the size of the file, hash trailer included,
     *             so a caller can compare it against status()['dbSize']
     * @throws EtcdException when the destination is unusable, the write fails,
     *                       or the stream ends without a valid hash trailer
     */
    public function snapshotTo(string $path, ?float $timeout = null): int
    {
        $dir = \dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new EtcdException("Snapshot destination directory is missing or not writable: {$dir}");
        }
        $temp = @tempnam($dir, '.' . basename($path) . '.');
        if ($temp === false) {
            throw new EtcdException("Cannot create a temporary file in {$dir}");
        }
        $handle = fopen($temp, 'wb');
        if ($handle === false) {
            @unlink($temp);
            throw new EtcdException("Cannot open {$temp} for writing");
        }
        // tempnam() creates it 0600; a backup usually wants the umask default.
        @chmod($temp, 0666 & ~umask());

        $hash = hash_init('sha256');
        $tail = '';      // the stream's trailer, held back from the digest
        $bytes = 0;
        try {
            $this->transport->sendStream('/v3/maintenance/snapshot', function (string $blob) use ($handle, $hash, &$tail, &$bytes, $path): void {
                $tail .= $blob;
                if (strlen($tail) > 32) {
                    hash_update($hash, substr($tail, 0, -32));
                    $tail = substr($tail, -32);
                }
                if (fwrite($handle, $blob) !== strlen($blob)) {
                    throw new EtcdException("Snapshot write failed, out of space? {$path}");
                }
                $bytes += strlen($blob);
            }, $timeout ?? 300.0);

            // The stream ends with the sha256 of everything before it. Nothing
            // else in a bbolt dump is self-describing, so this is what tells a
            // complete snapshot from one the transport stopped short of.
            if (!hash_equals($tail, hash_final($hash, true))) {
                throw new EtcdException("Snapshot stream ended without a valid hash trailer; {$path} not written");
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($temp);
            throw $e;
        }
        fclose($handle);

        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new EtcdException("Cannot move the snapshot onto {$path}");
        }

        return $bytes;
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
