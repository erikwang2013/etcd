<?php
declare(strict_types=1);
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
