<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Kv;

use Erikwang2013\Etcd\Transport\TransportInterface;
use Erikwang2013\Etcd\Support\KeyValue;
use Erikwang2013\Etcd\EtcdClient;

class KvClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Put a key-value pair.
     *
     * @param array $options  Optional: 'lease' => int, 'prevKv' => bool, 'ignoreValue' => bool, 'ignoreLease' => bool
     * @return array  ['header' => [...], 'prev_kv' => [...]|null]
     */
    public function put(string $key, string $value, array $options = []): array
    {
        $body = [
            'key'   => base64_encode($key),
            'value' => base64_encode($value),
        ];
        if (isset($options['lease'])) {
            $body['lease'] = $options['lease'];
        }
        if (!empty($options['prevKv'])) {
            $body['prev_kv'] = true;
        }
        if (!empty($options['ignoreValue'])) {
            $body['ignore_value'] = true;
        }
        if (!empty($options['ignoreLease'])) {
            $body['ignore_lease'] = true;
        }
        $response = $this->transport->send('/v3/kv/put', $body);
        return $this->decodePutResponse($response);
    }

    /**
     * Get a key or range of keys.
     *
     * @param array $options
     *   - 'rangeEnd' => string    Range end for prefix scan
     *   - 'limit'    => int       Max results
     *   - 'revision' => int       Snapshot revision
     *   - 'sortOrder' => 'ascend'|'descend'|'none'
     *   - 'sortTarget' => 'key'|'version'|'create'|'mod'|'value'
     *   - 'serializable' => bool  Serializable read (no consensus, faster)
     *   - 'keysOnly'   => bool    Only return keys (no values)
     *   - 'countOnly'  => bool    Only return count
     *   - 'minModRevision'    => int  Only KVs modified at or after this revision
     *   - 'maxModRevision'    => int  Only KVs modified at or before this revision
     *   - 'minCreateRevision' => int  Only KVs created at or after this revision
     *   - 'maxCreateRevision' => int  Only KVs created at or before this revision
     * @return array  ['header' => [...], 'kvs' => [...], 'count' => int, 'more' => bool]
     */
    public function get(string $key, array $options = []): array
    {
        $response = $this->transport->send('/v3/kv/range', $this->rangeBody($key, $options));
        return $this->decodeRangeResponse($response);
    }

    /**
     * Get all keys with a given prefix.
     *
     * @return array ['header' => [...], 'kvs' => [...], 'count' => int, 'more' => bool]
     */
    public function getByPrefix(string $prefix, array $options = []): array
    {
        $options['rangeEnd'] = EtcdClient::prefixToRangeEnd($prefix);
        return $this->get($prefix, $options);
    }

    /**
     * Get a single key or throw KeyNotFoundException if not found.
     *
     * @throws \Erikwang2013\Etcd\Exception\KeyNotFoundException
     * @return array  Decoded KV: ['key' => ..., 'value' => ..., 'create_revision' => ..., 'mod_revision' => ..., 'version' => ..., 'lease' => ...]
     */
    public function getOrFail(string $key, array $options = []): array
    {
        // Only kvs[0] is ever returned, so asking for more is pure waste: on a
        // prefix this is one key read instead of the whole range.
        $options['limit'] = 1;

        $result = $this->get($key, $options);
        if (empty($result['kvs'])) {
            throw new \Erikwang2013\Etcd\Exception\KeyNotFoundException("Key not found: {$key}");
        }
        return $result['kvs'][0];
    }

    /**
     * Delete a key or range of keys.
     *
     * @param array $options Optional: 'rangeEnd' => string, 'prevKv' => bool
     * @return array  ['header' => [...], 'deleted' => int, 'prev_kvs' => [...]]
     */
    public function delete(string $key, array $options = []): array
    {
        $body = ['key' => base64_encode($key)];

        if (!empty($options['rangeEnd'])) {
            $body['range_end'] = base64_encode($options['rangeEnd']);
        }
        if (!empty($options['prevKv'])) {
            $body['prev_kv'] = true;
        }

        $response = $this->transport->send('/v3/kv/deleterange', $body);
        return $this->decodeDeleteResponse($response);
    }

    /**
     * Delete all keys with a given prefix.
     */
    public function deleteByPrefix(string $prefix, array $options = []): array
    {
        $options['rangeEnd'] = EtcdClient::prefixToRangeEnd($prefix);
        return $this->delete($prefix, $options);
    }

    /**
     * Execute a transaction.
     *
     * @param array $compare   List of Compare arrays: [['result' => 0, 'target' => 0, 'key' => '', 'version' => 0], ...]
     * @param array $success   List of RequestOp. An op is one of
     *                         ['request_put' => ['key', 'value', 'lease', 'prevKv', 'ignoreValue', 'ignoreLease']],
     *                         ['request_range' => ['key', 'rangeEnd'|'range_end', ...get() options]],
     *                         ['request_delete_range' => ['key', 'rangeEnd'|'range_end', 'prevKv']],
     *                         ['request_txn' => ['compare' => [...], 'success' => [...], 'failure' => [...]]]
     *                         — the last one nests another txn in this branch.
     * @param array $failure   List of RequestOp (fallback if compare fails)
     * @return array  ['header' => [...], 'succeeded' => bool, 'responses' => [...]]
     */
    public function txn(array $compare, array $success, array $failure = []): array
    {
        $response = $this->transport->send('/v3/kv/txn', $this->encodeTxn([
            'compare' => $compare,
            'success' => $success,
            'failure' => $failure,
        ]));
        return $this->decodeTxnResponse($response);
    }

    /**
     * Compact the event history up to the given revision.
     * All revisions <= $revision are discarded.
     */
    public function compact(int $revision, bool $physical = false): array
    {
        $response = $this->transport->send('/v3/kv/compaction', [
            'revision' => $revision,
            'physical' => $physical,
        ]);
        return ['header' => $response['header'] ?? []];
    }

    // --- Internal helpers ---

    /**
     * The one place that turns range options into a RangeRequest body, shared by
     * get() and by a txn 'request_range' op so neither path can silently drop
     * options the other accepts.
     *
     * @param array $o Options as get() takes them. A txn op may also spell the
     *                 range end as 'range_end', which is its own wire field name.
     * @return array RangeRequest body, keys and values base64-encoded
     */
    private function rangeBody(string $key, array $o): array
    {
        $body = ['key' => base64_encode($key)];

        $rangeEnd = $this->rangeEndOf($o);
        if ($rangeEnd !== '') {
            $body['range_end'] = base64_encode($rangeEnd);
        }
        foreach (['limit', 'revision'] as $field) {
            if (isset($o[$field])) {
                $body[$field] = (int) $o[$field];
            }
        }
        foreach ([
            'minModRevision'    => 'min_mod_revision',
            'maxModRevision'    => 'max_mod_revision',
            'minCreateRevision' => 'min_create_revision',
            'maxCreateRevision' => 'max_create_revision',
        ] as $option => $field) {
            if (isset($o[$option])) {
                $body[$field] = (int) $o[$option];
            }
        }
        foreach ([
            'sortOrder'  => ['sort_order', ['none' => 0, 'ascend' => 1, 'descend' => 2]],
            'sortTarget' => ['sort_target', ['key' => 0, 'version' => 1, 'create' => 2, 'mod' => 3, 'value' => 4]],
        ] as $option => [$field, $allowed]) {
            if (empty($o[$option])) {
                continue;
            }
            if (!isset($allowed[$o[$option]])) {
                throw new \InvalidArgumentException("Invalid {$option}: {$o[$option]}");
            }
            $body[$field] = $allowed[$o[$option]];
        }
        if (!empty($o['serializable'])) {
            $body['serializable'] = true;
        }
        foreach (['keysOnly' => 'keys_only', 'countOnly' => 'count_only'] as $option => $field) {
            if (!empty($o[$option])) {
                $body[$field] = true;
            }
        }

        return $body;
    }

    private function rangeEndOf(array $o): string
    {
        return (string) ($o['rangeEnd'] ?? $o['range_end'] ?? '');
    }

    private function decodeRangeResponse(array $r): array
    {
        $kvs = [];
        foreach ($r['kvs'] ?? [] as $kv) {
            $kvs[] = $this->decodeKv($kv);
        }
        return [
            'header' => $r['header'] ?? [],
            'kvs'    => $kvs,
            'count'  => (int) ($r['count'] ?? count($kvs)),
            'more'   => !empty($r['more']),
        ];
    }

    private function decodePutResponse(array $r): array
    {
        $prevKv = null;
        if (isset($r['prev_kv'])) {
            $prevKv = $this->decodeKv($r['prev_kv']);
        }
        return [
            'header'  => $r['header'] ?? [],
            'prev_kv' => $prevKv,
        ];
    }

    private function decodeDeleteResponse(array $r): array
    {
        $prevKvs = [];
        foreach ($r['prev_kvs'] ?? [] as $kv) {
            $prevKvs[] = $this->decodeKv($kv);
        }
        return [
            'header'   => $r['header'] ?? [],
            'deleted'  => (int) ($r['deleted'] ?? 0),
            'prev_kvs' => $prevKvs,
        ];
    }

    private function decodeTxnResponse(array $r): array
    {
        $responses = [];
        foreach ($r['responses'] ?? [] as $op) {
            if (isset($op['response_put'])) {
                $responses[] = ['type' => 'put', 'response' => $this->decodePutResponse($op['response_put'])];
            } elseif (isset($op['response_range'])) {
                $responses[] = ['type' => 'range', 'response' => $this->decodeRangeResponse($op['response_range'])];
            } elseif (isset($op['response_delete_range'])) {
                $responses[] = ['type' => 'delete', 'response' => $this->decodeDeleteResponse($op['response_delete_range'])];
            } elseif (isset($op['response_txn'])) {
                $responses[] = ['type' => 'txn', 'response' => $this->decodeTxnResponse($op['response_txn'])];
            }
        }
        return [
            'header'    => $r['header'] ?? [],
            'succeeded' => !empty($r['succeeded']),
            'responses' => $responses,
        ];
    }

    private function decodeKv(array $kv): array
    {
        // shared with the watch path so both return the same types
        return KeyValue::decode($kv);
    }

    private function encodeComparisons(array $compares): array
    {
        return array_map(function ($c) {
            $encoded = [
                'result' => $c['result'] ?? 0,
                'target' => $c['target'] ?? 0,
                'key'    => base64_encode($c['key'] ?? ''),
            ];
            if (isset($c['range_end'])) {
                $encoded['range_end'] = base64_encode($c['range_end']);
            }
            switch ($c['target'] ?? 0) {
                case 0: $encoded['version'] = $c['version'] ?? 0; break;
                case 1: $encoded['create_revision'] = $c['create_revision'] ?? 0; break;
                case 2: $encoded['mod_revision'] = $c['mod_revision'] ?? 0; break;
                case 3: $encoded['value'] = base64_encode($c['value'] ?? ''); break;
                case 4: $encoded['lease'] = $c['lease'] ?? 0; break;
            }
            return $encoded;
        }, $compares);
    }

    private function encodeTxn(array $txn): array
    {
        return [
            'compare' => $this->encodeComparisons($txn['compare'] ?? []),
            'success' => $this->encodeRequestOps($txn['success'] ?? []),
            'failure' => $this->encodeRequestOps($txn['failure'] ?? []),
        ];
    }

    private function encodeRequestOps(array $ops): array
    {
        return array_map(function ($op) {
            if (isset($op['request_put'])) {
                $put = $op['request_put'];
                $r = [
                    'key'   => base64_encode($put['key'] ?? ''),
                    'value' => base64_encode($put['value'] ?? ''),
                ];
                if (isset($put['lease'])) {
                    $r['lease'] = $put['lease'];
                }
                foreach (['prevKv' => 'prev_kv', 'ignoreValue' => 'ignore_value', 'ignoreLease' => 'ignore_lease'] as $option => $field) {
                    if (!empty($put[$option])) {
                        $r[$field] = true;
                    }
                }
                return ['request_put' => $r];
            }
            if (isset($op['request_range'])) {
                $range = $op['request_range'];
                return ['request_range' => $this->rangeBody((string) ($range['key'] ?? ''), $range)];
            }
            if (isset($op['request_delete_range'])) {
                $delete = $op['request_delete_range'];
                $r = ['key' => base64_encode($delete['key'] ?? '')];
                $rangeEnd = $this->rangeEndOf($delete);
                if ($rangeEnd !== '') {
                    $r['range_end'] = base64_encode($rangeEnd);
                }
                if (!empty($delete['prevKv'])) {
                    $r['prev_kv'] = true;
                }
                return ['request_delete_range' => $r];
            }
            if (isset($op['request_txn'])) {
                return ['request_txn' => $this->encodeTxn($op['request_txn'])];
            }
            return $op;
        }, $ops);
    }
}
