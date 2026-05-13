<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Authpb;
class Role
{
    private $name = '';
    private $keyPermission;
    public function __construct()
    {
        $this->keyPermission = [];}
    public function getName(): string { return $this->name; }
    public function setName(string $var): void { $this->name = $var; }
    /** @return Permission[] */
    public function getKeyPermission(): array { return $this->keyPermission; }
    public function setKeyPermission(RepeatedField $var): void { $this->keyPermission = $var; }
}
