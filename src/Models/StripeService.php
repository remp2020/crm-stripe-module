<?php

declare(strict_types=1);

namespace Crm\StripeModule\Models;

use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Nette\Database\Table\ActiveRow;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\Subscription;

class StripeService
{
    public function __construct(
        private readonly ApplicationConfig $applicationConfig,
        private readonly UserMetaRepository $userMetaRepository,
    ) {
    }

    public function getStripeCustomerByEmail(string $email): Customer
    {
        $stripeCustomer = $this->getClient()->customers->all([
            'email' => $email,
            'limit' => 1,
        ])->first();

        if (!$stripeCustomer) {
            $stripeCustomer = $this->getClient()->customers->create([
                'email' => $email,
            ]);
        }

        return $stripeCustomer;
    }

    public function getStripeCustomerByUser(ActiveRow $user): Customer
    {
        $stripeCustomerId = $this->userMetaRepository->userMetaValueByKey($user, 'stripe_customer_id');
        if ($stripeCustomerId) {
            return $this->getClient()->customers->retrieve($stripeCustomerId);
        }

        $stripeCustomer = $this->getClient()->customers->all([
            'email' => $user->email,
            'limit' => 1,
        ])[0] ?? null;

        if (!$stripeCustomer) {
            $stripeCustomer = $this->getStripeCustomerByEmail($user->email);
        }

        if (!$stripeCustomer) {
            $stripeCustomer = $this->getClient()->customers->create([
                'email' => $user->email,
            ]);
        }

        $this->userMetaRepository->add(
            user: $user,
            key: UserMeta::STRIPE_CUSTOMER_ID,
            value: $stripeCustomer->id,
        );

        return $stripeCustomer;
    }

    public function createSubscriptionCheckoutSession(Customer $stripeCustomer, string $priceId, string $returnUrl): Session
    {
        $stripeClient = $this->getClient();

        $lineItems[] = [
            'price' => $priceId,
            'quantity' => 1,
        ];

        $stripeCheckoutReference = bin2hex(random_bytes(8));

        $checkoutSessionConfig = [
            'customer' => $stripeCustomer->id,
            'payment_method_types' => ['card'],
            'mode' => 'subscription',
            'ui_mode' => 'embedded',
            'return_url' => $returnUrl,
            'client_reference_id' => $stripeCheckoutReference,
            'line_items' => $lineItems,
            'subscription_data' => [
                'metadata' => [
                    'client_reference_id' => $stripeCheckoutReference,
                ],
            ],
            'expand' => ['line_items'], // so they're included within the created object
        ];

        return $stripeClient->checkout->sessions->create($checkoutSessionConfig);
    }

    public function retrieveCheckoutSession(string $checkoutSessionId): Session
    {
        return $this->getClient()->checkout->sessions->retrieve($checkoutSessionId, [
            'expand' => [
                'line_items',
                'invoice',
                'customer_details',
                'subscription',
                'payment_intent.payment_method',
            ],
        ]);
    }

    public function retrieveSubscription(string $subscriptionId): Subscription
    {
        return $this->getClient()->subscriptions->retrieve($subscriptionId, [
            'expand' => [
                'default_payment_method.card',
                'latest_invoice',
            ],
        ]);
    }

    public function cancelSubscription(string $subscriptionId): Subscription
    {
        return $this->getClient()->subscriptions->cancel($subscriptionId);
    }

    public function retrieveInvoice(string $invoiceId): Invoice
    {
        return $this->getClient()->invoices->retrieve($invoiceId, [
            'expand' => [
                'customer',
            ],
        ]);
    }

    public function getPaymentForCheckoutSession(ActiveRow $checkoutSessionRow): ActiveRow
    {
        if (!isset($checkoutSessionRow->payment)) {
            throw new RuntimeException("Support for confirmation of checkout sessions without payments hasn't been implemented yet.");
        }

        return $checkoutSessionRow->payment;
    }

    protected function getClient(): StripeClient
    {
        $secret = $this->applicationConfig->get('stripe_secret');
        if (!$secret) {
            throw new \Exception('Unable to initialize stripe gateway, secret is missing from CRM Admin configuration');
        }

        return new StripeClient($this->applicationConfig->get('stripe_secret'));
    }
}
