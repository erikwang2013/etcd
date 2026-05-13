<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class WatchCreateRequest
{
    private $key = '';
    private $range_end = '';
    private $start_revision = 0;
    private $progress_notify = false;
    private $prev_kv = false;
    private $watch_id = 0;
    private $fragment = false;
    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getRangeEnd(): string { return $this->range_end; }
    public function setRangeEnd(string $var): void { $this->range_end = $var; }
    public function getStartRevision(): int { return $this->start_revision; }
    public function setStartRevision(int $var): void { $this->start_revision = $var; }
    public function getProgressNotify(): bool { return $this->progress_notify; }
    public function setProgressNotify(bool $var): void { $this->progress_notify = $var; }
    public function getPrevKv(): bool { return $this->prev_kv; }
    public function setPrevKv(bool $var): void { $this->prev_kv = $var; }
    public function getWatchId(): int { return $this->watch_id; }
    public function setWatchId(int $var): void { $this->watch_id = $var; }
    public function getFragment(): bool { return $this->fragment; }
    public function setFragment(bool $var): void { $this->fragment = $var; }
}
