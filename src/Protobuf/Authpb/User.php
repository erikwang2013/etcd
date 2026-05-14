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
class User
{
    private $name = '';
    private $password = '';
    private $roles;
    public function __construct()
    {
        $this->roles = [];}
    public function getName(): string { return $this->name; }
    public function setName(string $var): void { $this->name = $var; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $var): void { $this->password = $var; }
    public function getRoles(): array { return $this->roles; }
    public function setRoles(RepeatedField $var): void { $this->roles = $var; }
}
