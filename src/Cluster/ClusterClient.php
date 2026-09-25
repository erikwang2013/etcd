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

use Erikwang2013\Etcd\Support\Int64;
use Erikwang2013\Etcd\Transport\TransportInterface;

/**
 * Member ids are uint64. The gateway sends them as JSON strings and they
 * routinely exceed PHP_INT_MAX, so every id here is `int|string`: ids read out
 * of memberList() can be passed straight back to memberRemove() (casting one to
 * int silently targets a different member, and under strict_types passing the
 * string raises a TypeError).
 */
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
     * @return array{header: array, member: array, members: list<array>}
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
            'member'  => self::member($response['member'] ?? []),
            'members' => self::members($response['members'] ?? []),
        ];
    }

    /**
     * Remove a member from the cluster.
     *
     * @param int|string $id member id, as returned by memberList()
     * @return array{header: array, members: list<array>}
     */
    public function memberRemove(int|string $id): array
    {
        $response = $this->transport->send('/v3/cluster/member/remove', ['ID' => $id]);
        return [
            'header'  => $response['header'] ?? [],
            'members' => self::members($response['members'] ?? []),
        ];
    }

    /**
     * Update peer URLs for a member.
     *
     * @param int|string $id member id, as returned by memberList()
     * @return array{header: array, members: list<array>}
     */
    public function memberUpdate(int|string $id, array $peerURLs): array
    {
        $response = $this->transport->send('/v3/cluster/member/update', [
            'ID'       => $id,
            'peerURLs' => $peerURLs,
        ]);
        return [
            'header'  => $response['header'] ?? [],
            'members' => self::members($response['members'] ?? []),
        ];
    }

    /**
     * List all cluster members.
     *
     * @return array{header: array, members: list<array>}
     */
    public function memberList(): array
    {
        $response = $this->transport->send('/v3/cluster/member/list', []);
        return [
            'header'  => $response['header'] ?? [],
            'members' => self::members($response['members'] ?? []),
        ];
    }

    /**
     * Promote a learner member to voting member.
     *
     * @param int|string $id member id, as returned by memberList()
     * @return array{header: array, members: list<array>}
     */
    public function memberPromote(int|string $id): array
    {
        $response = $this->transport->send('/v3/cluster/member/promote', ['ID' => $id]);
        return [
            'header'  => $response['header'] ?? [],
            'members' => self::members($response['members'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed> $member
     * @return array<string, mixed>
     */
    private static function member(array $member): array
    {
        if (array_key_exists('ID', $member)) {
            $member['ID'] = Int64::decode($member['ID']);
        }

        return $member;
    }

    /**
     * @param  array<int, array<string, mixed>> $members
     * @return list<array<string, mixed>>
     */
    private static function members(array $members): array
    {
        return array_values(array_map([self::class, 'member'], $members));
    }
}
