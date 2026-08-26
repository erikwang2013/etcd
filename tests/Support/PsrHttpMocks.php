<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * In-memory PSR-18/PSR-17/PSR-7 mocks for HttpTransport unit tests.
 *
 * Usage:
 *   $mocks = new PsrHttpMocks();
 *   $mocks->queue(200, '{"header":{}}');
 *   $transport->setHttpClient($mocks->client(), $mocks->requestFactory(), $mocks->streamFactory());
 *   $result = $transport->send('/v3/kv/put', ['key' => 'x']);
 *   $mocks->lastRequest() // [method, uri, headers, body] of the last sent request
 */
final class PsrHttpMocks
{
    /** @var list<array{0: int, 1: string, 2: array<string, string>}> */
    public array $queue = [];

    public ?\Throwable $exception = null;

    public ?array $lastRequest = null;

    public function queue(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = [$status, $body, $headers];
        return $this;
    }

    public function lastRequest(): ?array
    {
        return $this->lastRequest;
    }

    public function client(): ClientInterface
    {
        $owner = $this;
        return new class($owner) implements ClientInterface {
            public function __construct(private PsrHttpMocks $owner)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if ($this->owner->exception !== null) {
                    $e = $this->owner->exception;
                    if ($e instanceof ClientExceptionInterface) {
                        throw $e;
                    }
                    throw new class($e->getMessage()) extends \RuntimeException implements ClientExceptionInterface {
                    };
                }
                [$status, $body, $headers] = array_shift($this->owner->queue) ?? [200, '{}', []];
                $this->owner->lastRequest = [
                    $request->getMethod(),
                    (string) $request->getUri(),
                    $request->getHeaders(),
                    (string) $request->getBody(),
                ];
                return new class($status, $body, $headers) implements ResponseInterface {
                    public function __construct(
                        private int $status,
                        private string $body,
                        private array $headers = [],
                    ) {
                    }

                    public function getStatusCode(): int
                    {
                        return $this->status;
                    }

                    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
                    {
                        $clone = clone $this;
                        $clone->status = $code;
                        return $clone;
                    }

                    public function getReasonPhrase(): string
                    {
                        return '';
                    }

                    public function getProtocolVersion(): string
                    {
                        return '1.1';
                    }

                    public function withProtocolVersion(string $version): ResponseInterface
                    {
                        return clone $this;
                    }

                    public function getHeaders(): array
                    {
                        return $this->headers;
                    }

                    public function hasHeader(string $name): bool
                    {
                        return isset($this->headers[$name]);
                    }

                    public function getHeader(string $name): array
                    {
                        return [$this->headers[$name] ?? ''];
                    }

                    public function getHeaderLine(string $name): string
                    {
                        return $this->headers[$name] ?? '';
                    }

                    public function withHeader(string $name, $value): ResponseInterface
                    {
                        $clone = clone $this;
                        $clone->headers[$name] = (string) $value;
                        return $clone;
                    }

                    public function withAddedHeader(string $name, $value): ResponseInterface
                    {
                        return $this->withHeader($name, $value);
                    }

                    public function withoutHeader(string $name): ResponseInterface
                    {
                        $clone = clone $this;
                        unset($clone->headers[$name]);
                        return $clone;
                    }

                    public function getBody(): StreamInterface
                    {
                        return new class($this->body) implements StreamInterface {
                            private bool $eof = false;

                            public function __construct(private string $data)
                            {
                            }

                            public function __toString(): string
                            {
                                return $this->data;
                            }

                            public function close(): void
                            {
                            }

                            public function detach()
                            {
                                return null;
                            }

                            public function getSize(): ?int
                            {
                                return strlen($this->data);
                            }

                            public function tell(): int
                            {
                                return 0;
                            }

                            public function eof(): bool
                            {
                                return $this->eof;
                            }

                            public function isSeekable(): bool
                            {
                                return false;
                            }

                            public function seek(int $offset, int $whence = SEEK_SET): void
                            {
                            }

                            public function rewind(): void
                            {
                            }

                            public function isWritable(): bool
                            {
                                return false;
                            }

                            public function write(string $string): int
                            {
                                return 0;
                            }

                            public function isReadable(): bool
                            {
                                return true;
                            }

                            public function read(int $length): string
                            {
                                $this->eof = true;
                                return $this->data;
                            }

                            public function getContents(): string
                            {
                                $this->eof = true;
                                return $this->data;
                            }

                            public function getMetadata(?string $key = null)
                            {
                                return $key === null ? [] : null;
                            }
                        };
                    }

                    public function withBody(StreamInterface $body): ResponseInterface
                    {
                        return clone $this;
                    }
                };
            }
        };
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return new class implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return new class($method, (string) $uri) implements RequestInterface {
                    private array $headers = [];

                    private string $body = '';

                    public function __construct(private string $method, private string $uri)
                    {
                    }

                    public function getRequestTarget(): string
                    {
                        return $this->uri;
                    }

                    public function withRequestTarget(string $requestTarget): RequestInterface
                    {
                        return clone $this;
                    }

                    public function getMethod(): string
                    {
                        return $this->method;
                    }

                    public function withMethod(string $method): RequestInterface
                    {
                        $clone = clone $this;
                        $clone->method = $method;
                        return $clone;
                    }

                    public function getUri(): UriInterface
                    {
                        return new class($this->uri) implements UriInterface {
                            public function __construct(private string $uri)
                            {
                            }

                            public function __toString(): string
                            {
                                return $this->uri;
                            }

                            public function getScheme(): string
                            {
                                return '';
                            }

                            public function getAuthority(): string
                            {
                                return '';
                            }

                            public function getUserInfo(): string
                            {
                                return '';
                            }

                            public function getHost(): string
                            {
                                return '';
                            }

                            public function getPort(): ?int
                            {
                                return null;
                            }

                            public function getPath(): string
                            {
                                return '';
                            }

                            public function getQuery(): string
                            {
                                return '';
                            }

                            public function getFragment(): string
                            {
                                return '';
                            }

                            public function withScheme(string $scheme): UriInterface
                            {
                                return clone $this;
                            }

                            public function withUserInfo(string $user, ?string $password = null): UriInterface
                            {
                                return clone $this;
                            }

                            public function withHost(string $host): UriInterface
                            {
                                return clone $this;
                            }

                            public function withPort(?int $port): UriInterface
                            {
                                return clone $this;
                            }

                            public function withPath(string $path): UriInterface
                            {
                                return clone $this;
                            }

                            public function withQuery(string $query): UriInterface
                            {
                                return clone $this;
                            }

                            public function withFragment(string $fragment): UriInterface
                            {
                                return clone $this;
                            }
                        };
                    }

                    public function withUri($uri, bool $preserveHost = false): RequestInterface
                    {
                        $clone = clone $this;
                        $clone->uri = (string) $uri;
                        return $clone;
                    }

                    public function getProtocolVersion(): string
                    {
                        return '1.1';
                    }

                    public function withProtocolVersion(string $version): RequestInterface
                    {
                        return clone $this;
                    }

                    public function getHeaders(): array
                    {
                        return $this->headers;
                    }

                    public function hasHeader(string $name): bool
                    {
                        return isset($this->headers[$name]);
                    }

                    public function getHeader(string $name): array
                    {
                        return [$this->headers[$name] ?? ''];
                    }

                    public function getHeaderLine(string $name): string
                    {
                        return $this->headers[$name] ?? '';
                    }

                    public function withHeader(string $name, $value): RequestInterface
                    {
                        $clone = clone $this;
                        $clone->headers[$name] = (string) $value;
                        return $clone;
                    }

                    public function withAddedHeader(string $name, $value): RequestInterface
                    {
                        return $this->withHeader($name, $value);
                    }

                    public function withoutHeader(string $name): RequestInterface
                    {
                        $clone = clone $this;
                        unset($clone->headers[$name]);
                        return $clone;
                    }

                    public function getBody(): StreamInterface
                    {
                        return new class($this->body) implements StreamInterface {
                            public function __construct(private string $data)
                            {
                            }

                            public function __toString(): string
                            {
                                return $this->data;
                            }

                            public function close(): void
                            {
                            }

                            public function detach()
                            {
                                return null;
                            }

                            public function getSize(): ?int
                            {
                                return strlen($this->data);
                            }

                            public function tell(): int
                            {
                                return 0;
                            }

                            public function eof(): bool
                            {
                                return false;
                            }

                            public function isSeekable(): bool
                            {
                                return false;
                            }

                            public function seek(int $offset, int $whence = SEEK_SET): void
                            {
                            }

                            public function rewind(): void
                            {
                            }

                            public function isWritable(): bool
                            {
                                return false;
                            }

                            public function write(string $string): int
                            {
                                return 0;
                            }

                            public function isReadable(): bool
                            {
                                return true;
                            }

                            public function read(int $length): string
                            {
                                return $this->data;
                            }

                            public function getContents(): string
                            {
                                return $this->data;
                            }

                            public function getMetadata(?string $key = null)
                            {
                                return $key === null ? [] : null;
                            }
                        };
                    }

                    public function withBody(StreamInterface $body): RequestInterface
                    {
                        $clone = clone $this;
                        $clone->body = (string) $body;
                        return $clone;
                    }
                };
            }
        };
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return new class implements StreamFactoryInterface {
            public function createStream(string $content = ''): StreamInterface
            {
                return new class($content) implements StreamInterface {
                    public function __construct(private string $data)
                    {
                    }

                    public function __toString(): string
                    {
                        return $this->data;
                    }

                    public function close(): void
                    {
                    }

                    public function detach()
                    {
                        return null;
                    }

                    public function getSize(): ?int
                    {
                        return strlen($this->data);
                    }

                    public function tell(): int
                    {
                        return 0;
                    }

                    public function eof(): bool
                    {
                        return false;
                    }

                    public function isSeekable(): bool
                    {
                        return false;
                    }

                    public function seek(int $offset, int $whence = SEEK_SET): void
                    {
                    }

                    public function rewind(): void
                    {
                    }

                    public function isWritable(): bool
                    {
                        return false;
                    }

                    public function write(string $string): int
                    {
                        return 0;
                    }

                    public function isReadable(): bool
                    {
                        return true;
                    }

                    public function read(int $length): string
                    {
                        return substr($this->data, 0, $length);
                    }

                    public function getContents(): string
                    {
                        return $this->data;
                    }

                    public function getMetadata(?string $key = null)
                    {
                        return $key === null ? [] : null;
                    }
                };
            }

            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                return $this->createStream((string) @file_get_contents($filename));
            }

            public function createStreamFromResource($resource): StreamInterface
            {
                return $this->createStream((string) stream_get_contents($resource));
            }
        };
    }
}
