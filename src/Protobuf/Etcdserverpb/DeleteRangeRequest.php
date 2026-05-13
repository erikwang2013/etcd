<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class DeleteRangeRequest
{
    private $key = '';
    private $range_end = '';
    private $prev_kv = false;
    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getRangeEnd(): string { return $this->range_end; }
    public function setRangeEnd(string $var): void { $this->range_end = $var; }
    public function getPrevKv(): bool { return $this->prev_kv; }
    public function setPrevKv(bool $var): void { $this->prev_kv = $var; }
}
