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
class PutRequest
{
    private $key = '';
    private $value = '';
    private $lease = 0;
    private $prev_kv = false;
    private $ignore_value = false;
    private $ignore_lease = false;
    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getValue(): string { return $this->value; }
    public function setValue(string $var): void { $this->value = $var; }
    public function getLease(): int { return $this->lease; }
    public function setLease(int $var): void { $this->lease = $var; }
    public function getPrevKv(): bool { return $this->prev_kv; }
    public function setPrevKv(bool $var): void { $this->prev_kv = $var; }
    public function getIgnoreValue(): bool { return $this->ignore_value; }
    public function setIgnoreValue(bool $var): void { $this->ignore_value = $var; }
    public function getIgnoreLease(): bool { return $this->ignore_lease; }
    public function setIgnoreLease(bool $var): void { $this->ignore_lease = $var; }
}
