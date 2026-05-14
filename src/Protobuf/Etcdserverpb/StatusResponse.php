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

class StatusResponse { private $header; private $version = ''; private $dbSize = 0; private $leader = 0; private $raftIndex = 0; private $raftTerm = 0; private $raftAppliedIndex = 0; private $errors; public function __construct() { $this->errors = [];} public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getVersion(): string { return $this->version; } public function setVersion(string $var): void { $this->version = $var; } public function getDbSize(): int { return $this->dbSize; } public function setDbSize(int $var): void { $this->dbSize = $var; } public function getLeader(): int { return $this->leader; } public function setLeader(int $var): void { $this->leader = $var; } public function getRaftIndex(): int { return $this->raftIndex; } public function setRaftIndex(int $var): void { $this->raftIndex = $var; } public function getRaftTerm(): int { return $this->raftTerm; } public function setRaftTerm(int $var): void { $this->raftTerm = $var; } public function getRaftAppliedIndex(): int { return $this->raftAppliedIndex; } public function setRaftAppliedIndex(int $var): void { $this->raftAppliedIndex = $var; } public function getErrors(): array { return $this->errors; } }
