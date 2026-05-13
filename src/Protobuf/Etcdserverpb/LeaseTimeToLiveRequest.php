<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class LeaseTimeToLiveRequest
{
    private $ID = 0;
    private $keys = false;
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
    public function getKeys(): bool { return $this->keys; }
    public function setKeys(bool $var): void { $this->keys = $var; }
}
