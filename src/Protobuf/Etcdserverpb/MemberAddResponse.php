<?php
declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

class MemberAddResponse { private $header; private $member; private $members; public function __construct() { $this->members = [];} public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getMember(): ?Member { return $this->member; } public function setMember(Member $var): void { $this->member = $var; } public function getMembers(): array { return $this->members; } }
