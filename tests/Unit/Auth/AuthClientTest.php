<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Auth;

use Erikwang2013\Etcd\Auth\AuthClient;
use Erikwang2013\Etcd\Auth\RoleClient;
use Erikwang2013\Etcd\Auth\UserClient;
use Erikwang2013\Etcd\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AuthClientTest extends TestCase
{
    #[Test]
    public function userReturnsUserClientSharingTransport(): void
    {
        $transport = new FakeTransport();
        $auth = new AuthClient($transport);

        $this->assertInstanceOf(UserClient::class, $auth->user());
    }

    #[Test]
    public function roleReturnsRoleClientSharingTransport(): void
    {
        $transport = new FakeTransport();
        $auth = new AuthClient($transport);

        $this->assertInstanceOf(RoleClient::class, $auth->role());
    }

    #[Test]
    public function enableSendsAuthEnableWithEmptyBodyAndHeader(): void
    {
        $transport = (new FakeTransport())->addResponse([
            'header' => ['cluster_id' => '123'],
        ]);
        $auth = new AuthClient($transport);

        $result = $auth->enable();

        $this->assertSame([['/v3/auth/auth/enable', []]], $transport->sent);
        $this->assertSame(['header' => ['cluster_id' => '123']], $result);
    }

    #[Test]
    public function enableDefaultsHeaderToEmptyArray(): void
    {
        $transport = (new FakeTransport())->addResponse([]);
        $auth = new AuthClient($transport);

        $this->assertSame(['header' => []], $auth->enable());
    }

    #[Test]
    public function disableSendsAuthDisableWithEmptyBodyAndHeader(): void
    {
        $transport = (new FakeTransport())->addResponse([
            'header' => ['cluster_id' => '123'],
        ]);
        $auth = new AuthClient($transport);

        $result = $auth->disable();

        $this->assertSame([['/v3/auth/auth/disable', []]], $transport->sent);
        $this->assertSame(['header' => ['cluster_id' => '123']], $result);
    }

    #[Test]
    public function statusSendsAuthStatusAndPassesHeader(): void
    {
        $transport = (new FakeTransport())->addResponse([
            'header' => ['cluster_id' => '123'],
            'enabled' => true,
            'authRevision' => 5,
        ]);
        $auth = new AuthClient($transport);

        $result = $auth->status();

        $this->assertSame([['/v3/auth/auth/status', []]], $transport->sent);
        $this->assertSame(['cluster_id' => '123'], $result['header']);
    }

    #[Test]
    public function statusCoercesEnabledToBoolean(): void
    {
        foreach ([1, '1', 'yes', true] as $truthy) {
            $transport = (new FakeTransport())->addResponse(['enabled' => $truthy]);
            $this->assertTrue((new AuthClient($transport))->status()['enabled']);
        }

        foreach ([0, '0', '', null, false] as $falsy) {
            $transport = (new FakeTransport())->addResponse(['enabled' => $falsy]);
            $this->assertFalse((new AuthClient($transport))->status()['enabled']);
        }
    }

    #[Test]
    public function statusCoercesAuthRevisionToInt(): void
    {
        $transport = (new FakeTransport())->addResponse(['authRevision' => '42']);
        $result = (new AuthClient($transport))->status();

        $this->assertSame(42, $result['authRevision']);
        $this->assertIsInt($result['authRevision']);
    }

    #[Test]
    public function statusDefaultsAuthRevisionToZero(): void
    {
        $transport = (new FakeTransport())->addResponse([]);
        $result = (new AuthClient($transport))->status();

        $this->assertSame(0, $result['authRevision']);
    }
}
