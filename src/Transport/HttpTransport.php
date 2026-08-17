<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * This file is part of erikwang2013/etcd.
 *
 * SPDX-License-Identifier: MIT
 */

namespace Erikwang2013\Etcd\Transport;

use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Exception\AuthException;
use Erikwang2013\Etcd\Exception\EtcdException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class HttpTransport implements TransportInterface
{
    private array $endpoints;
    private array $config;
    private ?ClientInterface $httpClient = null;
    private ?RequestFactoryInterface $requestFactory = null;
    private ?StreamFactoryInterface $streamFactory = null;
    /** @var array<string, \CurlHandle> */
    private array $curlHandles = [];
    private string $credentials = '';

    public function __construct(array $endpoints, array $config = [])
    {
        $this->endpoints = $endpoints;
        $this->config = $config;
        if (!empty($config['auth']['user'] ?? null)) {
            $this->credentials = base64_encode($config['auth']['user'] . ':' . ($config['auth']['password'] ?? ''));
            if (($config['scheme'] ?? 'http') !== 'https') {
                throw new ConnectionException('Refusing to send etcd credentials over plaintext HTTP. Set scheme=https (ETCD_SCHEME=https) or leave auth empty.');
            }
        }
    }

    public function setHttpClient(ClientInterface $client, RequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory): void
    {
        $this->httpClient = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    private function getHttpClient(): ClientInterface
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }
        throw new ConnectionException('No PSR-18 HTTP client configured. Call setHttpClient() or use a framework adapter.');
    }

    private function getRequestFactory(): RequestFactoryInterface
    {
        if ($this->requestFactory !== null) {
            return $this->requestFactory;
        }
        throw new ConnectionException('No PSR-17 request factory configured.');
    }

    private function getStreamFactory(): StreamFactoryInterface
    {
        if ($this->streamFactory !== null) {
            return $this->streamFactory;
        }
        throw new ConnectionException('No PSR-17 stream factory configured.');
    }

    public function send(string $path, array $body): array
    {
        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $retries = max(0, (int) ($this->config['retry'] ?? 2));
        $lastException = null;

        for ($i = 0; $i <= $retries; $i++) {
            $url = $this->endpointUrl($path);
            try {
                [$status, $responseBody] = $this->httpRequest($url, $bodyJson);
                return $this->decodeResponse($status, $responseBody);
            } catch (AuthException | EtcdException $e) {
                throw $e;
            } catch (\JsonException $e) {
                throw new EtcdException('Invalid JSON response from etcd: ' . substr($responseBody ?? '', 0, 200), previous: $e);
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($i < $retries) {
                    usleep(100000 * ($i + 1));
                }
            }
        }

        $last = $lastException ? get_class($lastException) . ': ' . substr($lastException->getMessage(), 0, 120) : 'unknown error';
        throw new ConnectionException("Failed to connect to etcd after {$retries} retries ({$last})", previous: $lastException);
    }

    public function sendRaw(string $path): string
    {
        $retries = max(0, (int) ($this->config['retry'] ?? 2));
        $lastException = null;

        for ($i = 0; $i <= $retries; $i++) {
            $url = $this->endpointUrl($path);
            try {
                [$status, $responseBody] = $this->httpRequest($url, '{}');
                if ($status === 401) {
                    throw new AuthException('Authentication failed. Check credentials.');
                }
                if ($status >= 400) {
                    throw new EtcdException("Request failed with HTTP {$status}: {$path}");
                }
                return $responseBody;
            } catch (AuthException | EtcdException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($i < $retries) {
                    usleep(100000 * ($i + 1));
                }
            }
        }

        $last = $lastException ? get_class($lastException) . ': ' . substr($lastException->getMessage(), 0, 120) : 'unknown error';
        throw new ConnectionException("Failed to connect to etcd after {$retries} retries ({$last})", previous: $lastException);
    }

    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent, array $options = []): void
    {
        $createRequest = ['key' => base64_encode($key)];
        if ($rangeEnd !== '') {
            $createRequest['range_end'] = base64_encode($rangeEnd);
        }
        if ($startRevision > 0) {
            $createRequest['start_revision'] = $startRevision;
        }
        if (!empty($options['prevKv'])) {
            $createRequest['prev_kv'] = true;
        }
        if (!empty($options['progressNotify'])) {
            $createRequest['progress_notify'] = true;
        }

        // no 'timeout' key: a 0 value makes the http wrapper abort streaming opens instantly.
        // reads never block anyway (stream_set_blocking(false) below), so the default socket timeout only bounds fopen itself.
        $contextOpts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode(['create_request' => $createRequest], JSON_THROW_ON_ERROR),
            ],
            'ssl' => $this->config['options']['ssl'] ?? ['verify_peer' => true],
        ];
        if ($this->credentials !== '') {
            $contextOpts['http']['header'] .= 'Authorization: Basic ' . $this->credentials . "\r\n";
        }

        $lastRevision = $startRevision;
        $backoffMs = 100;
        $fopenFailures = 0;

        while (true) {
            $contextOpts['http']['content'] = json_encode(['create_request' => $createRequest], JSON_THROW_ON_ERROR);
            $url = $this->endpointUrl('/v3/watch');
            $stream = @fopen($url, 'r', false, stream_context_create($contextOpts));

            if (!$stream) {
                if (++$fopenFailures > 5) {
                    throw new ConnectionException('Failed to open watch stream to ' . $url . ': ' . (error_get_last()['message'] ?? 'unknown error'));
                }
                usleep($backoffMs * 1000);
                $backoffMs = min($backoffMs * 2, 2000);
                continue;
            }

            $status = isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d+)#', $http_response_header[0], $m) ? (int) $m[1] : 0;
            if ($status === 401) {
                fclose($stream);
                throw new AuthException('Authentication failed. Check credentials.');
            }
            if ($status >= 400) {
                fclose($stream);
                throw new EtcdException("Watch request failed with HTTP {$status}");
            }

            $fopenFailures = 0;
            $backoffMs = 100;
            stream_set_blocking($stream, false);
            $buffer = '';

            try {
                while (true) {
                    $read = [$stream];
                    $write = null;
                    $except = null;
                    $ready = @stream_select($read, $write, $except, 0, 200000);
                    if ($ready === false || $ready === 0) {
                        continue;
                    }
                    $chunk = fread($stream, 65536);
                    if ($chunk === false || $chunk === '') {
                        if (feof($stream)) {
                            break;
                        }
                        continue;
                    }
                    $buffer .= $chunk;
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = trim(substr($buffer, 0, $pos));
                        $buffer = substr($buffer, $pos + 1);
                        if ($line === '') {
                            continue;
                        }
                        $data = json_decode($line, true);
                        if (!is_array($data)) {
                            throw new EtcdException('Invalid watch frame: ' . substr($line, 0, 200));
                        }
                        if (!isset($data['result'])) {
                            throw new EtcdException('Watch terminated by server: ' . ($data['error'] ?? 'unknown error'));
                        }
                        $result = $data['result'];
                        if (isset($result['header']['revision'])) {
                            $lastRevision = (int) $result['header']['revision'];
                        }
                        $events = [];
                        foreach ($result['events'] ?? [] as $event) {
                            $type = 'PUT';
                            if (isset($event['type']) && $event['type'] === 1) {
                                $type = 'DELETE';
                            }
                            $kv = $event['kv'] ?? [];
                            if (isset($kv['key'])) {
                                $d = base64_decode($kv['key'], true);
                                $kv['key'] = $d !== false ? $d : $kv['key'];
                            }
                            if (isset($kv['value'])) {
                                $d = base64_decode($kv['value'], true);
                                $kv['value'] = $d !== false ? $d : $kv['value'];
                            }
                            $prevKv = null;
                            if (isset($event['prev_kv'])) {
                                $prevKv = $event['prev_kv'];
                                if (isset($prevKv['key'])) {
                                    $d = base64_decode($prevKv['key'], true);
                                    $prevKv['key'] = $d !== false ? $d : $prevKv['key'];
                                }
                                if (isset($prevKv['value'])) {
                                    $d = base64_decode($prevKv['value'], true);
                                    $prevKv['value'] = $d !== false ? $d : $prevKv['value'];
                                }
                            }
                            $events[] = ['type' => $type, 'kv' => $kv, 'prev_kv' => $prevKv];
                        }
                        if (!empty($events)) {
                            $onEvent($events);
                        }
                    }
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            // server closed the stream: resume from last revision with capped backoff
            if ($lastRevision > 0) {
                $createRequest['start_revision'] = $lastRevision;
            }
            usleep($backoffMs * 1000);
            $backoffMs = min($backoffMs * 2, 2000);
        }
    }

    /**
     * @return array{0: int, 1: string} [HTTP status, response body]
     */
    private function httpRequest(string $url, string $bodyJson): array
    {
        if ($this->httpClient !== null) {
            $request = $this->getRequestFactory()->createRequest('POST', $url)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->getStreamFactory()->createStream($bodyJson));
            if ($this->credentials !== '') {
                $request = $request->withHeader('Authorization', 'Basic ' . $this->credentials);
            }
            $response = $this->getHttpClient()->sendRequest($request);
            return [$response->getStatusCode(), (string) $response->getBody()];
        }

        $ch = $this->getCurlHandle($url);
        $headers = ['Content-Type: application/json'];
        if ($this->credentials !== '') {
            $headers[] = 'Authorization: Basic ' . $this->credentials;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL           => $url,
            CURLOPT_POSTFIELDS    => $bodyJson,
            CURLOPT_HTTPHEADER    => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (float) ($this->config['timeout'] ?? 5.0),
            CURLOPT_CONNECTTIMEOUT => (float) ($this->config['timeout'] ?? 5.0),
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException('cURL error: ' . curl_error($ch));
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $raw];
    }

    private function getCurlHandle(string $url): \CurlHandle
    {
        $host = parse_url($url, PHP_URL_HOST) . ':' . (parse_url($url, PHP_URL_PORT) ?? '');
        if (!isset($this->curlHandles[$host])) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
            $this->curlHandles[$host] = $ch;
        }
        return $this->curlHandles[$host];
    }

    private function decodeResponse(int $status, string $responseBody): array
    {
        if ($status === 401) {
            throw new AuthException('Authentication failed. Check credentials.');
        }
        if ($status >= 400) {
            $errData = json_decode($responseBody, true);
            if (is_array($errData)) {
                $message = $errData['message'] ?? $errData['error'] ?? "HTTP {$status}";
            } else {
                $message = "HTTP {$status}: " . substr($responseBody, 0, 200);
            }
            throw new EtcdException("etcd error: {$message}");
        }
        return json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    }

    private function endpointUrl(string $path): string
    {
        $scheme = $this->config['scheme'] ?? 'http';
        return "{$scheme}://{$this->pickEndpoint()}{$path}";
    }

    private function pickEndpoint(): string
    {
        return $this->endpoints[array_rand($this->endpoints)];
    }
}
