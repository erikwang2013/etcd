<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class AuthUserListRequest {}
class AuthUserListResponse { private $header; private $users; public function __construct() { $this->users = [];} public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getUsers(): array { return $this->users; } }
