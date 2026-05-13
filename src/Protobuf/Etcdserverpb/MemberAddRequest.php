<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

class MemberAddRequest {
    private $peerURLs; private $isLearner = false;
    public function __construct() { $this->peerURLs = [];}
    public function getPeerURLs(): array { return $this->peerURLs; }
    public function getIsLearner(): bool { return $this->isLearner; }
    public function setIsLearner(bool $var): void { $this->isLearner = $var; }
}
