<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

/*
 * Yii3 params, merged into the `params` group by yiisoft/config.
 * Override the whole `erikwang2013/etcd` key in the application's
 * config/common/params.php. Defaults are the packaged config/etcd.php,
 * so the ETCD_* environment variables keep working.
 */

return [
    'erikwang2013/etcd' => require __DIR__ . '/../../../../config/etcd.php',
];
