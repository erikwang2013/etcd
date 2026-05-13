<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class LeaseGrantResponse
{
    private $header;
    private $ID = 0;
    private $TTL = 0;
    private $error = '';
    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
    public function getTTL(): int { return $this->TTL; }
    public function setTTL(int $var): void { $this->TTL = $var; }
    public function getError(): string { return $this->error; }
    public function setError(string $var): void { $this->error = $var; }
}
