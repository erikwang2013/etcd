<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Auth;

use Erikwang2013\Etcd\Transport\TransportInterface;

class RoleClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function add(string $name): array
    {
        $response = $this->transport->send('/v3/auth/role/add', ['name' => $name]);
        return ['header' => $response['header'] ?? []];
    }

    public function get(string $role): array
    {
        $response = $this->transport->send('/v3/auth/role/get', ['role' => $role]);
        $perms = [];
        foreach ($response['perm'] ?? [] as $p) {
            $perms[] = [
                'permType'  => (int) ($p['permType'] ?? 0),
                'key'       => ($d = base64_decode($p['key'] ?? '', true)) !== false ? $d : ($p['key'] ?? ''),
                'range_end' => ($d = base64_decode($p['range_end'] ?? '', true)) !== false ? $d : ($p['range_end'] ?? ''),
            ];
        }
        return [
            'header' => $response['header'] ?? [],
            'perm'   => $perms,
        ];
    }

    public function list(): array
    {
        $response = $this->transport->send('/v3/auth/role/list', []);
        return [
            'header' => $response['header'] ?? [],
            'roles'  => $response['roles'] ?? [],
        ];
    }

    public function delete(string $role): array
    {
        $response = $this->transport->send('/v3/auth/role/delete', ['role' => $role]);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Grant a permission to a role.
     *
     * @param int $permType 0=READ, 1=WRITE, 2=READWRITE
     */
    public function grantPermission(string $role, int $permType, string $key, string $rangeEnd = ''): array
    {
        $perm = [
            'permType' => $permType,
            'key'      => base64_encode($key),
        ];
        if ($rangeEnd !== '') {
            $perm['range_end'] = base64_encode($rangeEnd);
        }
        $response = $this->transport->send('/v3/auth/role/grant', [
            'name' => $role,
            'perm' => $perm,
        ]);
        return ['header' => $response['header'] ?? []];
    }

    public function revokePermission(string $role, string $key, string $rangeEnd = ''): array
    {
        $body = [
            'role' => $role,
            'key'  => base64_encode($key),
        ];
        if ($rangeEnd !== '') {
            $body['range_end'] = base64_encode($rangeEnd);
        }
        $response = $this->transport->send('/v3/auth/role/revoke', $body);
        return ['header' => $response['header'] ?? []];
    }
}
