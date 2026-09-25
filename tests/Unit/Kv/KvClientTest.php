<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Kv;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Erikwang2013\Etcd\Kv\KvClient;
use Erikwang2013\Etcd\EtcdClient;
use Erikwang2013\Etcd\Exception\KeyNotFoundException;
use Erikwang2013\Etcd\Tests\Support\FakeTransport;

class KvClientTest extends TestCase
{
    #[Test]
    public function putSendsBase64KeyValue(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['header' => ['revision' => 1]]);
        $client = new KvClient($t);

        $result = $client->put('中文键', "\x00\x01\xFFbinary");

        $this->assertSame('/v3/kv/put', $t->sent[0][0]);
        $this->assertSame(base64_encode('中文键'), $t->sent[0][1]['key']);
        $this->assertSame(base64_encode("\x00\x01\xFFbinary"), $t->sent[0][1]['value']);
        $this->assertSame(['revision' => 1], $result['header']);
        $this->assertNull($result['prev_kv']);
    }

    #[Test]
    public function putMapsOptionsToWireFields(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new KvClient($t);

        $client->put('k', 'v', [
            'lease' => 42,
            'prevKv' => true,
            'ignoreValue' => true,
            'ignoreLease' => true,
        ]);

        $body = $t->sent[0][1];
        $this->assertSame(42, $body['lease']);
        $this->assertTrue($body['prev_kv']);
        $this->assertTrue($body['ignore_value']);
        $this->assertTrue($body['ignore_lease']);
    }

    #[Test]
    public function putDecodesPrevKv(): void
    {
        $t = new FakeTransport();
        $t->addResponse([
            'header' => ['cluster_id' => 7],
            'prev_kv' => [
                'key' => base64_encode('old'),
                'value' => base64_encode('prev-value'),
                'create_revision' => '3',
                'mod_revision' => '4',
                'version' => '2',
                'lease' => '5',
            ],
        ]);
        $client = new KvClient($t);

        $result = $client->put('k', 'v', ['prevKv' => true]);

        $this->assertSame(['cluster_id' => 7], $result['header']);
        $this->assertSame('old', $result['prev_kv']['key']);
        $this->assertSame('prev-value', $result['prev_kv']['value']);
        $this->assertSame(3, $result['prev_kv']['create_revision']);
        $this->assertSame(4, $result['prev_kv']['mod_revision']);
        $this->assertSame(2, $result['prev_kv']['version']);
        $this->assertSame(5, $result['prev_kv']['lease']);
    }

    #[Test]
    public function getSendsRangeWithMappedOptions(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['kvs' => [], 'count' => 0, 'more' => false]);
        $client = new KvClient($t);

        $client->get('key', [
            'rangeEnd' => 'key\xff',
            'limit' => 10,
            'revision' => 99,
            'sortOrder' => 'ascend',
            'sortTarget' => 'mod',
            'serializable' => true,
            'keysOnly' => true,
            'countOnly' => true,
        ]);

        $this->assertSame('/v3/kv/range', $t->sent[0][0]);
        $body = $t->sent[0][1];
        $this->assertSame(base64_encode('key'), $body['key']);
        $this->assertSame(base64_encode('key\xff'), $body['range_end']);
        $this->assertSame(10, $body['limit']);
        $this->assertSame(99, $body['revision']);
        $this->assertSame(1, $body['sort_order']);
        $this->assertSame(3, $body['sort_target']);
        $this->assertTrue($body['serializable']);
        $this->assertTrue($body['keys_only']);
        $this->assertTrue($body['count_only']);
    }

    #[Test]
    public function getMapsSortOrder(): void
    {
        $t = new FakeTransport();
        $client = new KvClient($t);

        $client->get('k', ['sortOrder' => 'none']);
        $this->assertSame(0, $t->sent[0][1]['sort_order']);
        $client->get('k', ['sortOrder' => 'ascend']);
        $this->assertSame(1, $t->sent[1][1]['sort_order']);
        $client->get('k', ['sortOrder' => 'descend']);
        $this->assertSame(2, $t->sent[2][1]['sort_order']);
    }

    #[Test]
    public function getRejectsInvalidSortOrder(): void
    {
        $t = new FakeTransport();
        $client = new KvClient($t);

        $this->expectException(\InvalidArgumentException::class);
        $client->get('k', ['sortOrder' => 'sideways']);
    }

    #[Test]
    public function getMapsRevisionFilters(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new KvClient($t);

        $client->get('k', [
            'rangeEnd'          => 'k\xff',
            'minModRevision'    => 11,
            'maxModRevision'    => 22,
            'minCreateRevision' => 33,
            'maxCreateRevision' => 44,
        ]);

        $body = $t->sent[0][1];
        $this->assertSame(base64_encode('k'), $body['key']);
        $this->assertSame(11, $body['min_mod_revision']);
        $this->assertSame(22, $body['max_mod_revision']);
        $this->assertSame(33, $body['min_create_revision']);
        $this->assertSame(44, $body['max_create_revision']);
    }

    #[Test]
    public function getLeavesRevisionFiltersOutWhenUnset(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new KvClient($t);

        $client->get('k');

        $body = $t->sent[0][1];
        foreach (['min_mod_revision', 'max_mod_revision', 'min_create_revision', 'max_create_revision'] as $field) {
            $this->assertArrayNotHasKey($field, $body);
        }
    }

    #[Test]
    public function getByPrefixKeepsTheCallersOptions(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new KvClient($t);

        $client->getByPrefix('pre/', [
            'limit'          => 3,
            'sortOrder'      => 'descend',
            'sortTarget'     => 'mod',
            'keysOnly'       => true,
            'countOnly'      => true,
            'minModRevision' => 7,
        ]);

        $body = $t->sent[0][1];
        $this->assertSame(base64_encode('pre/'), $body['key']);
        $this->assertSame(3, $body['limit']);
        $this->assertSame(2, $body['sort_order']);
        $this->assertSame(3, $body['sort_target']);
        $this->assertTrue($body['keys_only']);
        $this->assertTrue($body['count_only']);
        $this->assertSame(7, $body['min_mod_revision']);
    }

    #[Test]
    public function getRejectsInvalidSortTarget(): void
    {
        $t = new FakeTransport();
        $client = new KvClient($t);

        $this->expectException(\InvalidArgumentException::class);
        $client->get('k', ['sortTarget' => 'hash']);
    }

    #[Test]
    public function getDecodesKvsAndCountMore(): void
    {
        $t = new FakeTransport();
        $t->addResponse([
            'header' => ['revision' => 5],
            'kvs' => [
                ['key' => base64_encode('a'), 'value' => base64_encode('v1'), 'create_revision' => '1', 'mod_revision' => '2', 'version' => '3', 'lease' => '0'],
                ['key' => base64_encode('b'), 'create_revision' => '1', 'mod_revision' => '1', 'version' => '1'],
                ['key' => 'not-base64!!', 'value' => 'raw-value'],
            ],
            'count' => '7',
            'more' => true,
        ]);
        $client = new KvClient($t);

        $result = $client->get('k');

        $this->assertSame(['revision' => 5], $result['header']);
        $this->assertSame('a', $result['kvs'][0]['key']);
        $this->assertSame('v1', $result['kvs'][0]['value']);
        $this->assertSame(1, $result['kvs'][0]['create_revision']);
        $this->assertSame(2, $result['kvs'][0]['mod_revision']);
        $this->assertSame(3, $result['kvs'][0]['version']);
        $this->assertSame(0, $result['kvs'][0]['lease']);
        $this->assertNull($result['kvs'][1]['value']);
        $this->assertSame('not-base64!!', $result['kvs'][2]['key']);
        $this->assertSame('raw-value', $result['kvs'][2]['value']);
        $this->assertSame(7, $result['count']);
        $this->assertTrue($result['more']);
    }

    #[Test]
    public function getFallsBackCountToKvsWhenMissing(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['kvs' => [['key' => base64_encode('x')]]]);
        $client = new KvClient($t);

        $result = $client->get('k');

        $this->assertSame(1, $result['count']);
        $this->assertFalse($result['more']);
    }

    #[Test]
    public function getByPrefixUsesPrefixToRangeEnd(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new KvClient($t);

        $client->getByPrefix('foo');

        $this->assertSame('/v3/kv/range', $t->sent[0][0]);
        $this->assertSame(base64_encode(EtcdClient::prefixToRangeEnd('foo')), $t->sent[0][1]['range_end']);
        $this->assertSame(base64_encode('foo'), $t->sent[0][1]['key']);
    }

    #[Test]
    public function getOrFailReturnsFirstKv(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['kvs' => [['key' => base64_encode('k'), 'value' => base64_encode('v')]]]);
        $client = new KvClient($t);

        $kv = $client->getOrFail('k');

        $this->assertSame('k', $kv['key']);
        $this->assertSame('v', $kv['value']);
    }

    #[Test]
    public function getOrFailAsksForExactlyOneKey(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['kvs' => [['key' => base64_encode('p/a'), 'value' => base64_encode('v')]]]);
        $client = new KvClient($t);

        $kv = $client->getOrFail('p/', ['rangeEnd' => 'p0', 'limit' => 500]);

        $this->assertSame('/v3/kv/range', $t->sent[0][0]);
        $this->assertSame(1, $t->sent[0][1]['limit']);
        $this->assertSame(base64_encode('p0'), $t->sent[0][1]['range_end']);
        $this->assertSame('p/a', $kv['key']);
    }

    #[Test]
    public function getOrFailThrowsWhenMissing(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['kvs' => []]);
        $client = new KvClient($t);

        $this->expectException(KeyNotFoundException::class);
        $client->getOrFail('missing');
    }

    #[Test]
    public function deleteSendsDeleterangeWithOptions(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['deleted' => '2']);
        $client = new KvClient($t);

        $result = $client->delete('k', ['rangeEnd' => 'k\xff', 'prevKv' => true]);

        $this->assertSame('/v3/kv/deleterange', $t->sent[0][0]);
        $body = $t->sent[0][1];
        $this->assertSame(base64_encode('k'), $body['key']);
        $this->assertSame(base64_encode('k\xff'), $body['range_end']);
        $this->assertTrue($body['prev_kv']);
        $this->assertSame(2, $result['deleted']);
    }

    #[Test]
    public function deleteDecodesPrevKvs(): void
    {
        $t = new FakeTransport();
        $t->addResponse([
            'prev_kvs' => [
                ['key' => base64_encode('k'), 'value' => base64_encode('v')],
            ],
        ]);
        $client = new KvClient($t);

        $result = $client->delete('k', ['prevKv' => true]);

        $this->assertSame('k', $result['prev_kvs'][0]['key']);
        $this->assertSame('v', $result['prev_kvs'][0]['value']);
    }

    #[Test]
    public function deleteByPrefixUsesRangeEnd(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new KvClient($t);

        $client->deleteByPrefix('foo');

        $this->assertSame(base64_encode(EtcdClient::prefixToRangeEnd('foo')), $t->sent[0][1]['range_end']);
    }

    #[Test]
    public function compactSendsRevisionPhysicalAndReturnsHeader(): void
    {
        $t = new FakeTransport();
        $t->addResponse(['header' => ['revision' => 10]]);
        $client = new KvClient($t);

        $result = $client->compact(10, true);

        $this->assertSame('/v3/kv/compaction', $t->sent[0][0]);
        $this->assertSame(10, $t->sent[0][1]['revision']);
        $this->assertTrue($t->sent[0][1]['physical']);
        $this->assertSame(['revision' => 10], $result['header']);
    }

    #[Test]
    public function transportExceptionPropagates(): void
    {
        $t = new FakeTransport();
        $t->sendException = new \RuntimeException('boom');
        $client = new KvClient($t);

        $this->expectException(\RuntimeException::class);
        $client->put('k', 'v');
    }
}
