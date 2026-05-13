<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Erikwang2013\Etcd\Protobuf\Authpb\Permission;
class AuthRoleGetResponse {
    private $header;
    private $perm;
    public function __construct() { $this->perm = [];}
    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getPerm(): array { return $this->perm; }
}
