<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

class MemberPromoteResponse { private $header; private $members; public function __construct() { $this->members = [];} public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getMembers(): array { return $this->members; } }
