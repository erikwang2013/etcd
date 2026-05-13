<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

class MemberUpdateRequest { private $ID = 0; private $peerURLs; public function __construct() { $this->peerURLs = [];} public function getID(): int { return $this->ID; } public function setID(int $var): void { $this->ID = $var; } public function getPeerURLs(): array { return $this->peerURLs; } }
