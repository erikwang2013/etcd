<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit;

use Erikwang2013\Etcd\Mascot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MascotTest extends TestCase
{
    #[Test]
    public function assetShipsWithThePackage(): void
    {
        $this->assertFileExists(Mascot::path());
    }

    #[Test]
    public function svgIsWellFormedXml(): void
    {
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML(Mascot::svg()), 'docs/pet.svg must parse as XML');
        $this->assertSame('svg', $doc->documentElement->nodeName);
    }

    #[Test]
    public function dataUriDecodesBackToTheSameSvg(): void
    {
        $prefix = 'data:image/svg+xml;base64,';
        $uri = Mascot::dataUri();

        $this->assertStringStartsWith($prefix, $uri);
        $this->assertSame(Mascot::svg(), base64_decode(substr($uri, strlen($prefix)), true));
    }
}
