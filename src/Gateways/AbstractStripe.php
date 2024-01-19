<?php

namespace Crm\StripeModule\Gateways;

use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\PaymentsModule\Models\GatewayFail;
use Crm\PaymentsModule\Models\Gateways\GatewayAbstract;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Number;
use Money\Parser\DecimalMoneyParser;
use Nette\Application\LinkGenerator;
use Nette\Http\Response;
use Nette\Localization\Translator;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\Stripe;

class AbstractStripe extends GatewayAbstract
{
    public const GATEWAY_CODE = 'stripe';

    /** @var PaymentIntent */
    protected $paymentIntent;

    protected $paymentMetaRepository;

    protected $userMetaRepository;

    public function __construct(
        LinkGenerator $linkGenerator,
        ApplicationConfig $applicationConfig,
        Response $httpResponse,
        Translator $translator,
        PaymentMetaRepository $paymentMetaRepository,
        UserMetaRepository $userMetaRepository
    ) {
        parent::__construct($linkGenerator, $applicationConfig, $httpResponse, $translator);
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->userMetaRepository = $userMetaRepository;
    }

    protected function initialize()
    {
        $secret = $this->applicationConfig->get('stripe_secret');
        if (!$secret) {
            throw new \Exception('Unable to initialize stripe gateway, secret is missing from CRM Admin configuration');
        }
        Stripe::setApiKey($this->applicationConfig->get('stripe_secret'));
    }

    public function begin($payment)
    {
        $this->processCheckout($payment, 'on_session');
    }

    protected function processCheckout($payment, $futureUsage = 'on_session')
    {
        $this->initialize();

        $returnUrl = $this->generateReturnUrl($payment, [
            'vs' => $payment->variable_symbol,
        ]);

        $lineItems = [];
        $currency = new Currency($this->applicationConfig->get('currency'));
        foreach ($payment->related('payment_items') as $paymentItem) {
            $lineItems[] = [
                'price_data' => [
                    'unit_amount' => $this->calculateStripeAmount($paymentItem->amount, $currency),
                    'currency' => $currency->getCode(),
                    'product_data' => [
                        'name' => $paymentItem->name,
                    ],
                ],
                'quantity' => $paymentItem->count,
            ];
        }

        $checkoutSessionConfig = [
            'payment_method_types' => ['card'],
            'mode' => 'payment',
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'client_reference_id' => $payment->variable_symbol,
            'line_items' => $lineItems,
            'payment_intent_data' => [
                'setup_future_usage' => $futureUsage,
                'capture_method' => 'automatic',
            ],
        ];

        $stripeCustomerId = $this->userMetaRepository->userMetaValueByKey($payment->user, 'stripe_customer');
        if ($stripeCustomerId) {
            $checkoutSessionConfig['customer'] = $stripeCustomerId;
        } elseif (isset($payment->user->email)) {
            $checkoutSessionConfig['customer_email'] = $payment->user->email;
        }

        $checkoutSession = Session::create($checkoutSessionConfig);

        // create payment intent
        $this->paymentIntent = PaymentIntent::retrieve($checkoutSession->payment_intent);
        $this->paymentMetaRepository->add($payment, 'payment_intent_id', $this->paymentIntent->id);

        // redirect to page, where JS library redirects user to Stripe Checkout page
        $this->httpResponse->redirect($this->linkGenerator->link('Stripe:Checkout:Redirect', ['checkoutSessionId' => $checkoutSession->id]));
        exit();
    }

    protected function processSetupIntent($paymentMethodId, $payment, $futureUsage = 'on_session')
    {
        $returnUrl = $this->generateReturnUrl($payment, [
            'vs' => $payment->variable_symbol,
        ]);

        try {
            // attach or create stripe customer to payment method
            $stripeCustomerId = $this->userMetaRepository->userMetaValueByKey($payment->user, 'stripe_customer');
            if ($stripeCustomerId) {
                $paymentMethod = PaymentMethod::retrieve($paymentMethodId);
                $paymentMethod->attach(['customer' => $stripeCustomerId]);
            } else {
                $payload = [
                    'payment_method' => $paymentMethodId,
                ];

                if (isset($payment->user->email)) {
                    $payload['email'] = $payment->user->email;
                }

                $cardholderName = $this->paymentMetaRepository->values($payment, 'cardholder_name')->fetch()?->value;
                if ($cardholderName) {
                    $payload['name'] = $cardholderName;
                }
                $customer = Customer::create($payload);
                $stripeCustomerId = $customer->id;
                $this->userMetaRepository->add($payment->user, 'stripe_customer', $stripeCustomerId);
            }

            // create payment intent instance for charging
            $currency = new Currency($this->applicationConfig->get('currency'));
            $this->paymentIntent = PaymentIntent::create([
                'amount' => $this->calculateStripeAmount($payment->amount, $currency),
                'currency' => $currency->getCode(),
                'customer' => $stripeCustomerId,
                'payment_method' => $paymentMethodId,
                'setup_future_usage' => $futureUsage,
                'confirmation_method' => 'automatic',
                'capture_method' => 'automatic',
                'return_url' => $this->generateReturnUrl($payment, [
                    'vs' => $payment->variable_symbol,
                ]),
                'confirm' => true,
            ]);
        } catch (CardException $e) {
            if (isset($e->getError()->payment_intent)) {
                // the confirmation part failed, let's extract errors
                $paymentIntentId = $e->getError()->payment_intent->id;
                $this->paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            }
        }

        if (!$this->paymentIntent) {
            // if we don't have paymentIntent by this time, redirect away so it can fail gracefully
            $this->httpResponse->redirect($returnUrl);
            exit();
        }

        $this->paymentMetaRepository->add($payment, 'payment_intent_id', $this->paymentIntent->id);

        // redirect required
        if ($this->paymentIntent->status === PaymentIntent::STATUS_REQUIRES_ACTION) {
            if ($this->paymentIntent->next_action['type'] === 'redirect_to_url') {
                $this->httpResponse->redirect($this->paymentIntent->next_action['redirect_to_url']['url']);
                exit();
            }
            throw new GatewayFail('Unable to proceed with payment, unsupported next action type: ' . $this->paymentIntent->next_action);
        }

        // direct confirmation
        if ($this->paymentIntent->status === PaymentIntent::STATUS_SUCCEEDED) {
            $this->httpResponse->redirect($returnUrl);
            exit();
        }

        // ouch, shouldn't get here
        throw new GatewayFail('Unhandled stripe payment status: ' . $this->paymentIntent->status);
    }

    public function complete($payment): ?bool
    {
        $this->initialize();

        $paymentIntentId = $this->paymentMetaRepository->values($payment, 'payment_intent_id')->fetch()?->value;
        if (!$paymentIntentId) {
            return false;
        }
        $this->paymentIntent = PaymentIntent::retrieve($paymentIntentId);

        if ($this->paymentIntent->payment_method) {
            $stripeCustomerId = $this->userMetaRepository->userMetaValueByKey($payment->user, 'stripe_customer');
            if (!$stripeCustomerId) {
                $paymentMethod = PaymentMethod::retrieve($this->paymentIntent->payment_method);
                $this->userMetaRepository->add($payment->user, 'stripe_customer', $paymentMethod->customer);
            }
        }

        return $this->paymentIntent->status === PaymentIntent::STATUS_SUCCEEDED;
    }

    public function createSetupIntent()
    {
        $this->initialize();
        return SetupIntent::create();
    }

    protected function calculateStripeAmount($amount, $currency): string
    {
        $moneyParser = new DecimalMoneyParser(new ISOCurrencies());
        $number = Number::fromFloat($amount);
        $money = $moneyParser->parse((string) $number, $currency);
        return $money->getAmount();
    }
}
