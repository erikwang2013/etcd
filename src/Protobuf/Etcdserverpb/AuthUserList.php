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
class AuthUserListRequest {}
class AuthUserListResponse { private $header; private $users; public function __construct() { $this->users = [];} public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getUsers(): array { return $this->users; } }
