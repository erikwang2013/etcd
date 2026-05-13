<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

class AlarmResponse { private $header; private $alarms; public function __construct() { $this->alarms = [];} public function getHeader(): ?ResponseHeader { return $this->header; } public function setHeader(ResponseHeader $var): void { $this->header = $var; } public function getAlarms(): array { return $this->alarms; } }
