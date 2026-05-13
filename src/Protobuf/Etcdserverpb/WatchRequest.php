<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class WatchRequest
{
    private $create_request;
    public function getCreateRequest(): ?WatchCreateRequest { return $this->create_request; }
    public function setCreateRequest(WatchCreateRequest $var): void { $this->create_request = $var; }
}
