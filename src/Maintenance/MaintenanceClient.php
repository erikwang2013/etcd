<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Maintenance;

use Erikwang2013\Etcd\Transport\TransportInterface;
use Erikwang2013\Etcd\Exception\EtcdException;

class MaintenanceClient
{
    private TransportInterface $transport;
    private array $config;

    public function __construct(TransportInterface $transport, array $config = [])
    {
        $this->transport = $transport;
        $this->config = $config;
    }

    /**
     * Get the status of the connected etcd member.
     *
     * @return array ['header' => [...], 'version' => string, 'dbSize' => int, 'leader' => int, 'raftIndex' => int, 'raftTerm' => int, 'raftAppliedIndex' => int, 'errors' => list<string>]
     */
    public function status(): array
    {
        $response = $this->transport->send('/v3/maintenance/status', []);
        return [
            'header'           => $response['header'] ?? [],
            'version'          => $response['version'] ?? '',
            'dbSize'           => (int) ($response['dbSize'] ?? 0),
            'leader'           => (int) ($response['leader'] ?? 0),
            'raftIndex'        => (int) ($response['raftIndex'] ?? 0),
            'raftTerm'         => (int) ($response['raftTerm'] ?? 0),
            'raftAppliedIndex' => (int) ($response['raftAppliedIndex'] ?? 0),
            'errors'           => $response['errors'] ?? [],
        ];
    }

    /**
     * Manage etcd alarms.
     *
     * @param int $action   0=get, 1=activate, 2=deactivate
     * @param int $alarm    0=none, 1=nospace, 2=corrupt
     * @param int $memberID Member ID (0 for all)
     * @return array ['header' => [...], 'alarms' => [['memberID' => int, 'alarm' => int], ...]]
     */
    public function alarm(int $action = 0, int $alarm = 0, int $memberID = 0): array
    {
        $body = ['action' => $action];
        if ($memberID > 0) {
            $body['memberID'] = $memberID;
        }
        if ($alarm > 0) {
            $body['alarm'] = $alarm;
        }
        $response = $this->transport->send('/v3/maintenance/alarm', $body);
        return [
            'header' => $response['header'] ?? [],
            'alarms' => $response['alarms'] ?? [],
        ];
    }

    /**
     * Defragment the etcd database to reclaim storage.
     */
    public function defragment(): array
    {
        $response = $this->transport->send('/v3/maintenance/defragment', []);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Get the hash of the KV store (for integrity checking).
     *
     * @return array ['header' => [...], 'hash' => int]
     */
    public function hash(int $revision = 0): array
    {
        $body = [];
        if ($revision > 0) {
            $body['revision'] = $revision;
        }
        $response = $this->transport->send('/v3/maintenance/hash', $body);
        return [
            'header' => $response['header'] ?? [],
            'hash'   => (int) ($response['hash'] ?? 0),
        ];
    }

    /**
     * Take a snapshot of the etcd database. Returns raw binary data.
     * Caller should write to disk.
     */
    public function snapshot(): string
    {
        $endpoints = $this->config['endpoints'] ?? ['127.0.0.1:2379'];
        $endpoint = $endpoints[array_rand($endpoints)];
        $url = "http://{$endpoint}/v3/maintenance/snapshot";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        if (!empty($this->config['auth']['user'])) {
            $credentials = base64_encode($this->config['auth']['user'] . ':' . ($this->config['auth']['password'] ?? ''));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
        }

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new EtcdException("Snapshot failed with HTTP {$httpCode}");
        }

        return $data;
    }
}
