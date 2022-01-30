<?php

namespace Crm\StripeModule\Gateways;

class Stripe extends AbstractStripe
{
    public function begin($payment)
    {
        $this->initialize();

        // check if there's payment method already associated (by collecting card data on frontend)
        $paymentMethodId = $this->paymentMetaRepository->values($payment, 'payment_method_id')->fetchField('value');
        if ($paymentMethodId) {
            $this->processSetupIntent($paymentMethodId, $payment, 'on_session');
        }

        $this->processCheckout($payment, 'on_session');
    }
}
