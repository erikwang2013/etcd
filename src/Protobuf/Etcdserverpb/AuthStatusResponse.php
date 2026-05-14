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
class AuthStatusResponse { private $header; private $enabled = false; private $authRevision = 0; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getEnabled(): bool { return $this->enabled; } public function setEnabled(bool $var): void { $this->enabled = $var; } public function getAuthRevision(): int { return $this->authRevision; } public function setAuthRevision(int $var): void { $this->authRevision = $var; } }
