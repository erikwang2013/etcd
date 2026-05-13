<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

class AlarmRequest { const NONE = 0; const NOSPACE = 1; const CORRUPT = 2; private $action = 0; private $memberID = 0; private $alarm = 0; public function getAction(): int { return $this->action; } public function setAction(int $var): void { $this->action = $var; } public function getMemberID(): int { return $this->memberID; } public function setMemberID(int $var): void { $this->memberID = $var; } public function getAlarm(): int { return $this->alarm; } public function setAlarm(int $var): void { $this->alarm = $var; } }
