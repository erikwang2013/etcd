<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class AuthUserRevokeRoleRequest { private $name = ''; private $role = ''; public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; } public function getRole(): string { return $this->role; } public function setRole(string $var): void { $this->role = $var; } }
