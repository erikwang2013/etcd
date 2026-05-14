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
class PutResponse
{
    private $header;
    private $prev_kv;
    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getPrevKv(): ?KeyValue { return $this->prev_kv; }
    public function setPrevKv(KeyValue $var): void { $this->prev_kv = $var; }
}
