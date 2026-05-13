<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Adapter\Webman;

class Plugin
{
    /**
     * Install — called once when composer require is run.
     */
    public static function install(): void
    {
        $configDir = \config_path() . '/plugin/erikwang2013/etcd';
        if (!\is_dir($configDir)) {
            \mkdir($configDir, 0755, true);
        }
        $configFile = $configDir . '/etcd.php';
        if (!\file_exists($configFile)) {
            \copy(
                __DIR__ . '/../../../../config/etcd.php',
                $configFile
            );
        }
        echo "erikwang2013/etcd plugin installed. Config at plugin/erikwang2013/etcd/etcd.php\n";
    }

    /**
     * Uninstall.
     */
    public static function uninstall(): void
    {
        echo "erikwang2013/etcd plugin uninstalled.\n";
    }
}
