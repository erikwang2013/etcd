<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class LeaseKeepAliveRequest
{
    private $ID = 0;
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
}
