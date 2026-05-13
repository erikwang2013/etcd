<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class LeaseStatus
{
    private $ID = 0;
    public function getID(): int { return $this->ID; }
    public function setID(int $var): void { $this->ID = $var; }
}
class LeaseLeasesResponse
{
    private $header;
    private $leases;
    public function __construct()
    {
        $this->leases = [];}
    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    /** @return LeaseStatus[] */
    public function getLeases(): array { return $this->leases; }
}
