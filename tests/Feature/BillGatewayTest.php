<?php

namespace Aelura\BillGateway\Tests\Feature;

use PHPUnit\Framework\TestCase;

class BillGatewayTest extends TestCase
{
    public function test_package_classes_can_be_autoloaded(): void
    {
        $this->assertTrue(class_exists('Aelura\\BillGateway\\BillGatewayServiceProvider'));
        $this->assertTrue(class_exists('Aelura\\BillGateway\\BillGatewayManager'));
        $this->assertTrue(class_exists('Aelura\\BillGateway\\Facades\\BillGateway'));
    }
}
