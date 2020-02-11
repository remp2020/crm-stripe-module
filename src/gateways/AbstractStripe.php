<?php

namespace Crm\StripeModule\Gateways;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\PaymentsModule\GatewayFail;
use Crm\PaymentsModule\Gateways\GatewayAbstract;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\UsersModule\Repository\UserMetaRepository;
use Money\Currencies\ISOCurrencies;
use Money\Number;
use Money\Parser\DecimalMoneyParser;
use Nette\Application\LinkGenerator;
use Nette\Http\Response;
use Nette\Localization\ITranslator;
use Stripe\PaymentIntent;

class AbstractStripe extends GatewayAbstract
{
    const GATEWAY_CODE = 'stripe';

    /** @var PaymentIntent */
    protected $paymentIntent;

    protected $paymentMetaRepository;

    protected $userMetaRepository;

    public function __construct(
        LinkGenerator $linkGenerator,
        ApplicationConfig $applicationConfig,
        Response $httpResponse,
        ITranslator $translator,
        PaymentMetaRepository $paymentMetaRepository,
        UserMetaRepository $userMetaRepository
    ) {
        parent::__construct($linkGenerator, $applicationConfig, $httpResponse, $translator);
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->userMetaRepository = $userMetaRepository;
    }

    protected function initialize()
    {
        \Stripe\Stripe::setApiKey($this->applicationConfig->get('stripe_secret'));
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
        foreach ($payment->related('payment_items') as $paymentItem) {
            $lineItems[] = [
                'name' => $paymentItem->name,
                'amount' => $this->calculateStripeAmount($paymentItem->amount * $paymentItem->count, $this->applicationConfig->get('currency')),
                'currency' => $this->applicationConfig->get('currency'),
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
        } else {
            $checkoutSessionConfig['customer_email'] = $payment->user->email;
        }

        $checkoutSession = \Stripe\Checkout\Session::create($checkoutSessionConfig);

        // create payment intent
        $this->paymentIntent = PaymentIntent::retrieve($checkoutSession->payment_intent);
        $this->paymentMetaRepository->add($payment, 'payment_intent_id', $this->paymentIntent->id);

        // redirect to page, where JS library redirects user to Stripe Checkout page
        $this->httpResponse->redirect($this->linkGenerator->link('Stripe:Checkout:Redirect', ['checkoutSessionId' => $checkoutSession->id]));
    }

    protected function processSetupIntent($paymentMethodId, $payment, $futureUsage = 'on_session')
    {
        $returnUrl = $this->generateReturnUrl($payment, [
            'vs' => $payment->variable_symbol,
        ]);

        // attach or create stripe customer to payment method
        $stripeCustomerId = $this->userMetaRepository->userMetaValueByKey($payment->user, 'stripe_customer');
        if ($stripeCustomerId) {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
            $paymentMethod->attach(['customer' => $stripeCustomerId]);
        } else {
            $customer = \Stripe\Customer::create([
                'payment_method' => $paymentMethodId,
            ]);
            $stripeCustomerId = $customer->id;
            $this->userMetaRepository->add($payment->user, 'stripe_customer', $stripeCustomerId);
        }

        // create payment intent instance for charging
        try {
            $this->paymentIntent = PaymentIntent::create([
                'amount' => $this->calculateStripeAmount($payment->amount, $this->applicationConfig->get('currency')),
                'currency' => $this->applicationConfig->get('currency'),
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
        } catch (\Stripe\Exception\CardException $e) {
            // the confirmation part failed, let's extract errors
            $paymentIntentId = $e->getError()->payment_intent->id;
            $this->paymentIntent = PaymentIntent::retrieve($paymentIntentId);
        }

        $this->paymentMetaRepository->add($payment, 'payment_intent_id', $this->paymentIntent->id);

        // redirect required
        if ($this->paymentIntent->status === PaymentIntent::STATUS_REQUIRES_ACTION) {
            if ($this->paymentIntent->next_action['type'] === 'redirect_to_url') {
                $this->httpResponse->redirect($this->paymentIntent->next_action['redirect_to_url']['url']);
            }
            throw new GatewayFail('Unable to proceed with payment, unsupported next action type: ' . $this->paymentIntent->next_action);
        }

        // direct confirmation
        if ($this->paymentIntent->status === PaymentIntent::STATUS_SUCCEEDED) {
            $this->httpResponse->redirect($returnUrl);
        }

        // ouch, shouldn't get here
        throw new GatewayFail('Unhandled stripe payment status: ' . $this->paymentIntent->status);
    }

    public function complete($payment): ?bool
    {
        $this->initialize();

        $paymentIntentId = $this->paymentMetaRepository->values($payment, 'payment_intent_id')->fetchField('value');
        $this->paymentIntent = PaymentIntent::retrieve($paymentIntentId);

        if ($this->paymentIntent->payment_method) {
            $stripeCustomerId = $this->userMetaRepository->userMetaValueByKey($payment->user, 'stripe_customer');
            if (!$stripeCustomerId) {
                $paymentMethod = \Stripe\PaymentMethod::retrieve($this->paymentIntent->payment_method);
                $this->userMetaRepository->add($payment->user, 'stripe_customer', $paymentMethod->customer);
            }
        }

        return $this->paymentIntent->status === PaymentIntent::STATUS_SUCCEEDED;
    }

    public function createSetupIntent()
    {
        $this->initialize();
        return \Stripe\SetupIntent::create();
    }

    protected function calculateStripeAmount($amount, $currency): int
    {
        $moneyParser = new DecimalMoneyParser(new ISOCurrencies());
        $number = Number::fromFloat($amount);
        $money = $moneyParser->parse((string) $number, $currency);
        return $money->getAmount();
    }
}
