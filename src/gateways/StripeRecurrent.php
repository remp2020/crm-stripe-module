<?php

namespace Crm\StripeModule\Gateways;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\PaymentsModule\GatewayFail;
use Crm\PaymentsModule\Gateways\GatewayAbstract;
use Crm\PaymentsModule\Gateways\RecurrentPaymentInterface;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\UsersModule\Repository\UserMetaRepository;
use Money\Currencies\ISOCurrencies;
use Money\Number;
use Money\Parser\DecimalMoneyParser;
use Nette\Application\LinkGenerator;
use Nette\Http\Response;
use Nette\Localization\ITranslator;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Omnipay;
use Omnipay\Stripe\PaymentIntentsGateway;
use Stripe\ErrorObject;
use Stripe\PaymentIntent;

class StripeRecurrent extends GatewayAbstract implements RecurrentPaymentInterface
{
    const GATEWAY_CODE = 'stripe_recurrent';

    /** @var PaymentIntentsGateway */
    protected $gateway;

    private $paymentMetaRepository;

    private $userMetaRepository;

    /** @var PaymentIntent */
    private $paymentIntent;

    /** @var ErrorObject */
    private $paymentIntentError;

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
        $secret = $this->applicationConfig->get('stripe_secret');

        // for communication vie Omnipay library (standard payments)
        $this->gateway = Omnipay::create('Stripe\PaymentIntents');
        $this->gateway->setApiKey($secret);

        // for communication via Stripe library (recurring payments)
        \Stripe\Stripe::setApiKey($secret);
    }

    public function begin($payment)
    {
        $this->initialize();

        $paymentMethodId = $this->paymentMetaRepository->values($payment, 'payment_method_id')->fetchField('value');
        $returnUrl = $this->generateReturnUrl($payment, [
            'vs' => $payment->variable_symbol,
        ]);

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

        try {
            $this->paymentIntent = PaymentIntent::create([
                'amount' => $this->getAmount($payment),
                'currency' => $this->applicationConfig->get('currency'),
                'customer' => $stripeCustomerId,
                'payment_method' => $paymentMethodId,
                'setup_future_usage' => 'off_session',
                'confirmation_method' => 'automatic',
                'capture_method' => 'automatic',
                'return_url' => $this->generateReturnUrl($payment, [
                    'vs' => $payment->variable_symbol,
                ]),
                'confirm' => true,
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            $paymentIntentId = $e->getError()->payment_intent->id;
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
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
        return $this->paymentIntent->status === PaymentIntent::STATUS_SUCCEEDED;
    }

    public function createSetupIntent()
    {
        $this->initialize();
        return \Stripe\SetupIntent::create();
    }

    public function charge($payment, $token)
    {
        $this->initialize();

        $stripeCustomerId = $this->userMetaRepository->userMetaValueByKey($payment->user, 'stripe_customer');

        try {
            $this->paymentIntent = PaymentIntent::create([
                'amount' => $this->getAmount($payment),
                'currency' => $this->applicationConfig->get('currency'),
                'customer' => $stripeCustomerId,
                'payment_method' => $token,
                'confirm' => true,
                'off_session' => true,
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            $this->paymentIntentError = $e->getError();
            $paymentIntentId = $e->getError()->payment_intent->id;
            $this->paymentIntent = PaymentIntent::retrieve($paymentIntentId);
        }

        return $this->paymentIntent->status === PaymentIntent::STATUS_SUCCEEDED;
    }

    /**
     * @inheritDoc
     */
    public function checkValid($token)
    {
        throw new InvalidRequestException("stripe recurrent gateway doesn't support checking if token is still valid");
    }

    /**
     * @inheritDoc
     */
    public function checkExpire($recurrentPayments)
    {
        throw new InvalidRequestException("stripe recurrent gateway doesn't support token expiration checking (it should never expire)");
    }

    /**
     * @inheritDoc
     */
    public function hasRecurrentToken(): bool
    {
        return isset($this->paymentIntent->payment_method);
    }

    /**
     * @inheritDoc
     */
    public function getRecurrentToken()
    {
        return $this->paymentIntent->payment_method;
    }

    /**
     * @inheritDoc
     */
    public function getResultCode()
    {
        if (isset($this->paymentIntent->last_payment_error['code'])) {
            return sprintf('%s: %s', $this->paymentIntent->last_payment_error['code'], $this->paymentIntent->last_payment_error['decline_code']);
        }
        if (isset($this->paymentIntentError)) {
            return $this->paymentIntentError->message;
        }
        return $this->paymentIntent->status;
    }

    /**
     * @inheritDoc
     */
    public function getResultMessage()
    {
        if (isset($this->paymentIntent->last_payment_error['message'])) {
            return $this->paymentIntent->last_payment_error['message'];
        }
        if (isset($this->paymentIntentError)) {
            return $this->paymentIntentError->message;
        }
        return $this->paymentIntent->status;
    }

    private function getAmount($payment): int
    {
        $moneyParser = new DecimalMoneyParser(new ISOCurrencies());
        $currency = $this->applicationConfig->get('currency');
        $number = Number::fromFloat($payment->amount);
        $money = $moneyParser->parse((string) $number, $currency);
        return $money->getAmount();
    }
}
