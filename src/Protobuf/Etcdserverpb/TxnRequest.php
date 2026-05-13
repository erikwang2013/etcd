<?php
declare(strict_types=1);
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

class TxnRequest
{
    private $compare;
    private $success;
    private $failure;
    public function __construct()
    {
        $this->compare = [];
        $this->success = [];
        $this->failure = [];}
    /** @return Compare[] */
    public function getCompare(): array { return $this->compare; }
    /** @return RequestOp[] */
    public function getSuccess(): array { return $this->success; }
    /** @return RequestOp[] */
    public function getFailure(): array { return $this->failure; }
}
