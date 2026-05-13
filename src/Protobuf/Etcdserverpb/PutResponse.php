<?php
declare(strict_types=1);
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
