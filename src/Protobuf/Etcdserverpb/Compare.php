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

class Compare
{
    private $result = 0;
    private $target = 0;
    private $key = '';
    private $range_end = '';
    private $version = 0;
    private $create_revision = 0;
    private $mod_revision = 0;
    private $value = '';
    private $lease = 0;
    public function getResult(): int { return $this->result; }
    public function setResult(int $var): void { $this->result = $var; }
    public function getTarget(): int { return $this->target; }
    public function setTarget(int $var): void { $this->target = $var; }
    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getRangeEnd(): string { return $this->range_end; }
    public function setRangeEnd(string $var): void { $this->range_end = $var; }
    public function getVersion(): int { return $this->version; }
    public function setVersion(int $var): void { $this->version = $var; }
    public function getCreateRevision(): int { return $this->create_revision; }
    public function setCreateRevision(int $var): void { $this->create_revision = $var; }
    public function getModRevision(): int { return $this->mod_revision; }
    public function setModRevision(int $var): void { $this->mod_revision = $var; }
    public function getValue(): string { return $this->value; }
    public function setValue(string $var): void { $this->value = $var; }
    public function getLease(): int { return $this->lease; }
    public function setLease(int $var): void { $this->lease = $var; }
}
