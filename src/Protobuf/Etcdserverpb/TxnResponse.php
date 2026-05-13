<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class TxnResponse
{
    private $header;
    private $succeeded = false;
    private $responses;
    public function __construct()
    {
        $this->responses = [];}
    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getSucceeded(): bool { return $this->succeeded; }
    public function setSucceeded(bool $var): void { $this->succeeded = $var; }
    /** @return ResponseOp[] */
    public function getResponses(): array { return $this->responses; }
}
