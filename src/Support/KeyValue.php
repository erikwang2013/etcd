<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Support;

/**
 * The one decoder for a wire KeyValue.
 *
 * etcd's gateway sends base64 bytes, int64/uint64 fields as JSON *strings*, and
 * omits zero values entirely (proto3). Every path that hands a KeyValue to a
 * caller — KV reads, transactions, watch events, lease-bound keys — must produce
 * the same shape, or `$kv['version'] === 2` is true from get() and false from
 * watch().
 */
final class KeyValue
{
    /**
     * @param array<string, mixed> $kv raw wire KeyValue
     * @return array{key: string, value: ?string, create_revision: int, mod_revision: int, version: int, lease: int}
     */
    public static function decode(array $kv): array
    {
        $key = (string) ($kv['key'] ?? '');
        $decodedKey = base64_decode($key, true);

        $value = null;
        if (array_key_exists('value', $kv) && $kv['value'] !== null) {
            $decodedValue = base64_decode((string) $kv['value'], true);
            $value = $decodedValue !== false ? $decodedValue : (string) $kv['value'];
        }

        return [
            'key'             => $decodedKey !== false ? $decodedKey : $key,
            'value'           => $value,
            'create_revision' => (int) ($kv['create_revision'] ?? 0),
            'mod_revision'    => (int) ($kv['mod_revision'] ?? 0),
            'version'         => (int) ($kv['version'] ?? 0),
            'lease'           => (int) ($kv['lease'] ?? 0),
        ];
    }
}
