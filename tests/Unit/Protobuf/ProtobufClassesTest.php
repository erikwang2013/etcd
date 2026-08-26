<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit\Protobuf;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Generic reflection-based test covering every Protobuf message class:
 * setter/getter round-trip for all accessor pairs.
 */
class ProtobufClassesTest extends TestCase
{
    public static function protobufClasses(): array
    {
        $classes = [];
        foreach (glob(__DIR__ . '/../../../src/Protobuf/*/*.php') as $file) {
            $relative = substr($file, strlen(__DIR__ . '/../../../src/'));
            $fqcn = 'Erikwang2013\\Etcd\\' . str_replace('/', '\\', substr($relative, 0, -4));
            $classes[$fqcn] = [$fqcn];
        }
        return $classes;
    }

    #[Test]
    #[DataProvider('protobufClasses')]
    public function accessorRoundTrip(string $fqcn): void
    {
        $instance = new $fqcn();
        $ref = new \ReflectionClass($instance);

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (!str_starts_with($name, 'set') || $method->getNumberOfRequiredParameters() !== 1) {
                continue;
            }
            $getter = 'get' . substr($name, 3);
            if (!$ref->hasMethod($getter)) {
                continue;
            }
            $value = $this->sampleValue($method->getParameters()[0]->getType());
            if ($value === null) {
                continue; // unsupported type (e.g. external class) — not covered here
            }
            $instance->$name($value);
            $this->assertSame($value, $instance->$getter(), "$fqcn::$name round-trip");
        }

        $this->assertInstanceOf($fqcn, $instance, "$fqcn must be instantiable");
    }

    private function sampleValue(?\ReflectionType $type): mixed
    {
        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }
        return match ($type->getName()) {
            'int'    => 7,
            'string' => 'probe',
            'bool'   => true,
            'float'  => 1.5,
            'array'  => ['probe'],
            default  => $type->isBuiltin() ? null : $this->tryInstantiate($type->getName()),
        };
    }

    private function tryInstantiate(string $fqcn): ?object
    {
        if (!class_exists($fqcn) || !str_starts_with($fqcn, 'Erikwang2013\\Etcd\\Protobuf\\')) {
            return null;
        }
        try {
            return new $fqcn();
        } catch (\Throwable) {
            return null;
        }
    }
}
