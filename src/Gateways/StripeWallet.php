<?php

namespace Crm\StripeModule\Gateways;

use Crm\PaymentsModule\Models\Gateways\GatewayAbstract;

class StripeWallet extends GatewayAbstract
{
    public const GATEWAY_CODE = 'stripe_wallet';

    protected function initialize()
    {
        // nothing here
    }

    public function begin($payment)
    {
        $url = $this->generateReturnUrl($payment, [
            'VS' => $payment->variable_symbol,
        ]);
        $this->httpResponse->redirect($url);
        exit();
    }

    public function complete($payment): ?bool
    {
        return null;
    }
}
