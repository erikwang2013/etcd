<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Erikwang2013\Etcd\Protobuf\Authpb\Permission;
class AuthRoleGrantPermissionRequest { private $name = ''; private $perm; public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; } public function getPerm(): ?Permission { return $this->perm; } public function setPerm(Permission $var): void { $this->perm = $var; } }
