<?php

declare(strict_types=1);

namespace Crm\StripeModule\Models;

class PaymentMeta
{
    public const INVOICE_ID = 'stripe_invoice_id';
    public const INVOICE_URL = 'stripe_invoice_url';
    public const SUBSCRIPTION_ID = 'stripe_subscription_id';
    public const PAYMENT_INTENT_ID = 'stripe_payment_intent_id';
    public const PAYMENT_METHOD_ID = 'stripe_payment_method_id';
}
