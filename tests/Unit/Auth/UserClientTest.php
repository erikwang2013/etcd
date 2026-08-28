<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Auth;

use Erikwang2013\Etcd\Auth\UserClient;
use Erikwang2013\Etcd\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UserClientTest extends TestCase
{
    #[Test]
    public function addSendsUserAddWithNameAndPassword(): void
    {
        $transport = (new FakeTransport())->addResponse(['header' => ['cluster_id' => '1']]);
        $client = new UserClient($transport);

        $result = $client->add('alice', 'secret');

        $this->assertSame([['/v3/auth/user/add', [
            'name' => 'alice',
            'password' => 'secret',
        ]]], $transport->sent);
        $this->assertSame(['header' => ['cluster_id' => '1']], $result);
    }

    #[Test]
    public function addDefaultsHeaderToEmptyArray(): void
    {
        $transport = (new FakeTransport())->addResponse([]);
        $client = new UserClient($transport);

        $this->assertSame(['header' => []], $client->add('alice', 'secret'));
    }

    #[Test]
    public function getSendsUserGetWithNameAndPassesRoles(): void
    {
        $transport = (new FakeTransport())->addResponse([
            'header' => ['cluster_id' => '1'],
            'roles' => ['admin', 'dev'],
        ]);
        $client = new UserClient($transport);

        $result = $client->get('alice');

        $this->assertSame([['/v3/auth/user/get', ['name' => 'alice']]], $transport->sent);
        $this->assertSame('1', $result['header']['cluster_id']);
        $this->assertSame(['admin', 'dev'], $result['roles']);
    }

    #[Test]
    public function getDefaultsRolesToEmptyArray(): void
    {
        $transport = (new FakeTransport())->addResponse([]);
        $client = new UserClient($transport);

        $this->assertSame([], $client->get('alice')['roles']);
    }

    #[Test]
    public function listSendsUserListAndPassesUsers(): void
    {
        $transport = (new FakeTransport())->addResponse([
            'header' => ['cluster_id' => '1'],
            'users' => ['alice', 'bob'],
        ]);
        $client = new UserClient($transport);

        $result = $client->list();

        $this->assertSame([['/v3/auth/user/list', []]], $transport->sent);
        $this->assertSame(['alice', 'bob'], $result['users']);
    }

    #[Test]
    public function listDefaultsUsersToEmptyArray(): void
    {
        $transport = (new FakeTransport())->addResponse([]);
        $client = new UserClient($transport);

        $this->assertSame([], $client->list()['users']);
    }

    #[Test]
    public function deleteSendsUserDeleteWithName(): void
    {
        $transport = (new FakeTransport())->addResponse(['header' => ['cluster_id' => '1']]);
        $client = new UserClient($transport);

        $result = $client->delete('alice');

        $this->assertSame([['/v3/auth/user/delete', ['name' => 'alice']]], $transport->sent);
        $this->assertSame(['header' => ['cluster_id' => '1']], $result);
    }

    #[Test]
    public function changePasswordSendsUserChangepwWithNameAndPassword(): void
    {
        $transport = (new FakeTransport())->addResponse(['header' => ['cluster_id' => '1']]);
        $client = new UserClient($transport);

        $result = $client->changePassword('alice', 'newpass');

        $this->assertSame([['/v3/auth/user/changepw', [
            'name' => 'alice',
            'password' => 'newpass',
        ]]], $transport->sent);
        $this->assertSame(['header' => ['cluster_id' => '1']], $result);
    }

    #[Test]
    public function grantRoleSendsUserGrantWithUserAndRole(): void
    {
        $transport = (new FakeTransport())->addResponse(['header' => ['cluster_id' => '1']]);
        $client = new UserClient($transport);

        $result = $client->grantRole('alice', 'admin');

        $this->assertSame([['/v3/auth/user/grant', [
            'user' => 'alice',
            'role' => 'admin',
        ]]], $transport->sent);
        $this->assertSame(['header' => ['cluster_id' => '1']], $result);
    }

    #[Test]
    public function revokeRoleSendsUserRevokeWithNameAndRole(): void
    {
        $transport = (new FakeTransport())->addResponse(['header' => ['cluster_id' => '1']]);
        $client = new UserClient($transport);

        $result = $client->revokeRole('alice', 'admin');

        $this->assertSame([['/v3/auth/user/revoke', [
            'name' => 'alice',
            'role' => 'admin',
        ]]], $transport->sent);
        $this->assertSame(['header' => ['cluster_id' => '1']], $result);
    }
}
