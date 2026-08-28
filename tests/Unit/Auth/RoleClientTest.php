<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Auth;

use Erikwang2013\Etcd\Auth\RoleClient;
use Erikwang2013\Etcd\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RoleClientTest extends TestCase
{
    #[Test]
    public function addSendsRoleAddWithName(): void
    {
        $transport = (new FakeTransport())->addResponse(['header' => ['cluster_id' => '1']]);
        $client = new RoleClient($transport);

        $result = $client->add('admin');

        $this->assertSame([['/v3/auth/role/add', ['name' => 'admin']]], $transport->sent);
        $this->assertSame(['header' => ['cluster_id' => '1']], $result);
    }

    #[Test]
    public function getSendsRoleGetAndDecodesPerms(): void
    {
        $transport = (new FakeTransport())->addResponse([
            'header' => ['cluster_id' => '1'],
            'perm' => [
                ['permType' => 'WRITE', 'key' => base64_encode('foo'), 'range_end' => base64_encode('foo0')],
                ['permType' => 'READWRITE', 'key' => base64_encode('bar'), 'range_end' => ''],
            ],
        ]);
        $client = new RoleClient($transport);

        $result = $client->get('admin');

        $this->assertSame([['/v3/auth/role/get', ['role' => 'admin']]], $transport->sent);
        $this->assertSame('1', $result['header']['cluster_id']);
        $this->assertSame([
            ['permType' => 1, 'key' => 'foo', 'range_end' => 'foo0'],
            ['permType' => 2, 'key' => 'bar', 'range_end' => ''],
        ], $result['perm']);
    }

    #[Test]
    public function getCoercesPermTypeToInt(): void
    {
        $transport = (new FakeTransport())->addResponse([
            'perm' => [['permType' => 'READWRITE', 'key' => base64_encode('k'), 'range_end' => '']],
        ]);
        $perm = (new RoleClient($transport))->get('admin')['perm'][0];

        $this->assertSame(2, $perm['permType']);
        $this->assertIsInt($perm['permType']);
    }

    #[Test]
    public function getLeavesInvalidBase64KeyAsIs(): void
    {
        $transport = (new FakeTransport())->addResponse([
            'perm' => [['permType' => 0, 'key' => '!!!not-base64!!!', 'range_end' => '???']],
        ]);
        $perm = (new RoleClient($transport))->get('admin')['perm'][0];

        $this->assertSame('!!!not-base64!!!', $perm['key']);
        $this->assertSame('???', $perm['range_end']);
    }

    #[Test]
    public function getDefaultsPermToEmptyArray(): void
    {
        $transport = (new FakeTransport())->addResponse([]);
        $result = (new RoleClient($transport))->get('admin');

        $this->assertSame([], $result['perm']);
    }

    #[Test]
    public function listSendsRoleListAndPassesRoles(): void
    {
        $transport = (new FakeTransport())->addResponse([
            'header' => ['cluster_id' => '1'],
            'roles' => ['admin', 'dev'],
        ]);
        $client = new RoleClient($transport);

        $result = $client->list();

        $this->assertSame([['/v3/auth/role/list', []]], $transport->sent);
        $this->assertSame(['admin', 'dev'], $result['roles']);
    }

    #[Test]
    public function deleteSendsRoleDeleteWithRole(): void
    {
        $transport = (new FakeTransport())->addResponse(['header' => ['cluster_id' => '1']]);
        $client = new RoleClient($transport);

        $result = $client->delete('admin');

        $this->assertSame([['/v3/auth/role/delete', ['role' => 'admin']]], $transport->sent);
        $this->assertSame(['header' => ['cluster_id' => '1']], $result);
    }

    #[Test]
    public function grantPermissionSendsRoleGrantWithEncodedPerm(): void
    {
        $transport = (new FakeTransport())->addResponse(['header' => ['cluster_id' => '1']]);
        $client = new RoleClient($transport);

        $result = $client->grantPermission('admin', 1, 'foo');

        $this->assertSame([['/v3/auth/role/grant', [
            'name' => 'admin',
            'perm' => [
                'permType' => 1,
                'key' => base64_encode('foo'),
            ],
        ]]], $transport->sent);
        $this->assertSame(['header' => ['cluster_id' => '1']], $result);
    }

    #[Test]
    public function grantPermissionOmitsRangeEndWhenEmpty(): void
    {
        $transport = (new FakeTransport())->addResponse([]);
        $client = new RoleClient($transport);

        $client->grantPermission('admin', 0, 'foo');

        $this->assertArrayNotHasKey('range_end', $transport->sent[0][1]['perm']);
    }

    #[Test]
    public function grantPermissionEncodesRangeEndWhenProvided(): void
    {
        $transport = (new FakeTransport())->addResponse([]);
        $client = new RoleClient($transport);

        $client->grantPermission('admin', 2, 'foo', 'foo0');

        $this->assertSame('foo0', base64_decode($transport->sent[0][1]['perm']['range_end'], true));
    }

    #[Test]
    public function revokePermissionSendsRoleRevokeWithEncodedKey(): void
    {
        $transport = (new FakeTransport())->addResponse(['header' => ['cluster_id' => '1']]);
        $client = new RoleClient($transport);

        $result = $client->revokePermission('admin', 'foo');

        $this->assertSame([['/v3/auth/role/revoke', [
            'role' => 'admin',
            'key' => base64_encode('foo'),
        ]]], $transport->sent);
        $this->assertSame(['header' => ['cluster_id' => '1']], $result);
    }

    #[Test]
    public function revokePermissionOmitsRangeEndWhenEmpty(): void
    {
        $transport = (new FakeTransport())->addResponse([]);
        $client = new RoleClient($transport);

        $client->revokePermission('admin', 'foo');

        $this->assertSame(['role' => 'admin', 'key' => base64_encode('foo')], $transport->sent[0][1]);
    }

    #[Test]
    public function revokePermissionEncodesRangeEndWhenProvided(): void
    {
        $transport = (new FakeTransport())->addResponse([]);
        $client = new RoleClient($transport);

        $client->revokePermission('admin', 'foo', 'foo0');

        $this->assertSame('foo0', base64_decode($transport->sent[0][1]['range_end'], true));
    }
}
