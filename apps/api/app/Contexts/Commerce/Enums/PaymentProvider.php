<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Payment providers the commerce module can resolve behind the PaymentGateway abstraction.
 * `fake` is local/test only; `stripe` is the international card rail; the remainder are the MENA
 * gateways. Adding a case here does not couple any commerce code to a vendor — only the matching
 * adapter under Payments/Gateways references a provider SDK/API.
 */
enum PaymentProvider: string
{
    case Fake = 'fake';
    case Stripe = 'stripe';
    case Paymob = 'paymob';
    case Moyasar = 'moyasar';
    case HyperPay = 'hyperpay';
    case Tap = 'tap';
    case AmazonPaymentServices = 'aps';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
