<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Watch;

use Erikwang2013\Etcd\Support\WatchHandle;
use Erikwang2013\Etcd\Transport\TransportInterface;
use Erikwang2013\Etcd\EtcdClient;

class WatchClient
{
    /**
     * Every key watch() looks at. An option outside this list is a typo that
     * would otherwise be dropped on the floor exactly the way `handle` was:
     * the transport reads a fixed set of keys, so `'progessNotify'` watches
     * just as well as `'progressNotify'` and never says a word.
     */
    private const OPTIONS = [
        'rangeEnd'       => true,
        'startRevision'  => true,
        'prevKv'         => true,
        'progressNotify' => true,
        'handle'         => true,
    ];

    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Watch a key for changes. Blocks until the caller stops.
     *
     * There are two ways to stop: throw from $onEvent (fine for a script that
     * exits anyway), or pass a WatchHandle and cancel it from anywhere — which
     * is what a long-running worker needs, since it cannot run shutdown code
     * while it is parked inside this call:
     *
     *     $handle = new WatchHandle();
     *     pcntl_async_signals(true);
     *     pcntl_signal(SIGTERM, fn () => $handle->cancel());   // also: config reload, queue drained
     *     $etcd->watch()->watchPrefix('/config/', $onEvent, ['handle' => $handle]);
     *
     * Cancelling returns from watch() normally — no exception to catch — and
     * takes effect within one event: the transport checks the flag after every
     * frame and again before every (re)connect, so a cancel during an outage
     * returns instead of reconnecting once more. A watch sitting idle on a
     * quiet key stops on the next frame.
     *
     * The handle travels in $options rather than as a parameter because that is
     * the shape the transport reads (`$options['handle']`) and the one
     * WatchHandle documents; watchPrefix() forwards the same array, so both
     * entry points are covered by one code path. An option that is not a
     * WatchHandle is rejected here instead of forwarded, since the transport
     * would ignore it and the watch would simply never stop.
     *
     * @param string   $key           Key to watch (use '' for all keys with rangeEnd)
     * @param callable $onEvent       Called with each batch of events: function(array $events): void
     *                                Each event: ['type' => 'PUT'|'DELETE', 'kv' => [...], 'prev_kv' => [...]|null]
     * @param array    $options
     *   - 'rangeEnd'       => string      Range end for prefix watch
     *   - 'startRevision'  => int         Revision to start from
     *   - 'prevKv'         => bool        Return previous KV on DELETE
     *   - 'progressNotify' => bool        Periodic empty events
     *   - 'handle'         => WatchHandle Stop the watch from outside the callback
     */
    public function watch(string $key, callable $onEvent, array $options = []): void
    {
        self::validateOptions($options);

        $rangeEnd = $options['rangeEnd'] ?? '';
        // cast here: the transport takes an int, and a numeric string from
        // user config would otherwise be a TypeError under strict types
        $startRevision = (int) ($options['startRevision'] ?? 0);
        $watchOpts = [];
        if (!empty($options['prevKv'])) {
            $watchOpts['prevKv'] = true;
        }
        if (!empty($options['progressNotify'])) {
            $watchOpts['progressNotify'] = true;
        }
        $handle = $options['handle'] ?? null;
        if ($handle instanceof WatchHandle) {
            $watchOpts['handle'] = $handle;
        }
        $this->transport->watch($key, $rangeEnd, $startRevision, $onEvent, $watchOpts);
    }

    /**
     * Watch a prefix for changes. Takes the same options as watch(), including
     * 'handle'.
     */
    public function watchPrefix(string $prefix, callable $onEvent, array $options = []): void
    {
        $options['rangeEnd'] = EtcdClient::prefixToRangeEnd($prefix);
        $this->watch($prefix, $onEvent, $options);
    }

    /**
     * Fail at the call site rather than forward an option nothing will read.
     *
     * @param array<string, mixed> $options
     */
    private static function validateOptions(array $options): void
    {
        foreach (array_keys($options) as $name) {
            if (!isset(self::OPTIONS[$name])) {
                throw new \InvalidArgumentException(
                    "Unknown watch option '{$name}'. Known options: " . implode(', ', array_keys(self::OPTIONS)) . '.'
                );
            }
        }

        $handle = $options['handle'] ?? null;
        if ($handle !== null && !$handle instanceof WatchHandle) {
            throw new \InvalidArgumentException(
                "Watch option 'handle' must be an instance of " . WatchHandle::class
                . ', ' . get_debug_type($handle) . ' given. A value the transport cannot read means the watch never stops.'
            );
        }
    }
}
