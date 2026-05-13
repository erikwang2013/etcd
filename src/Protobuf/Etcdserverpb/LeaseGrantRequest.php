<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class LeaseGrantRequest
{
    private $TTL = 0;
    private $ID = 0;
    public function getTTL(): int { return $this->TTL; }
    public function setTTL(int $var): void { $this->TTL = $var; }
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
}
