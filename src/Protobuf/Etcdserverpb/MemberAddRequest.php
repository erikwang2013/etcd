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

class MemberAddRequest {
    private $peerURLs; private $isLearner = false;
    public function __construct() { $this->peerURLs = [];}
    public function getPeerURLs(): array { return $this->peerURLs; }
    public function getIsLearner(): bool { return $this->isLearner; }
    public function setIsLearner(bool $var): void { $this->isLearner = $var; }
}
