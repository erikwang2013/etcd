<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

class MemberAddResponse { private $header; private $member; private $members; public function __construct() { $this->members = [];} public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getMember(): ?Member { return $this->member; } public function setMember(Member $var): void { $this->member = $var; } public function getMembers(): array { return $this->members; } }
