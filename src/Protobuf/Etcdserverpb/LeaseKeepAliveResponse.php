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
class LeaseKeepAliveResponse
{
    private $header;
    private $ID = 0;
    private $TTL = 0;
    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
    public function getTTL(): int { return $this->TTL; }
    public function setTTL(int $var): void { $this->TTL = $var; }
}
