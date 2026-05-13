<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class Member {
    private $ID = 0; private $name = ''; private $peerURLs; private $clientURLs; private $isLearner = false;
    public function __construct() { $this->peerURLs = []; $this->clientURLs = [];}
    public function getID(): int { return $this->ID; } public function setID(int $var): void { $this->ID = $var; }
    public function getName(): string { return $this->name; } public function setName(string $var): void { $this->name = $var; }
    public function getPeerURLs(): array { return $this->peerURLs; }
    public function getClientURLs(): array { return $this->clientURLs; }
    public function getIsLearner(): bool { return $this->isLearner; } public function setIsLearner(bool $var): void { $this->isLearner = $var; }
}
