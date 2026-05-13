<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Kv;

use Erikwang2013\Etcd\Transport\TransportInterface;

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
            'key'   => \base64_encode($key),
            'value' => \base64_encode($value),
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
     * @return array  ['header' => [...], 'kvs' => [...], 'count' => int, 'more' => bool]
     */
    public function get(string $key, array $options = []): array
    {
        $body = ['key' => \base64_encode($key)];

        if (!empty($options['rangeEnd'])) {
            $body['range_end'] = \base64_encode($options['rangeEnd']);
        }
        if (isset($options['limit'])) {
            $body['limit'] = (int) $options['limit'];
        }
        if (isset($options['revision'])) {
            $body['revision'] = (int) $options['revision'];
        }
        if (!empty($options['sortOrder'])) {
            $orderMap = ['none' => 0, 'ascend' => 1, 'descend' => 2];
            $body['sort_order'] = $orderMap[$options['sortOrder']] ?? 0;
        }
        if (!empty($options['sortTarget'])) {
            $targetMap = ['key' => 0, 'version' => 1, 'create' => 2, 'mod' => 3, 'value' => 4];
            $body['sort_target'] = $targetMap[$options['sortTarget']] ?? 0;
        }
        if (!empty($options['serializable'])) {
            $body['serializable'] = true;
        }
        if (!empty($options['keysOnly'])) {
            $body['keys_only'] = true;
        }
        if (!empty($options['countOnly'])) {
            $body['count_only'] = true;
        }

        $response = $this->transport->send('/v3/kv/range', $body);
        return $this->decodeRangeResponse($response);
    }

    /**
     * Get all keys with a given prefix.
     *
     * @return array ['header' => [...], 'kvs' => [...], 'count' => int, 'more' => bool]
     */
    public function getByPrefix(string $prefix, array $options = []): array
    {
        $rangeEnd = $this->prefixToRangeEnd($prefix);
        $options['rangeEnd'] = $rangeEnd;
        return $this->get($prefix, $options);
    }

    /**
     * Delete a key or range of keys.
     *
     * @param array $options Optional: 'rangeEnd' => string, 'prevKv' => bool
     * @return array  ['header' => [...], 'deleted' => int, 'prev_kvs' => [...]]
     */
    public function delete(string $key, array $options = []): array
    {
        $body = ['key' => \base64_encode($key)];

        if (!empty($options['rangeEnd'])) {
            $body['range_end'] = \base64_encode($options['rangeEnd']);
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
        $options['rangeEnd'] = $this->prefixToRangeEnd($prefix);
        return $this->delete($prefix, $options);
    }

    /**
     * Execute a transaction.
     *
     * @param array $compare   List of Compare arrays: [['result' => 0, 'target' => 0, 'key' => '', 'version' => 0], ...]
     * @param array $success   List of RequestOp: [['request_put' => ['key' => ..., 'value' => ...]], ...]
     * @param array $failure   List of RequestOp (fallback if compare fails)
     * @return array  ['header' => [...], 'succeeded' => bool, 'responses' => [...]]
     */
    public function txn(array $compare, array $success, array $failure = []): array
    {
        $body = [
            'compare' => $this->encodeComparisons($compare),
            'success' => $this->encodeRequestOps($success),
            'failure' => $this->encodeRequestOps($failure),
        ];

        $response = $this->transport->send('/v3/kv/txn', $body);
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

    private function prefixToRangeEnd(string $prefix): string
    {
        if ($prefix === '') {
            return "\x00";
        }
        $bytes = $prefix;
        $len = \strlen($bytes);
        for ($i = $len - 1; $i >= 0; $i--) {
            $c = \ord($bytes[$i]);
            if ($c < 0xFF) {
                return \substr($bytes, 0, $i) . \chr($c + 1);
            }
        }
        return '';
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
            'count'  => (int) ($r['count'] ?? \count($kvs)),
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
        return [
            'key'              => \base64_decode($kv['key'] ?? '', true) ?: ($kv['key'] ?? ''),
            'value'            => \array_key_exists('value', $kv) ? (\base64_decode($kv['value'], true) ?: $kv['value']) : null,
            'create_revision'  => (int) ($kv['create_revision'] ?? 0),
            'mod_revision'     => (int) ($kv['mod_revision'] ?? 0),
            'version'          => (int) ($kv['version'] ?? 0),
            'lease'            => (int) ($kv['lease'] ?? 0),
        ];
    }

    private function encodeComparisons(array $compares): array
    {
        return \array_map(function ($c) {
            $encoded = [
                'result' => $c['result'] ?? 0,
                'target' => $c['target'] ?? 0,
                'key'    => \base64_encode($c['key'] ?? ''),
            ];
            if (isset($c['range_end'])) {
                $encoded['range_end'] = \base64_encode($c['range_end']);
            }
            switch ($c['target'] ?? 0) {
                case 0: $encoded['version'] = $c['version'] ?? 0; break;
                case 1: $encoded['create_revision'] = $c['create_revision'] ?? 0; break;
                case 2: $encoded['mod_revision'] = $c['mod_revision'] ?? 0; break;
                case 3: $encoded['value'] = \base64_encode($c['value'] ?? ''); break;
                case 4: $encoded['lease'] = $c['lease'] ?? 0; break;
            }
            return $encoded;
        }, $compares);
    }

    private function encodeRequestOps(array $ops): array
    {
        return \array_map(function ($op) {
            if (isset($op['request_put'])) {
                $r = [
                    'key'   => \base64_encode($op['request_put']['key'] ?? ''),
                    'value' => \base64_encode($op['request_put']['value'] ?? ''),
                ];
                if (isset($op['request_put']['lease'])) {
                    $r['lease'] = $op['request_put']['lease'];
                }
                return ['request_put' => $r];
            }
            if (isset($op['request_range'])) {
                $r = ['key' => \base64_encode($op['request_range']['key'] ?? '')];
                if (isset($op['request_range']['range_end'])) {
                    $r['range_end'] = \base64_encode($op['request_range']['range_end']);
                }
                return ['request_range' => $r];
            }
            if (isset($op['request_delete_range'])) {
                $r = ['key' => \base64_encode($op['request_delete_range']['key'] ?? '')];
                if (isset($op['request_delete_range']['range_end'])) {
                    $r['range_end'] = \base64_encode($op['request_delete_range']['range_end']);
                }
                return ['request_delete_range' => $r];
            }
            return $op;
        }, $ops);
    }
}
