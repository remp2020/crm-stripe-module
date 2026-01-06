<?php

namespace Crm\StripeModule\Gateways;

use Crm\StripeModule\Models\PaymentMeta;
use Stripe\PaymentIntent;

class Stripe extends AbstractStripe
{
    public function begin($payment)
    {
        // check if there's payment method already associated (by collecting card data on frontend)
        $paymentMethodId = $this->paymentMetaRepository
            ->findByPaymentAndKey($payment, PaymentMeta::PAYMENT_METHOD_ID)
            ?->value;

        if ($paymentMethodId) {
            $this->processSetupIntent($paymentMethodId, $payment, PaymentIntent::SETUP_FUTURE_USAGE_ON_SESSION);
        }

        $this->processCheckout($payment, PaymentIntent::SETUP_FUTURE_USAGE_ON_SESSION);
    }
}
