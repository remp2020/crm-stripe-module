<?php

namespace Crm\StripeModule\Models;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Nette\Database\Table\ActiveRow;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class StripeWalletClient
{
    private ApplicationConfig $applicationConfig;

    private PaymentMetaRepository $paymentMetaRepository;

    private bool $loaded = false;

    public function __construct(ApplicationConfig $applicationConfig, PaymentMetaRepository $paymentMetaRepository)
    {
        $this->applicationConfig = $applicationConfig;
        $this->paymentMetaRepository = $paymentMetaRepository;
    }

    private function initStripe(): void
    {
        if (!$this->loaded) {
            Stripe::setApiKey($this->applicationConfig->get('stripe_secret'));
            $this->loaded = true;
        }
    }

    public function createIntent(ActiveRow $payment, string $currency): PaymentIntent
    {
        $this->initStripe();

        $intent = PaymentIntent::create([
            'amount' => $payment->amount * 100,
            'currency' => $currency,
            'metadata' => [
                "source" => "crm",
                "subscription_type_id" => $payment->subscription_type_id,
                "subscription_type" => $payment->subscription_type_id ? $payment->subscription_type->code : null,
                "user_id" => $payment->user_id,
                "payment_id" => $payment->id,
                "vs" => $payment->variable_symbol,
                "subsciption_type_length" => $payment->subscription_type_id ? $payment->subscription_type->length : null,
            ],
        ]);

        return $intent;
    }

    public function linkPaymentWithIntent(ActiveRow $payment, string $intentId): void
    {
        $this->paymentMetaRepository->remove($payment, 'stripe_intent');
        $this->paymentMetaRepository->add($payment, 'stripe_intent', $intentId);
    }

    public function validIntentForPayment(ActiveRow $payment, string $intentId): bool
    {
        $meta = $this->paymentMetaRepository->findByPaymentAndKey($payment, 'stripe_intent');
        return $meta && $meta->value === $intentId;
    }

    public function loadIntent(string $id): ?PaymentIntent
    {
        $this->initStripe();
        return PaymentIntent::retrieve($id);
    }

    public function isIntentPaid(string $id): bool
    {
        $intent = $this->loadIntent($id);
        return $intent->status == PaymentIntent::STATUS_SUCCEEDED;
    }
}
