<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class AuthStatusResponse { private $header; private $enabled = false; private $authRevision = 0; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getEnabled(): bool { return $this->enabled; } public function setEnabled(bool $var): void { $this->enabled = $var; } public function getAuthRevision(): int { return $this->authRevision; } public function setAuthRevision(int $var): void { $this->authRevision = $var; } }
