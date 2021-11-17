<?php

namespace Crm\StripeModule\Models;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Exception;
use Nette\Database\Table\ActiveRow;
use Stripe\Exception\ApiErrorException;
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

    /**
     * @throws Exception
     */
    private function initStripe(): void
    {
        if (!$this->loaded) {
            $clientSecret = $this->applicationConfig->get('stripe_secret');
            if (!$clientSecret) {
                throw new Exception('Unable to initialize stripe, secret is missing from CRM Admin configuration');
            }
            Stripe::setApiKey($clientSecret);
            $this->loaded = true;
        }
    }

    /**
     * @throws Exception
     */
    public function createIntent(ActiveRow $payment): PaymentIntent
    {
        $this->initStripe();

        return PaymentIntent::create([
            'amount' => $payment->amount * 100,
            'currency' => $this->applicationConfig->get('currency'),
            'metadata' => [
                "source" => "crm",
                "subscription_type_id" => $payment->subscription_type_id,
                "subscription_type_length" => $payment->subscription_type_id ?? null,
                "user_id" => $payment->user_id,
                "payment_id" => $payment->id,
                "vs" => $payment->variable_symbol,
            ],
        ]);
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


    /**
     * @throws Exception
     */
    public function loadIntent(string $id): ?PaymentIntent
    {
        $this->initStripe();
        return PaymentIntent::retrieve($id);
    }

    /**
     * @throws ApiErrorException
     * @throws Exception
     */
    public function isIntentPaid(string $id): bool
    {
        $intent = $this->loadIntent($id);
        return $intent->status === PaymentIntent::STATUS_SUCCEEDED;
    }
}
