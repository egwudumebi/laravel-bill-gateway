<?php

namespace Aelura\BillGateway\Facades;

use Illuminate\Support\Facades\Facade;

class BillGateway extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'bill-gateway';
    }
}
