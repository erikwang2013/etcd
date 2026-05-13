<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;

class AlarmMember { private $memberID = 0; private $alarm = 0; public function getMemberID(): int { return $this->memberID; } public function setMemberID(int $var): void { $this->memberID = $var; } public function getAlarm(): int { return $this->alarm; } public function setAlarm(int $var): void { $this->alarm = $var; } }
