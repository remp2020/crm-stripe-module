<?php

namespace Crm\StripeModule\Models;

use Crm\PaymentsModule\Models\SuccessPageResolver\PaymentCompleteRedirectResolver;
use Crm\StripeModule\Gateways\StripeWallet;
use Nette\Database\Table\ActiveRow;

class RedirectResolver implements PaymentCompleteRedirectResolver
{
    public function wantsToRedirect(?ActiveRow $payment, string $status): bool
    {
        if ($payment && $payment->payment_gateway->code === StripeWallet::GATEWAY_CODE) {
            return true;
        }
        return false;
    }

    public function redirectArgs(?ActiveRow $payment, string $status): array
    {
        if (!$payment || $payment->payment_gateway->code !== StripeWallet::GATEWAY_CODE) {
            throw new \Exception('unhandled status when requesting redirectArgs (did you check wantsToRedirect first?): ' . $status);
        }

        return [
            ':Stripe:StripeWallet:default',
            [
                'id' => $payment->variable_symbol,
            ],
        ];
    }
}
