<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

return [
    'endpoints' => explode(',', getenv('ETCD_ENDPOINTS') ?: '127.0.0.1:2379'),
    'transport' => getenv('ETCD_TRANSPORT') ?: 'auto',
    'timeout'   => (float) (getenv('ETCD_TIMEOUT') ?: 5.0),
    'retry'     => (int) (getenv('ETCD_RETRY') ?: 2),
    'auth'      => [
        'user'     => getenv('ETCD_USER') ?: '',
        'password' => getenv('ETCD_PASSWORD') ?: '',
    ],
];
