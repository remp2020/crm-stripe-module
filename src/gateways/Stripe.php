<?php

namespace Crm\StripeModule\Gateways;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\PaymentsModule\Gateways\GatewayAbstract;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Nette\Application\LinkGenerator;
use Nette\Http\Response;
use Nette\Localization\ITranslator;
use Omnipay\Omnipay;
use Omnipay\Stripe\Gateway;
use Omnipay\Stripe\Message\PaymentIntents\PurchaseRequest;

class Stripe extends GatewayAbstract
{
    const GATEWAY_CODE = 'stripe';

    /** @var Gateway */
    protected $gateway;

    private $paymentMetaRepository;

    public function __construct(
        LinkGenerator $linkGenerator,
        ApplicationConfig $applicationConfig,
        Response $httpResponse,
        ITranslator $translator,
        PaymentMetaRepository $paymentMetaRepository
    ) {
        parent::__construct($linkGenerator, $applicationConfig, $httpResponse, $translator);
        $this->paymentMetaRepository = $paymentMetaRepository;
    }

    protected function initialize()
    {
        $secret = $this->applicationConfig->get('stripe_secret');

        // for communication vie Omnipay library (standard payments)
        $this->gateway = Omnipay::create('Stripe\PaymentIntents');
        $this->gateway->setApiKey($secret);
    }

    public function begin($payment)
    {
        $this->initialize();

        $paymentMethodId = $this->paymentMetaRepository->values($payment, 'payment_method_id')->fetchField('value');
        $returnUrl = $this->generateReturnUrl($payment, [
            'vs' => $payment->variable_symbol,
        ]);

        /** @var PurchaseRequest $purchaseRequest */
        $purchaseRequest = $this->gateway->purchase();
        $purchaseRequest
            ->setMetadata([
                'variable_symbol' => $payment->variable_symbol,
            ])
            ->setPaymentMethod($paymentMethodId)
            ->setAmount($payment->amount)
            ->setCurrency($this->applicationConfig->get('currency'))
            ->setReturnUrl($returnUrl)
            ->setConfirm(true);

        $this->response = $purchaseRequest->send();
        $this->paymentMetaRepository->add($payment, 'payment_intent_reference', $this->response->getPaymentIntentReference());

        // direct charge, proceed to redirect URL
        if ($this->response->isSuccessful()) {
            $this->httpResponse->redirect($returnUrl);
        }

        // redirect to bank required
        $this->response->redirect();
    }

    public function complete($payment): ?bool
    {
        $this->initialize();
        $paymentIntentReference = $this->paymentMetaRepository->values($payment, 'payment_intent_reference')->fetchField('value');

        // check if payment intent has been confirmed
        $this->response = $this->gateway->fetchPaymentIntent([
            'paymentIntentReference' => $paymentIntentReference,
        ])->send();
        if ($this->response->isSuccessful()) {
            return true;
        }

        // otherwise let's try to complete the intent
        $this->response = $this->gateway->completePurchase([
            'paymentIntentReference' => $paymentIntentReference,
            'returnUrl' => $this->generateReturnUrl($payment, [
                'vs' => $payment->variable_symbol,
            ]),
        ])->send();

        return $this->response->isSuccessful();
    }
}
