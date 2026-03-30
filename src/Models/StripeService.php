<?php

declare(strict_types=1);

namespace Crm\StripeModule\Models;

use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Number;
use Money\Parser\DecimalMoneyParser;
use Nette\Database\Table\ActiveRow;
use Stripe\Checkout\Session;
use Stripe\Collection;
use Stripe\Customer;
use Stripe\Invoice;
use Stripe\InvoicePayment;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Refund;
use Stripe\SetupIntent;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\TaxRate;

class StripeService
{
    private Currency $currency;

    public function __construct(
        private readonly ApplicationConfig $applicationConfig,
        private readonly UserMetaRepository $userMetaRepository,
    ) {
    }

    public function getCustomerByEmail(string $email): Customer
    {
        $stripeCustomer = $this->getClient()->customers->all([
            'email' => $email,
            'limit' => 1,
        ])->first();

        if (!$stripeCustomer) {
            $stripeCustomer = $this->createCustomer($email);
        }

        return $stripeCustomer;
    }

    public function getCustomerByUser(ActiveRow $user): Customer
    {
        $stripeCustomerId = $this->userMetaRepository->userMetaValueByKey($user, UserMeta::STRIPE_CUSTOMER_ID);
        if ($stripeCustomerId) {
            return $this->getClient()->customers->retrieve($stripeCustomerId);
        }

        $stripeCustomer = $this->getClient()->customers->all([
            'email' => $user->email,
            'limit' => 1,
        ])[0] ?? null;

        if (!$stripeCustomer) {
            $stripeCustomer = $this->getCustomerByEmail($user->email);
        }

        $this->userMetaRepository->add(
            user: $user,
            key: UserMeta::STRIPE_CUSTOMER_ID,
            value: $stripeCustomer->id,
        );

        return $stripeCustomer;
    }

    public function getInvoicesBySubscription(Subscription $subscription): array
    {
        $invoices = [];

        $query = $this->getClient()->invoices->all([
            'subscription' => $subscription->id,
            'limit' => 100,
        ]);

        foreach ($query->autoPagingIterator() as $invoice) {
            $invoices[] = $invoice;
        }

        return $invoices;
    }

    public function createCustomer(string $email)
    {
        return $this->getClient()->customers->create([
            'email' => $email,
        ]);
    }

    public function createSetupIntent(): SetupIntent
    {
        return $this->getClient()->setupIntents->create();
    }

    public function createSubscriptionCheckoutSession(Customer $stripeCustomer, string $priceId, string $returnUrl): Session
    {
        $stripeClient = $this->getClient();

        $lineItems = [
            [
                'price' => $priceId,
                'quantity' => 1,
            ],
        ];

        $stripeCheckoutReference = bin2hex(random_bytes(8));

        $checkoutSessionConfig = [
            'customer' => $stripeCustomer->id,
            'mode' => Session::MODE_SUBSCRIPTION,
            'ui_mode' => Session::UI_MODE_EMBEDDED,
            'return_url' => $returnUrl,
            'client_reference_id' => $stripeCheckoutReference,
            'line_items' => $lineItems,
            'automatic_tax' => [
                'enabled' => true,
            ],
            'subscription_data' => [
                'metadata' => [
                    'client_reference_id' => $stripeCheckoutReference,
                ],
            ],
            'expand' => ['line_items'], // so they're included within the created object
            'customer_update' => [
                'address' => 'auto',
                'shipping' => 'auto',
            ],
        ];

        return $stripeClient->checkout->sessions->create($checkoutSessionConfig);
    }

    public function createPaymentCheckoutSession(
        ActiveRow $payment,
        string $returnUrl,
        string $setupFutureUsage = PaymentIntent::SETUP_FUTURE_USAGE_ON_SESSION,
    ): Session {
        $lineItems = [];
        foreach ($payment->related('payment_items') as $paymentItem) {
            $lineItems[] = [
                'price_data' => [
                    'unit_amount' => $this->calculateStripeAmount((float) $paymentItem->amount),
                    'currency' => $this->getCurrency()->getCode(),
                    'product_data' => [
                        'name' => $paymentItem->name,
                    ],
                ],
                'quantity' => $paymentItem->count,
            ];
        }

        $customer = $this->getCustomerByUser($payment->user);
        $stripeCheckoutReference = bin2hex(random_bytes(8));

        return $this->getClient()->checkout->sessions->create([
            'payment_method_types' => [PaymentMethod::TYPE_CARD],
            'mode' => Session::MODE_PAYMENT,
            'customer' => $customer->id,
            'client_reference_id' => $stripeCheckoutReference,
            'payment_intent_data' => [
                'setup_future_usage' => $setupFutureUsage,
                'capture_method' => PaymentIntent::CAPTURE_METHOD_AUTOMATIC,
            ],
            'line_items' => $lineItems,
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
        ]);
    }

    public function createPaymentIntent(
        PaymentMethod $paymentMethod,
        Customer $customer,
        float $amount,
        ?string $setupFutureUsage = null,
        bool $offSession = false,
        ?string $returnUrl = null,
    ): PaymentIntent {
        $params = [
            'amount' => $this->calculateStripeAmount($amount),
            'currency' => $this->getCurrency()->getCode(),
            'customer' => $customer->id,
            'payment_method' => $paymentMethod->id,
            'off_session' => $offSession,
            'confirm' => true,
            'capture_method' => PaymentIntent::CAPTURE_METHOD_AUTOMATIC,
            'confirmation_method' => PaymentIntent::CONFIRMATION_METHOD_AUTOMATIC,
        ];

        if (isset($setupFutureUsage)) {
            $params['setup_future_usage'] = $setupFutureUsage;
        }
        if (isset($returnUrl)) {
            $params['return_url'] = $returnUrl;
        }

        return $this->getClient()->paymentIntents->create($params);
    }

    public function createRefund(PaymentIntent $paymentIntent, float $amount): Refund
    {
        return $this->getClient()->refunds->create([
            'payment_intent' => $paymentIntent->id,
            'amount' => $this->calculateStripeAmount($amount),
        ]);
    }

    public function retrieveCustomer(string $customerId): Customer
    {
        return $this->getClient()->customers->retrieve($customerId, [
            'expand' => [
                'invoice_settings.default_payment_method',
                'default_source',
            ],
        ]);
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

    public function retrieveInvoice(string $invoiceId): Invoice
    {
        return $this->getClient()->invoices->retrieve($invoiceId, [
            'expand' => [
                'customer',
            ],
        ]);
    }

    /**
     * @return Collection<InvoicePayment>
     */
    public function retrieveInvoicePayments(Invoice $invoice): Collection
    {
        return $this->getClient()->invoicePayments->all([
            'invoice' => $invoice->id,
        ]);
    }

    public function retrievePaymentMethod(string $paymentMethodId): PaymentMethod
    {
        return $this->getClient()->paymentMethods->retrieve($paymentMethodId);
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->getClient()->paymentIntents->retrieve($paymentIntentId);
    }

    public function retrieveTaxRate(string $taxRateId): TaxRate
    {
        return $this->getClient()->taxRates->retrieve($taxRateId);
    }

    public function cancelSubscription(string $subscriptionId): Subscription
    {
        $stripeSubscription = $this->retrieveSubscription($subscriptionId);
        if ($stripeSubscription->schedule) {
            $this->getClient()->subscriptionSchedules->release($stripeSubscription->schedule);
        }

        return $this->getClient()->subscriptions->update($subscriptionId, [
            'cancel_at_period_end' => true,
        ]);
    }

    public function getPaymentForCheckoutSession(ActiveRow $checkoutSessionRow): ActiveRow
    {
        if (!isset($checkoutSessionRow->payment)) {
            throw new RuntimeException("Support for confirmation of checkout sessions without payments hasn't been implemented yet.");
        }

        return $checkoutSessionRow->payment;
    }

    protected function calculateStripeAmount(float $amount): string
    {
        $moneyParser = new DecimalMoneyParser(new ISOCurrencies());
        $number = Number::fromFloat($amount);
        $money = $moneyParser->parse((string) $number, $this->getCurrency());
        return $money->getAmount();
    }

    protected function getClient(): StripeClient
    {
        $secret = $this->applicationConfig->get('stripe_secret');
        if (!$secret) {
            throw new \Exception('Unable to initialize stripe gateway, secret is missing from CRM Admin configuration');
        }

        return new StripeClient($this->applicationConfig->get('stripe_secret'));
    }

    protected function getCurrency(): Currency
    {
        if (!isset($this->currency)) {
            $this->currency = new Currency($this->applicationConfig->get('currency'));
        }

        return $this->currency;
    }
}
