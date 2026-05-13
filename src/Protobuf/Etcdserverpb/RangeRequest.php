<?php
declare(strict_types=1);
namespace Erikwang2013\Etcd\Protobuf\Etcdserverpb;
class RangeRequest
{
    private $key = '';
    private $range_end = '';
    private $limit = 0;
    private $revision = 0;
    private $sort_order = 0;
    private $sort_target = 0;
    private $serializable = false;
    private $keys_only = false;
    private $count_only = false;
    private $min_mod_revision = 0;
    private $max_mod_revision = 0;
    private $min_create_revision = 0;
    private $max_create_revision = 0;
    public function getKey(): string { return $this->key; }
    public function setKey(string $var): void { $this->key = $var; }
    public function getRangeEnd(): string { return $this->range_end; }
    public function setRangeEnd(string $var): void { $this->range_end = $var; }
    public function getLimit(): int { return $this->limit; }
    public function setLimit(int $var): void { $this->limit = $var; }
    public function getRevision(): int { return $this->revision; }
    public function setRevision(int $var): void { $this->revision = $var; }
    public function getSortOrder(): int { return $this->sort_order; }
    public function setSortOrder(int $var): void { $this->sort_order = $var; }
    public function getSortTarget(): int { return $this->sort_target; }
    public function setSortTarget(int $var): void { $this->sort_target = $var; }
    public function getSerializable(): bool { return $this->serializable; }
    public function setSerializable(bool $var): void { $this->serializable = $var; }
    public function getKeysOnly(): bool { return $this->keys_only; }
    public function setKeysOnly(bool $var): void { $this->keys_only = $var; }
    public function getCountOnly(): bool { return $this->count_only; }
    public function setCountOnly(bool $var): void { $this->count_only = $var; }
    public function getMinModRevision(): int { return $this->min_mod_revision; }
    public function setMinModRevision(int $var): void { $this->min_mod_revision = $var; }
    public function getMaxModRevision(): int { return $this->max_mod_revision; }
    public function setMaxModRevision(int $var): void { $this->max_mod_revision = $var; }
    public function getMinCreateRevision(): int { return $this->min_create_revision; }
    public function setMinCreateRevision(int $var): void { $this->min_create_revision = $var; }
    public function getMaxCreateRevision(): int { return $this->max_create_revision; }
    public function setMaxCreateRevision(int $var): void { $this->max_create_revision = $var; }
}
