<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

class HashResponse { private $header; private $hash = 0; public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getHash(): int { return $this->hash; } public function setHash(int $var): void { $this->hash = $var; } }
