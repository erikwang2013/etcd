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

class ResponseOp
{
    private $response_range;
    private $response_put;
    private $response_delete_range;
    private $response_txn;
    public function getResponseRange(): ?RangeResponse { return $this->response_range; }
    public function setResponseRange(RangeResponse $var): void { $this->response_range = $var; }
    public function getResponsePut(): ?PutResponse { return $this->response_put; }
    public function setResponsePut(PutResponse $var): void { $this->response_put = $var; }
    public function getResponseDeleteRange(): ?DeleteRangeResponse { return $this->response_delete_range; }
    public function setResponseDeleteRange(DeleteRangeResponse $var): void { $this->response_delete_range = $var; }
    public function getResponseTxn(): ?TxnResponse { return $this->response_txn; }
    public function setResponseTxn(TxnResponse $var): void { $this->response_txn = $var; }
}
