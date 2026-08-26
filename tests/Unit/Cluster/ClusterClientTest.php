<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Cluster;

use Erikwang2013\Etcd\Cluster\ClusterClient;
use Erikwang2013\Etcd\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ClusterClientTest extends TestCase
{
    #[Test]
    public function memberAddSendsPeerUrlsAndDefaults(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse([]);
        $client = new ClusterClient($transport);

        $client->memberAdd(['http://1.2.3.4:2379']);

        $this->assertSame(
            ['/v3/cluster/member/add', ['peerURLs' => ['http://1.2.3.4:2379']]],
            $transport->sent[0]
        );
    }

    #[Test]
    public function memberAddIncludesIsLearnerOnlyWhenTrue(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse([]);
        $client = new ClusterClient($transport);

        $client->memberAdd(['http://1.2.3.4:2379'], true);

        $this->assertSame(
            ['/v3/cluster/member/add', ['peerURLs' => ['http://1.2.3.4:2379'], 'isLearner' => true]],
            $transport->sent[0]
        );
    }

    #[Test]
    public function memberAddPassesThroughResponse(): void
    {
        $transport = new FakeTransport();
        $member = ['ID' => 1, 'peerURLs' => ['http://1.2.3.4:2379']];
        $members = [$member];
        $transport->addResponse(['header' => ['revision' => 1], 'member' => $member, 'members' => $members]);
        $client = new ClusterClient($transport);

        $result = $client->memberAdd(['http://1.2.3.4:2379']);

        $this->assertSame(['revision' => 1], $result['header']);
        $this->assertSame($member, $result['member']);
        $this->assertSame($members, $result['members']);
    }

    #[Test]
    public function memberRemoveSendsId(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse([]);
        $client = new ClusterClient($transport);

        $client->memberRemove(5);

        $this->assertSame(['/v3/cluster/member/remove', ['ID' => 5]], $transport->sent[0]);
    }

    #[Test]
    public function memberUpdateSendsIdAndPeerUrls(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse([]);
        $client = new ClusterClient($transport);

        $client->memberUpdate(5, ['http://9.9.9.9:2379']);

        $this->assertSame(
            ['/v3/cluster/member/update', ['ID' => 5, 'peerURLs' => ['http://9.9.9.9:2379']]],
            $transport->sent[0]
        );
    }

    #[Test]
    public function memberListSendsToListPath(): void
    {
        $transport = new FakeTransport();
        $members = [['ID' => 1], ['ID' => 2]];
        $transport->addResponse(['header' => ['revision' => 2], 'members' => $members]);
        $client = new ClusterClient($transport);

        $result = $client->memberList();

        $this->assertSame(['/v3/cluster/member/list', []], $transport->sent[0]);
        $this->assertSame(['revision' => 2], $result['header']);
        $this->assertSame($members, $result['members']);
    }

    #[Test]
    public function memberPromoteSendsId(): void
    {
        $transport = new FakeTransport();
        $members = [['ID' => 3]];
        $transport->addResponse(['members' => $members]);
        $client = new ClusterClient($transport);

        $result = $client->memberPromote(3);

        $this->assertSame(['/v3/cluster/member/promote', ['ID' => 3]], $transport->sent[0]);
        $this->assertSame($members, $result['members']);
    }

    #[Test]
    public function responseFieldsDefaultToEmptyArray(): void
    {
        $transport = new FakeTransport();
        $transport->addResponse([]);
        $client = new ClusterClient($transport);

        $result = $client->memberList();

        $this->assertSame([], $result['header']);
        $this->assertSame([], $result['members']);
    }
}
