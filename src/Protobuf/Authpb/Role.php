<?php
declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */
namespace Erikwang2013\Etcd\Protobuf\Authpb;
class Role
{
    private $name = '';
    private $keyPermission;
    public function __construct()
    {
        $this->keyPermission = [];}
    public function getName(): string { return $this->name; }
    public function setName(string $var): void { $this->name = $var; }
    /** @return Permission[] */
    public function getKeyPermission(): array { return $this->keyPermission; }
    public function setKeyPermission(RepeatedField $var): void { $this->keyPermission = $var; }
}
