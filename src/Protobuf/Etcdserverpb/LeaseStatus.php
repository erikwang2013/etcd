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

class LeaseStatus
{
    private $ID = 0;
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
}
