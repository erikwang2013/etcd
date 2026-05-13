<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class AuthRoleListRequest {}
class AuthRoleListResponse { private $header; private $roles; public function __construct() { $this->roles = [];} public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getRoles(): array { return $this->roles; } }
