<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Watch;

use Erikwang2013\Etcd\Transport\TransportInterface;

class WatchClient
{
    private TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Watch a key for changes. Blocks until the caller stops.
     *
     * @param string   $key           Key to watch (use '' for all keys with rangeEnd)
     * @param callable $onEvent       Called with each batch of events: function(array $events): void
     *                                Each event: ['type' => 'PUT'|'DELETE', 'kv' => [...], 'prev_kv' => [...]|null]
     * @param array    $options
     *   - 'rangeEnd'       => string   Range end for prefix watch
     *   - 'startRevision'  => int      Revision to start from
     *   - 'prevKv'         => bool     Return previous KV on DELETE
     *   - 'progressNotify' => bool     Periodic empty events
     */
    public function watch(string $key, callable $onEvent, array $options = []): void
    {
        $rangeEnd = $options['rangeEnd'] ?? '';
        $startRevision = $options['startRevision'] ?? 0;
        $this->transport->watch($key, $rangeEnd, $startRevision, $onEvent);
    }

    /**
     * Watch a prefix for changes.
     */
    public function watchPrefix(string $prefix, callable $onEvent, array $options = []): void
    {
        $options['rangeEnd'] = $this->prefixToRangeEnd($prefix);
        $this->watch($prefix, $onEvent, $options);
    }

    private function prefixToRangeEnd(string $prefix): string
    {
        if ($prefix === '') {
            return "\x00";
        }
        $bytes = $prefix;
        $len = \strlen($bytes);
        for ($i = $len - 1; $i >= 0; $i--) {
            $c = \ord($bytes[$i]);
            if ($c < 0xFF) {
                return \substr($bytes, 0, $i) . \chr($c + 1);
            }
        }
        return '';
    }
}
