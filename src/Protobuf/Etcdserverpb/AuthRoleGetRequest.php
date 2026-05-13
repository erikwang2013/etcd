<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class AuthRoleGetRequest { private $role = ''; public function getRole(): string { return $this->role; } public function setRole(string $var): void { $this->role = $var; } }
