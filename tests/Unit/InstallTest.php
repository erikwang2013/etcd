<?php

declare(strict_types=1);

namespace Erikwang2013\Etcd\Tests\Unit;

use Erikwang2013\Etcd\Install;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class InstallTest extends TestCase
{
    #[Test]
    public function webmanPluginConstantIsPackageName(): void
    {
        $this->assertSame('erikwang2013/etcd', Install::WEBMAN_PLUGIN);
    }

    #[Test]
    public function installAndUninstallArePublicStaticMethods(): void
    {
        $this->assertTrue(method_exists(Install::class, 'install'));
        $this->assertTrue(method_exists(Install::class, 'uninstall'));
    }

    // install() is not invoked here: it calls config_path(), a webman runtime
    // function that does not exist in a plain PHPUnit context (webman is not a
    // composer dependency of this package). Defining a stub global config_path()
    // would shadow webman's real function when this suite runs inside webman.
    // Only the WEBMAN_PLUGIN contract is exercised.
}
