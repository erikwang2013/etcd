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
use Erikwang2013\Etcd\Protobuf\Authpb\Permission;
class AuthRoleGrantPermissionRequest { private $name = ''; private $perm; public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; } public function getPerm(): ?Permission { return $this->perm; } public function setPerm(Permission $var): void { $this->perm = $var; } }
