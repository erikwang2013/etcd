<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

class RequestOp
{
    private $request_range;
    private $request_put;
    private $request_delete_range;
    private $request_txn;
    public function getRequestRange(): ?RangeRequest { return $this->request_range; }
    public function setRequestRange(RangeRequest $var): void { $this->request_range = $var; }
    public function getRequestPut(): ?PutRequest { return $this->request_put; }
    public function setRequestPut(PutRequest $var): void { $this->request_put = $var; }
    public function getRequestDeleteRange(): ?DeleteRangeRequest { return $this->request_delete_range; }
    public function setRequestDeleteRange(DeleteRangeRequest $var): void { $this->request_delete_range = $var; }
    public function getRequestTxn(): ?TxnRequest { return $this->request_txn; }
    public function setRequestTxn(TxnRequest $var): void { $this->request_txn = $var; }
}
