<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
use Erikwang2013\Etcd\Protobuf\Mvccpb\Event;
class WatchResponse
{
    private $header;
    private $watch_id = 0;
    private $created = false;
    private $canceled = false;
    private $compact_revision = 0;
    private $cancel_reason = '';
    private $fragment = false;
    private $events;
    public function __construct()
    {
        $this->events = [];}
    public function getHeader(): ?ResponseHeader { return $this->header; }
    public function setHeader(ResponseHeader $var): void { $this->header = $var; }
    public function getWatchId(): int { return $this->watch_id; }
    public function setWatchId(int $var): void { $this->watch_id = $var; }
    public function getCreated(): bool { return $this->created; }
    public function setCreated(bool $var): void { $this->created = $var; }
    public function getCanceled(): bool { return $this->canceled; }
    public function setCanceled(bool $var): void { $this->canceled = $var; }
    public function getCompactRevision(): int { return $this->compact_revision; }
    public function setCompactRevision(int $var): void { $this->compact_revision = $var; }
    public function getCancelReason(): string { return $this->cancel_reason; }
    public function setCancelReason(string $var): void { $this->cancel_reason = $var; }
    public function getFragment(): bool { return $this->fragment; }
    public function setFragment(bool $var): void { $this->fragment = $var; }
    /** @return Event[] */
    public function getEvents(): array { return $this->events; }
}
