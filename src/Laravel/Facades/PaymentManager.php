<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Krafsys\PaymentManager\Contracts\GatewayInterface;

/**
 * @method static GatewayInterface gateway(string $name)
 * @method static string[] availableGateways()
 *
 * @see \Krafsys\PaymentManager\PaymentManager
 */
final class PaymentManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Krafsys\PaymentManager\PaymentManager::class;
    }
}
