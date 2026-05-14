<?php
declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class DeleteRangeRequest
{
    private $key = '';
    private $range_end = '';
    private $prev_kv = false;
    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getRangeEnd(): string { return $this->range_end; }
    public function setRangeEnd(string $var): void { $this->range_end = $var; }
    public function getPrevKv(): bool { return $this->prev_kv; }
    public function setPrevKv(bool $var): void { $this->prev_kv = $var; }
}
