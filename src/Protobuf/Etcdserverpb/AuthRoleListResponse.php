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
class AuthRoleListResponse { private $header; private $roles; public function __construct() { $this->roles = [];} public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getRoles(): array { return $this->roles; } }
