<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Auth;

use Erikwang2013\Etcd\Transport\TransportInterface;

class UserClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function add(string $name, string $password): array
    {
        $response = $this->transport->send('/v3/auth/user/add', [
            'name'     => $name,
            'password' => $password,
        ]);
        return ['header' => $response['header'] ?? []];
    }

    public function get(string $name): array
    {
        $response = $this->transport->send('/v3/auth/user/get', ['name' => $name]);
        return [
            'header' => $response['header'] ?? [],
            'roles'  => $response['roles'] ?? [],
        ];
    }

    public function list(): array
    {
        $response = $this->transport->send('/v3/auth/user/list', []);
        return [
            'header' => $response['header'] ?? [],
            'users'  => $response['users'] ?? [],
        ];
    }

    public function delete(string $name): array
    {
        $response = $this->transport->send('/v3/auth/user/delete', ['name' => $name]);
        return ['header' => $response['header'] ?? []];
    }

    public function changePassword(string $name, string $password): array
    {
        $response = $this->transport->send('/v3/auth/user/changepw', [
            'name'     => $name,
            'password' => $password,
        ]);
        return ['header' => $response['header'] ?? []];
    }

    public function grantRole(string $user, string $role): array
    {
        $response = $this->transport->send('/v3/auth/user/grant', [
            'name' => $user,
            'role' => $role,
        ]);
        return ['header' => $response['header'] ?? []];
    }

    public function revokeRole(string $user, string $role): array
    {
        $response = $this->transport->send('/v3/auth/user/revoke', [
            'name' => $user,
            'role' => $role,
        ]);
        return ['header' => $response['header'] ?? []];
    }
}
