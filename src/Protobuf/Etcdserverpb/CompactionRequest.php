<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class CompactionRequest
{
    private $revision = 0;
    private $physical = false;
    public function getRevision(): int { return $this->revision; }
    public function setRevision(int $var): void { $this->revision = $var; }
    public function getPhysical(): bool { return $this->physical; }
    public function setPhysical(bool $var): void { $this->physical = $var; }
}
