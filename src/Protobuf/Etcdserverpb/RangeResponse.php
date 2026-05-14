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
class RangeResponse
{
    private $header;
    private $kvs;
    private $more = false;
    private $count = 0;
    public function __construct()
    {
        $this->kvs = [];}
    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    /** @return KeyValue[] */
    public function getKvs(): array { return $this->kvs; }
    public function setKvs(RepeatedField $var): void { $this->kvs = $var; }
    public function getMore(): bool { return $this->more; }
    public function setMore(bool $var): void { $this->more = $var; }
    public function getCount(): int { return $this->count; }
    public function setCount(int $var): void { $this->count = $var; }
}
