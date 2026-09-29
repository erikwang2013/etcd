<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Tests\Unit\Adapter;

use Erikwang2013\Etcd\EtcdClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Yii3 adapter is two config files, so it is testable without the framework:
 * yiisoft/config includes them with `$params` in scope, which is what these
 * tests reproduce. The merge plan itself is yiisoft/config's job, and the
 * declaration in composer.json is checked by testConfigPluginDeclaresBothGroups.
 */
class Yii3ConfigTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../../../src/Adapter/Yii3/config';

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        foreach (['ETCD_ENDPOINTS', 'ETCD_TIMEOUT', 'ETCD_SCHEME'] as $name) {
            $this->env[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            $value === false ? putenv($name) : putenv("$name=$value");
        }
    }

    #[Test]
    public function paramsExposeTheClientOptionsUnderThePackageKey(): void
    {
        $params = require self::CONFIG_DIR . '/params.php';

        $this->assertArrayHasKey('erikwang2013/etcd', $params);
        $this->assertSame(['127.0.0.1:2379'], $params['erikwang2013/etcd']['endpoints']);
        $this->assertSame(5.0, $params['erikwang2013/etcd']['timeout']);
    }

    #[Test]
    public function paramsFollowTheEtcdEnvironmentVariables(): void
    {
        putenv('ETCD_ENDPOINTS=10.0.0.1:2379,10.0.0.2:2379');
        putenv('ETCD_SCHEME=https');

        $params = require self::CONFIG_DIR . '/params.php';

        $this->assertSame(['10.0.0.1:2379', '10.0.0.2:2379'], $params['erikwang2013/etcd']['endpoints']);
        $this->assertSame('https', $params['erikwang2013/etcd']['scheme']);
    }

    #[Test]
    public function diBuildsTheClientFromTheParams(): void
    {
        $params = ['erikwang2013/etcd' => ['endpoints' => ['10.1.1.1:2379'], 'scheme' => 'http']];

        $definitions = require self::CONFIG_DIR . '/di.php';

        $this->assertArrayHasKey(EtcdClient::class, $definitions);
        $this->assertInstanceOf(\Closure::class, $definitions[EtcdClient::class]);

        $client = $definitions[EtcdClient::class]();
        $this->assertInstanceOf(EtcdClient::class, $client);
        $this->assertSame(['10.1.1.1:2379'], $client->config()['endpoints']);
    }

    #[Test]
    public function diSurvivesMissingParams(): void
    {
        $params = [];

        $definitions = require self::CONFIG_DIR . '/di.php';

        $this->assertSame(['127.0.0.1:2379'], $definitions[EtcdClient::class]()->config()['endpoints']);
    }

    #[Test]
    public function composerJsonDeclaresBothGroupsForTheConfigPlugin(): void
    {
        /** @var array{extra?: array} $composer */
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../../composer.json'), true);
        $extra = $composer['extra'];

        $this->assertSame('src/Adapter/Yii3/config', $extra['config-plugin-options']['source-directory']);
        $this->assertSame(['params' => 'params.php', 'di' => 'di.php'], $extra['config-plugin']);

        foreach ($extra['config-plugin'] as $file) {
            $this->assertFileExists(__DIR__ . '/../../../' . $extra['config-plugin-options']['source-directory'] . '/' . $file);
        }
    }
}
