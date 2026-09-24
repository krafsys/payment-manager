<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Enums;

enum GatewayName: string
{
    case Paystack = 'paystack';
    case Flutterwave = 'flutterwave';
    case Monnify = 'monnify';
    case Kora = 'kora';
    case Bachs = 'bachs';
}
