<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Authpb;
class Permission
{
    const READ = 0;
    const WRITE = 1;
    const READWRITE = 2;
    private $permType = 0;
    private $key = '';
    private $range_end = '';
    public function getPermType(): int { return $this->permType; }
    public function setPermType(int $var): void { $this->permType = $var; }
    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getRangeEnd(): string { return $this->range_end; }
    public function setRangeEnd(string $var): void { $this->range_end = $var; }
}
