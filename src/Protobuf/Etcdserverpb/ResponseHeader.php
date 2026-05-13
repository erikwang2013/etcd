<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

class ResponseHeader
{
    private $cluster_id;
    private $member_id;
    private $revision;
    private $raft_term;

    public function __construct()
    {
        $this->cluster_id = 0;
        $this->member_id = 0;
        $this->revision = 0;
        $this->raft_term = 0;}

    public function getClusterId(): int { return $this->cluster_id; }
    public function setClusterId(int $var): void { $this->cluster_id = $var; }
    public function getMemberId(): int { return $this->member_id; }
    public function setMemberId(int $var): void { $this->member_id = $var; }
    public function getRevision(): int { return $this->revision; }
    public function setRevision(int $var): void { $this->revision = $var; }
    public function getRaftTerm(): int { return $this->raft_term; }
    public function setRaftTerm(int $var): void { $this->raft_term = $var; }
}
