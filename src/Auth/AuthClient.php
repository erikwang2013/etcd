<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Auth;

use Erikwang2013\Etcd\Transport\TransportInterface;

class AuthClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function user(): UserClient
    {
        return new UserClient($this->transport);
    }

    public function role(): RoleClient
    {
        return new RoleClient($this->transport);
    }

    /**
     * Enable authentication.
     */
    public function enable(): array
    {
        $response = $this->transport->send('/v3/auth/auth/enable', []);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Disable authentication.
     */
    public function disable(): array
    {
        $response = $this->transport->send('/v3/auth/auth/disable', []);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Get authentication status.
     *
     * @return array ['header' => [...], 'enabled' => bool, 'authRevision' => int]
     */
    public function status(): array
    {
        $response = $this->transport->send('/v3/auth/auth/status', []);
        return [
            'header'       => $response['header'] ?? [],
            'enabled'      => !empty($response['enabled']),
            'authRevision' => (int) ($response['authRevision'] ?? 0),
        ];
    }
}
