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
    public function txnEncodesComparesForAllTargets(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new KvClient($t);

        $client->txn(
            [
                ['result' => 0, 'target' => 0, 'key' => 'a', 'version' => 5],
                ['result' => 1, 'target' => 1, 'key' => 'b', 'create_revision' => 6],
                ['result' => 2, 'target' => 2, 'key' => 'c', 'mod_revision' => 7],
                ['result' => 3, 'target' => 3, 'key' => 'd', 'value' => 'cmp-val'],
                ['result' => 0, 'target' => 4, 'key' => 'e', 'lease' => 8],
            ],
            [],
            []
        );

        $this->assertSame('/v3/kv/txn', $t->sent[0][0]);
        $c = $t->sent[0][1]['compare'];
        $this->assertCount(5, $c);
        $this->assertSame(0, $c[0]['result']);
        $this->assertSame(base64_encode('a'), $c[0]['key']);
        $this->assertSame(5, $c[0]['version']);
        $this->assertArrayNotHasKey('value', $c[0]);
        $this->assertSame(6, $c[1]['create_revision']);
        $this->assertSame(7, $c[2]['mod_revision']);
        $this->assertSame(base64_encode('cmp-val'), $c[3]['value']);
        $this->assertSame(8, $c[4]['lease']);
    }

    #[Test]
    public function txnEncodesRequestOps(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new KvClient($t);

        $client->txn(
            [['result' => 0, 'target' => 0, 'key' => 'k']],
            [['request_put' => ['key' => 'pk', 'value' => 'pv', 'lease' => 9]]],
            [
                ['request_range' => ['key' => 'rk', 'range_end' => 're']],
                ['request_delete_range' => ['key' => 'dk', 'range_end' => 'de']],
            ]
        );

        $body = $t->sent[0][1];
        $this->assertSame(base64_encode('pk'), $body['success'][0]['request_put']['key']);
        $this->assertSame(base64_encode('pv'), $body['success'][0]['request_put']['value']);
        $this->assertSame(9, $body['success'][0]['request_put']['lease']);
        $this->assertSame(base64_encode('rk'), $body['failure'][0]['request_range']['key']);
        $this->assertSame(base64_encode('re'), $body['failure'][0]['request_range']['range_end']);
        $this->assertSame(base64_encode('dk'), $body['failure'][1]['request_delete_range']['key']);
        $this->assertSame(base64_encode('de'), $body['failure'][1]['request_delete_range']['range_end']);
    }

    #[Test]
    public function txnDecodesAllResponseTypesRecursively(): void
    {
        $t = new FakeTransport();
        $t->addResponse([
            'header' => ['revision' => 3],
            'succeeded' => true,
            'responses' => [
                ['response_put' => ['prev_kv' => ['key' => base64_encode('pk'), 'value' => base64_encode('pv')]]],
                ['response_range' => ['kvs' => [['key' => base64_encode('rk'), 'value' => base64_encode('rv')]], 'count' => 1]],
                ['response_delete_range' => ['deleted' => 2]],
                ['response_txn' => ['succeeded' => false, 'responses' => [['response_put' => []]]]],
            ],
        ]);
        $client = new KvClient($t);

        $result = $client->txn([], []);

        $this->assertSame(['revision' => 3], $result['header']);
        $this->assertTrue($result['succeeded']);
        $this->assertSame('put', $result['responses'][0]['type']);
        $this->assertSame('pk', $result['responses'][0]['response']['prev_kv']['key']);
        $this->assertSame('range', $result['responses'][1]['type']);
        $this->assertSame('rk', $result['responses'][1]['response']['kvs'][0]['key']);
        $this->assertSame('delete', $result['responses'][2]['type']);
        $this->assertSame(2, $result['responses'][2]['response']['deleted']);
        $this->assertSame('txn', $result['responses'][3]['type']);
        $this->assertFalse($result['responses'][3]['response']['succeeded']);
        $this->assertSame('put', $result['responses'][3]['response']['responses'][0]['type']);
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
