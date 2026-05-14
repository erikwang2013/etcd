<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Cluster;

use Erikwang2013\Etcd\Transport\TransportInterface;

class ClusterClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Add a member to the cluster.
     *
     * @return array ['header' => [...], 'member' => [...], 'members' => [...]]
     */
    public function memberAdd(array $peerURLs, bool $isLearner = false): array
    {
        $body = ['peerURLs' => $peerURLs];
        if ($isLearner) {
            $body['isLearner'] = true;
        }
        $response = $this->transport->send('/v3/cluster/member/add', $body);
        return [
            'header'  => $response['header'] ?? [],
            'member'  => $response['member'] ?? [],
            'members' => $response['members'] ?? [],
        ];
    }

    /**
     * Remove a member from the cluster.
     *
     * @return array ['header' => [...], 'members' => [...]]
     */
    public function memberRemove(int $id): array
    {
        $response = $this->transport->send('/v3/cluster/member/remove', ['ID' => $id]);
        return [
            'header'  => $response['header'] ?? [],
            'members' => $response['members'] ?? [],
        ];
    }

    /**
     * Update peer URLs for a member.
     *
     * @return array ['header' => [...], 'members' => [...]]
     */
    public function memberUpdate(int $id, array $peerURLs): array
    {
        $response = $this->transport->send('/v3/cluster/member/update', [
            'ID'       => $id,
            'peerURLs' => $peerURLs,
        ]);
        return [
            'header'  => $response['header'] ?? [],
            'members' => $response['members'] ?? [],
        ];
    }

    /**
     * List all cluster members.
     *
     * @return array ['header' => [...], 'members' => [...]]
     */
    public function memberList(): array
    {
        $response = $this->transport->send('/v3/cluster/member/list', []);
        return [
            'header'  => $response['header'] ?? [],
            'members' => $response['members'] ?? [],
        ];
    }

    /**
     * Promote a learner member to voting member.
     *
     * @return array ['header' => [...], 'members' => [...]]
     */
    public function memberPromote(int $id): array
    {
        $response = $this->transport->send('/v3/cluster/member/promote', ['ID' => $id]);
        return [
            'header'  => $response['header'] ?? [],
            'members' => $response['members'] ?? [],
        ];
    }
}
