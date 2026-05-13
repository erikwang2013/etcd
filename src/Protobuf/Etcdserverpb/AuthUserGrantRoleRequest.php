<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class AuthUserGrantRoleRequest { private $user = ''; private $role = ''; public function getUser(): string { return $this->user; } public function setUser(string $var): void { $this->user = $var; } public function getRole(): string { return $this->role; } public function setRole(string $var): void { $this->role = $var; } }
