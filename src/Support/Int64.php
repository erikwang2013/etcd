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
 * The one decoder for a wire int64/uint64 field.
 *
 * The gateway sends 64-bit fields as JSON *strings* (proto3 JSON), and member,
 * cluster and lease ids routinely exceed PHP_INT_MAX. An (int) cast saturates
 * them at 9223372036854775807 — measured: the status RPC's leader
 * "10276657743932975437" came back as PHP_INT_MAX, naming a member that does
 * not exist. A value that fits stays an int, so `$member['ID'] === 7` keeps
 * working; anything larger is returned as the decimal string the server sent,
 * which jsonpb accepts back on the way in.
 */
final class Int64
{
    public static function decode(mixed $wire): int|string
    {
        if (is_int($wire)) {
            return $wire;
        }

        $string = (string) $wire;
        if ($string === '') {
            return 0;
        }

        $asInt = (int) $string;

        return (string) $asInt === $string ? $asInt : $string;
    }
}
