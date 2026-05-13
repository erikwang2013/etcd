<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class AuthUserChangePasswordRequest { private $name = ''; private $password = ''; public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; } public function getPassword(): string { return $this->password; } public function setPassword(string $var): void { $this->password = $var; } }
