<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Kv;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Erikwang2013\Etcd\Kv\KvClient;
use Erikwang2013\Etcd\Tests\Support\FakeTransport;

class KvClientTxnTest extends TestCase
{
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
            [['request_put' => ['key' => 'pk', 'value' => 'pv', 'lease' => 9, 'prevKv' => true, 'ignoreValue' => true, 'ignoreLease' => true]]],
            [
                ['request_range' => ['key' => 'rk', 'range_end' => 're']],
                ['request_delete_range' => ['key' => 'dk', 'range_end' => 'de', 'prevKv' => true]],
            ]
        );

        $body = $t->sent[0][1];
        $this->assertSame(base64_encode('pk'), $body['success'][0]['request_put']['key']);
        $this->assertSame(base64_encode('pv'), $body['success'][0]['request_put']['value']);
        $this->assertSame(9, $body['success'][0]['request_put']['lease']);
        $this->assertTrue($body['success'][0]['request_put']['prev_kv']);
        $this->assertTrue($body['success'][0]['request_put']['ignore_value']);
        $this->assertTrue($body['success'][0]['request_put']['ignore_lease']);
        $this->assertSame(base64_encode('rk'), $body['failure'][0]['request_range']['key']);
        $this->assertSame(base64_encode('re'), $body['failure'][0]['request_range']['range_end']);
        $this->assertSame(base64_encode('dk'), $body['failure'][1]['request_delete_range']['key']);
        $this->assertSame(base64_encode('de'), $body['failure'][1]['request_delete_range']['range_end']);
        $this->assertTrue($body['failure'][1]['request_delete_range']['prev_kv']);
    }

    #[Test]
    public function txnRangeOpKeepsRangeOptions(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new KvClient($t);

        $client->txn([], [
            ['request_range' => [
                'key'               => 'rk',
                'rangeEnd'          => 'rz',
                'limit'             => 4,
                'revision'          => 12,
                'sortOrder'         => 'ascend',
                'sortTarget'        => 'create',
                'keysOnly'          => true,
                'countOnly'         => true,
                'minModRevision'    => 5,
                'maxModRevision'    => 6,
                'minCreateRevision' => 7,
                'maxCreateRevision' => 8,
            ]],
        ]);

        $body = $t->sent[0][1]['success'][0]['request_range'];
        $this->assertSame(base64_encode('rk'), $body['key']);
        $this->assertSame(base64_encode('rz'), $body['range_end']);
        $this->assertSame(4, $body['limit']);
        $this->assertSame(12, $body['revision']);
        $this->assertSame(1, $body['sort_order']);
        $this->assertSame(2, $body['sort_target']);
        $this->assertTrue($body['keys_only']);
        $this->assertTrue($body['count_only']);
        $this->assertSame(5, $body['min_mod_revision']);
        $this->assertSame(6, $body['max_mod_revision']);
        $this->assertSame(7, $body['min_create_revision']);
        $this->assertSame(8, $body['max_create_revision']);
    }

    #[Test]
    public function txnEncodesNestedTxnBranches(): void
    {
        $t = new FakeTransport();
        $t->addResponse([]);
        $client = new KvClient($t);

        $client->txn(
            [],
            [
                ['request_txn' => [
                    'compare' => [['result' => 0, 'target' => 1, 'key' => 'nk', 'create_revision' => 0]],
                    'success' => [['request_put' => ['key' => 'hit', 'value' => 'yes']]],
                    'failure' => [['request_put' => ['key' => 'miss', 'value' => 'no']]],
                ]],
            ],
            [['request_put' => ['key' => 'outer-fail', 'value' => 'x']]]
        );

        $body = $t->sent[0][1];
        $nested = $body['success'][0]['request_txn'];
        $this->assertCount(1, $nested['compare']);
        $this->assertSame(base64_encode('nk'), $nested['compare'][0]['key']);
        $this->assertSame(1, $nested['compare'][0]['target']);
        $this->assertSame(0, $nested['compare'][0]['create_revision']);
        $this->assertSame(base64_encode('hit'), $nested['success'][0]['request_put']['key']);
        $this->assertSame(base64_encode('yes'), $nested['success'][0]['request_put']['value']);
        $this->assertSame(base64_encode('miss'), $nested['failure'][0]['request_put']['key']);
        $this->assertSame(base64_encode('outer-fail'), $body['failure'][0]['request_put']['key']);
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
}
