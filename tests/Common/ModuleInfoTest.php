<?php

declare(strict_types=1);

namespace Botbye\Tests\Common;

use Botbye\Common\ModuleInfo;
use PHPUnit\Framework\TestCase;

final class ModuleInfoTest extends TestCase
{
    public function testModuleConstants(): void
    {
        $this->assertSame('PHP', ModuleInfo::NAME);
        $this->assertSame('4.0.0', ModuleInfo::VERSION);
    }
}
