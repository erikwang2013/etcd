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

use Erikwang2013\Etcd\Exception\AuthException;
use Erikwang2013\Etcd\Exception\EtcdException;
use Erikwang2013\Etcd\Transport\TransportInterface;

/**
 * Auth administration: enable/disable, status, plus user() and role() clients.
 *
 * Configuring `auth.user` / `auth.password` on the transport makes all of this
 * work against an auth-enabled cluster on its own — the transport fetches a
 * token lazily on the first request and refreshes it when etcd rejects it.
 * authenticate() is the explicit form, for when you want the token itself.
 */
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
     * Exchange credentials for an etcd v3 auth token.
     *
     * etcd v3 does not accept HTTP Basic auth: POST /v3/auth/authenticate with
     * the user name and password returns a token that must come back in a bare
     * `Authorization:` header (no `Bearer` prefix).
     *
     * You rarely need this. Configuring `auth.user` / `auth.password` on the
     * transport (HTTPS only) makes every call authenticate lazily by itself,
     * and the transport caches the token it receives here, so a client with
     * credentials configured is authorised for later requests after this call.
     * With no credentials configured the returned token is *not* attached to
     * later requests — pass it yourself if you need that.
     *
     * @throws EtcdException when etcd refuses the credentials (a bad user name
     *                       or password is HTTP 400, reported as a plain
     *                       EtcdException) or answers without a token
     */
    public function authenticate(string $user, string $password): string
    {
        $response = $this->transport->send('/v3/auth/authenticate', [
            'name'     => $user,
            'password' => $password,
        ]);
        // A 200 without a token means nothing was cached either, and the caller
        // would send an empty Authorization header instead.
        if (!isset($response['token']) || $response['token'] === '') {
            throw new AuthException('etcd returned no auth token for these credentials.');
        }
        return (string) $response['token'];
    }

    /**
     * Enable authentication.
     *
     * etcd refuses this unless a root user exists (create it from the cluster,
     * via etcdctl or user()->add(), before calling).
     */
    public function enable(): array
    {
        $response = $this->transport->send('/v3/auth/enable', []);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Disable authentication.
     *
     * This call still needs to be authenticated once auth is on.
     */
    public function disable(): array
    {
        $response = $this->transport->send('/v3/auth/disable', []);
        return ['header' => $response['header'] ?? []];
    }

    /**
     * Get authentication status.
     *
     * @return array ['header' => [...], 'enabled' => bool, 'authRevision' => int]
     */
    public function status(): array
    {
        $response = $this->transport->send('/v3/auth/status', []);
        return [
            'header'       => $response['header'] ?? [],
            'enabled'      => !empty($response['enabled']),
            'authRevision' => (int) ($response['authRevision'] ?? 0),
        ];
    }
}
