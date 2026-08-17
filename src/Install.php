<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd;

/**
 * Webman plugin hook. Discovered by webman's post-autoload-dump scan
 * (any package containing src/Install.php with a WEBMAN_PLUGIN constant).
 * Config lands at config/plugin/erikwang2013/etcd/etcd.php and is read
 * via config('plugin.erikwang2013.etcd.etcd').
 */
class Install
{
    const WEBMAN_PLUGIN = 'erikwang2013/etcd';

    public static function install(): void
    {
        $configDir = config_path() . '/plugin/erikwang2013/etcd';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        $configFile = $configDir . '/etcd.php';
        if (!file_exists($configFile)) {
            copy(__DIR__ . '/../config/etcd.php', $configFile);
        }
        echo "erikwang2013/etcd plugin installed. Config at config/plugin/erikwang2013/etcd/etcd.php\n";
    }

    public static function uninstall(): void
    {
        echo "erikwang2013/etcd plugin uninstalled.\n";
    }
}
