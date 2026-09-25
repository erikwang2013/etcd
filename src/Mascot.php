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
 * 项目宠物 Etchy —— 蓝色小象（elePHPant 顶着三节点 Raft 集群）。
 *
 * 图形本体是 docs/pet.svg，随包发布；这里只提供取值入口，别处不必再拷一份。
 *
 * 用法：
 *   echo Mascot::svg();                       // 直接内联到 HTML
 *   <img src="<?= Mascot::dataUri() ?>">      // 或走 data URI，不依赖 web 目录
 *   copy(Mascot::path(), $publicDir);         // 落到自己的静态目录
 */
class Mascot
{
    /**
     * SVG 文件的绝对路径。
     */
    public static function path(): string
    {
        return dirname(__DIR__) . '/docs/pet.svg';
    }

    /**
     * SVG 源码。
     *
     * @throws \RuntimeException 文件缺失（安装不完整或被构建工具剔除）
     */
    public static function svg(): string
    {
        $path = self::path();
        $svg = @file_get_contents($path);
        if ($svg === false) {
            throw new \RuntimeException("Mascot asset is missing: {$path}");
        }

        return $svg;
    }

    /**
     * 可直接塞进 <img src="..."> 的 data URI。
     */
    public static function dataUri(): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg());
    }
}
