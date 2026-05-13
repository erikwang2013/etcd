<?php

declare(strict_types=1);

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

    public function __construct(array $endpoints, array $config = [])
    {
        $this->endpoints = $endpoints;
        $this->config = $config;
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
        $endpoint = $this->pickEndpoint();
        $url = "http://{$endpoint}{$path}";

        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $request = $this->getRequestFactory()->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->getStreamFactory()->createStream($bodyJson));

        if (!empty($this->config['auth']['user'])) {
            $credentials = base64_encode($this->config['auth']['user'] . ':' . ($this->config['auth']['password'] ?? ''));
            $request = $request->withHeader('Authorization', 'Basic ' . $credentials);
        }

        $retries = $this->config['retry'] ?? 2;
        $lastException = null;

        for ($i = 0; $i <= $retries; $i++) {
            try {
                $response = $this->getHttpClient()->sendRequest($request);
                $responseBody = (string) $response->getBody();

                if ($response->getStatusCode() === 401) {
                    throw new AuthException('Authentication failed. Check credentials.');
                }

                if ($response->getStatusCode() >= 400) {
                    $errData = json_decode($responseBody, true);
                    $message = $errData['message'] ?? $errData['error'] ?? "HTTP {$response->getStatusCode()}";
                    throw new EtcdException("etcd error: {$message}");
                }

                return json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

            } catch (AuthException $e) {
                throw $e;
            } catch (EtcdException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($i < $retries) {
                    usleep(100000);
                }
            }
        }

        throw new ConnectionException(
            "Failed to connect to etcd after {$retries} retries: " . ($lastException ? $lastException->getMessage() : ''),
            previous: $lastException
        );
    }

    public function watch(string $key, string $rangeEnd, int $startRevision, callable $onEvent, array $options = []): void
    {
        $endpoint = $this->pickEndpoint();
        $url = "http://{$endpoint}/v3/watch";

        $createRequest = [
            'key' => base64_encode($key),
        ];
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

        $body = json_encode(['create_request' => $createRequest], JSON_THROW_ON_ERROR);

        $contextOpts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 0,
            ],
        ];

        if (!empty($this->config['auth']['user'])) {
            $credentials = base64_encode($this->config['auth']['user'] . ':' . ($this->config['auth']['password'] ?? ''));
            $contextOpts['http']['header'] .= "Authorization: Basic {$credentials}\r\n";
        }

        $context = stream_context_create($contextOpts);
        $stream = @fopen($url, 'r', false, $context);

        if (!$stream) {
            throw new ConnectionException("Failed to open watch stream to {$url}");
        }

        stream_set_blocking($stream, false);

        $lastRevision = $startRevision;

        while (true) {
            $line = fgets($stream);
            if ($line === false) {
                if (feof($stream)) {
                    fclose($stream);
                    $createRequest['start_revision'] = $lastRevision;
                    $body = json_encode(['create_request' => $createRequest], JSON_THROW_ON_ERROR);
                    $contextOpts['http']['content'] = $body;
                    $context = stream_context_create($contextOpts);
                    $stream = @fopen($url, 'r', false, $context);
                    if (!$stream) {
                        throw new ConnectionException("Watch reconnect failed");
                    }
                    stream_set_blocking($stream, false);
                    continue;
                }
                usleep(50000);
                continue;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);
            if ($data === null || !isset($data['result'])) {
                continue;
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

    private function pickEndpoint(): string
    {
        return $this->endpoints[array_rand($this->endpoints)];
    }
}
