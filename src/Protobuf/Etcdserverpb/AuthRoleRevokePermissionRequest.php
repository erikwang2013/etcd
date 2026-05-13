<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class AuthRoleRevokePermissionRequest { private $role = ''; private $key = ''; private $range_end = ''; public function getRole(): string { return $this->role; } public function setRole(string $var): void { $this->role = $var; } public function getKey(): string { return $this->key; } public function setKey(string $var): void { $this->key = $var; } public function getRangeEnd(): string { return $this->range_end; } public function setRangeEnd(string $var): void { $this->range_end = $var; } }
