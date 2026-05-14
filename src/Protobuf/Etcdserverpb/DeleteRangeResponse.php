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
use Erikwang2013\Etcd\Protobuf\Mvccpb\KeyValue;
class DeleteRangeResponse
{
    private $header;
    private $deleted = 0;
    private $prev_kvs;
    public function __construct()
    {
        $this->prev_kvs = [];}
    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getDeleted(): int { return $this->deleted; }
    public function setDeleted(int $var): void { $this->deleted = $var; }
    /** @return KeyValue[] */
    public function getPrevKvs(): array { return $this->prev_kvs; }
}
