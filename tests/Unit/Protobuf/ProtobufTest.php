<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Protobuf;

use Erikwang2013\Etcd\Protobuf\Mvccpb\Event;
use Erikwang2013\Etcd\Protobuf\Mvccpb\KeyValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ProtobufTest extends TestCase
{
    #[Test]
    public function keyValueDefaults(): void
    {
        $kv = new KeyValue();

        $this->assertSame('', $kv->getKey());
        $this->assertSame(0, $kv->getCreateRevision());
        $this->assertSame(0, $kv->getModRevision());
        $this->assertSame(0, $kv->getVersion());
        $this->assertSame('', $kv->getValue());
        $this->assertSame(0, $kv->getLease());
    }

    #[Test]
    public function keyValueGetterSetterRoundtrip(): void
    {
        $kv = new KeyValue();
        $kv->setKey('k1');
        $kv->setCreateRevision(1);
        $kv->setModRevision(2);
        $kv->setVersion(3);
        $kv->setValue('v1');
        $kv->setLease(99);

        $this->assertSame('k1', $kv->getKey());
        $this->assertSame(1, $kv->getCreateRevision());
        $this->assertSame(2, $kv->getModRevision());
        $this->assertSame(3, $kv->getVersion());
        $this->assertSame('v1', $kv->getValue());
        $this->assertSame(99, $kv->getLease());
    }

    #[Test]
    public function keyValueHasNoSerializationApi(): void
    {
        // google/protobuf is only a suggested dependency; these classes are
        // plain PHP value objects without serialize/unserialize methods.
        $this->assertFalse(method_exists(KeyValue::class, 'serializeToString'));
        $this->assertFalse(method_exists(KeyValue::class, 'mergeFromString'));
    }

    #[Test]
    public function eventConstants(): void
    {
        $this->assertSame(0, Event::PUT);
        $this->assertSame(1, Event::DELETE);
    }

    #[Test]
    public function eventDefaults(): void
    {
        $event = new Event();

        $this->assertSame(0, $event->getType());
        $this->assertNull($event->getKv());
        $this->assertNull($event->getPrevKv());
    }

    #[Test]
    public function eventTypeRoundtrip(): void
    {
        $event = new Event();
        $event->setType(Event::DELETE);

        $this->assertSame(Event::DELETE, $event->getType());
    }

    #[Test]
    public function eventKvRoundtrip(): void
    {
        $kv = new KeyValue();
        $kv->setKey('k1');
        $event = new Event();
        $event->setKv($kv);

        $this->assertSame($kv, $event->getKv());
        $this->assertSame('k1', $event->getKv()->getKey());
    }

    #[Test]
    public function eventPrevKvRoundtrip(): void
    {
        $prev = new KeyValue();
        $prev->setValue('old');
        $event = new Event();
        $event->setPrevKv($prev);

        $this->assertSame($prev, $event->getPrevKv());
        $this->assertSame('old', $event->getPrevKv()->getValue());
    }
}
