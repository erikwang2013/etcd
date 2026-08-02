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

use Erikwang2013\Etcd\Transport\TransportInterface;

class MaintenanceClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Get the status of the connected etcd member.
     *
     * @return array ['header' => [...], 'version' => string, 'dbSize' => int, 'leader' => int, 'raftIndex' => int, 'raftTerm' => int, 'raftAppliedIndex' => int, 'errors' => list<string>]
     */
    public function status(): array
    {
        $response = $this->transport->send('/v3/maintenance/status', []);
        return [
            'header'           => $response['header'] ?? [],
            'version'          => $response['version'] ?? '',
            'dbSize'           => (int) ($response['dbSize'] ?? 0),
            'leader'           => (int) ($response['leader'] ?? 0),
            'raftIndex'        => (int) ($response['raftIndex'] ?? 0),
            'raftTerm'         => (int) ($response['raftTerm'] ?? 0),
            'raftAppliedIndex' => (int) ($response['raftAppliedIndex'] ?? 0),
            'errors'           => $response['errors'] ?? [],
        ];
    }

    /**
     * Manage etcd alarms.
     *
     * @param int $action   0=get, 1=activate, 2=deactivate
     * @param int $alarm    0=none, 1=nospace, 2=corrupt
     * @param int $memberID Member ID (0 for all)
     * @return array ['header' => [...], 'alarms' => [['memberID' => int, 'alarm' => int], ...]]
     */
    public function alarm(int $action = 0, int $alarm = 0, int $memberID = 0): array
    {
        $body = ['action' => $action];
        if ($memberID > 0) {
            $body['memberID'] = $memberID;
        }
        if ($alarm > 0) {
            $body['alarm'] = $alarm;
        }
        $response = $this->transport->send('/v3/maintenance/alarm', $body);
        return [
            'header' => $response['header'] ?? [],
            'alarms' => $response['alarms'] ?? [],
        ];
    }

    /**
     * Defragment the etcd database to reclaim storage.
     */
    public function defragment(): array
    {
        $response = $this->transport->send('/v3/maintenance/defragment', []);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Get the hash of the KV store (for integrity checking).
     *
     * @return array ['header' => [...], 'hash' => int]
     */
    public function hash(int $revision = 0): array
    {
        $body = [];
        if ($revision > 0) {
            $body['revision'] = $revision;
        }
        $response = $this->transport->send('/v3/maintenance/hash', $body);
        return [
            'header' => $response['header'] ?? [],
            'hash'   => (int) ($response['hash'] ?? 0),
        ];
    }

    /**
     * Take a snapshot of the etcd database. Returns raw binary data.
     * Caller should write to disk.
     */
    public function snapshot(): string
    {
        return $this->transport->sendRaw('/v3/maintenance/snapshot');
    }
}
