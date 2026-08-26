<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Exception;

use Erikwang2013\Etcd\Exception\AuthException;
use Erikwang2013\Etcd\Exception\ConnectionException;
use Erikwang2013\Etcd\Exception\EtcdException;
use Erikwang2013\Etcd\Exception\KeyNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    #[Test]
    #[DataProvider('exceptionClasses')]
    public function allExceptionsExtendEtcdException(string $class): void
    {
        $this->assertTrue(is_subclass_of($class, EtcdException::class));
    }

    #[Test]
    public function etcdExceptionExtendsRuntimeException(): void
    {
        $this->assertTrue(is_subclass_of(EtcdException::class, \RuntimeException::class));
    }

    #[Test]
    #[DataProvider('exceptionClasses')]
    public function exceptionsCarryMessageCodeAndPrevious(string $class): void
    {
        $previous = new \LogicException('root cause');
        $e = new $class('boom', 42, $previous);

        $this->assertSame('boom', $e->getMessage());
        $this->assertSame(42, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function exceptionClasses(): array
    {
        return [
            'AuthException' => [AuthException::class],
            'ConnectionException' => [ConnectionException::class],
            'KeyNotFoundException' => [KeyNotFoundException::class],
        ];
    }

    #[Test]
    public function defaultConstructionYieldsEmptyMessage(): void
    {
        $this->assertSame('', (new EtcdException())->getMessage());
    }
}
