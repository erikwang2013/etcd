<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Authpb;
class User
{
    private $name = '';
    private $password = '';
    private $roles;
    public function __construct()
    {
        $this->roles = [];}
    public function getName(): string { return $this->name; }
    public function setName(string $var): void { $this->name = $var; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $var): void { $this->password = $var; }
    public function getRoles(): array { return $this->roles; }
    public function setRoles(RepeatedField $var): void { $this->roles = $var; }
}
