<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Support;

/**
 * A way to stop a blocking watch from outside its callback.
 *
 * watch() blocks until the callback throws, which is fine for a script that
 * exits anyway, but a long-running worker needs to shut a watch down cleanly —
 * on SIGTERM, on a config reload, or when the keyspace is no longer needed.
 *
 *     $handle = new WatchHandle();
 *     pcntl_signal(SIGTERM, fn() => $handle->cancel());
 *     $etcd->watch()->watch('/config/', $onEvent, ['handle' => $handle]);
 *
 * The flag is checked after every frame, on the stream driver's idle ticks
 * (every 200 ms) and, on the cURL driver, from curl's periodic progress
 * callback — so a quiet key still stops within about a second rather than
 * waiting for the next event.
 */
final class WatchHandle
{
    private bool $canceled = false;

    public function cancel(): void
    {
        $this->canceled = true;
    }

    public function isCanceled(): bool
    {
        return $this->canceled;
    }
}
