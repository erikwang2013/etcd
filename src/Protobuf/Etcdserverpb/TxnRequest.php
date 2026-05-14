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
class TxnRequest
{
    private $compare;
    private $success;
    private $failure;
    public function __construct()
    {
        $this->compare = [];
        $this->success = [];
        $this->failure = [];
    }
    /** @return Compare[] */
    public function getCompare(): array { return $this->compare; }
    /** @return RequestOp[] */
    public function getSuccess(): array { return $this->success; }
    /** @return RequestOp[] */
    public function getFailure(): array { return $this->failure; }
}
