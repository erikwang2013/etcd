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
class AuthRoleAddRequest { private $name = ''; public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; } }
