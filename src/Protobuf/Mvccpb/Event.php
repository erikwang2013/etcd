<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Protobuf\Mvccpb;

class Event
{
    const PUT = 0;
    const DELETE = 1;

    private $type;
    private $kv;
    private $prev_kv;

    public function __construct()
    {
        $this->type = 0;
        $this->kv = null;
        $this->prev_kv = null;}

    public function getType(): int { return $this->type; }
    public function setType(int $var): void { $this->type = $var; }
    public function getKv(): ?KeyValue { return $this->kv; }
    public function setKv(KeyValue $var): void { $this->kv = $var; }
    public function getPrevKv(): ?KeyValue { return $this->prev_kv; }
    public function setPrevKv(KeyValue $var): void { $this->prev_kv = $var; }
}
