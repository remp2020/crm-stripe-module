<?php

namespace Crm\StripeModule\Gateways;

use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\PaymentsModule\Models\GatewayFail;
use Crm\PaymentsModule\Models\Gateways\GatewayAbstract;
use Crm\PaymentsModule\Models\Gateways\RefundableInterface;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\StripeModule\Models\PaymentMeta;
use Crm\StripeModule\Models\StripeService;
use Crm\StripeModule\Repositories\StripeCheckoutSessionsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Nette\Application\LinkGenerator;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Response;
use Nette\Localization\Translator;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class AbstractStripe extends GatewayAbstract implements RefundableInterface
{
    use RefundTrait;

    public const GATEWAY_CODE = 'stripe';

    protected PaymentIntent $paymentIntent;

    protected StripeClient $stripeClient;

    public function __construct(
        LinkGenerator $linkGenerator,
        ApplicationConfig $applicationConfig,
        Response $httpResponse,
        Translator $translator,
        protected readonly PaymentMetaRepository $paymentMetaRepository,
        protected readonly UserMetaRepository $userMetaRepository,
        protected readonly SubscriptionTypeItemsRepository $subscriptionTypeItemsRepository,
        protected readonly StripeService $stripeService,
        protected readonly StripeCheckoutSessionsRepository $stripeCheckoutSessionsRepository,
    ) {
        parent::__construct($linkGenerator, $applicationConfig, $httpResponse, $translator);
    }

    protected function initialize(): void
    {
        $secret = $this->applicationConfig->get('stripe_secret');
        if (!$secret) {
            throw new \Exception('Unable to initialize stripe gateway, secret is missing from CRM Admin configuration');
        }

        $this->stripeClient = new StripeClient($this->applicationConfig->get('stripe_secret'));
    }

    public function begin($payment)
    {
        $this->processCheckout($payment, 'on_session');
    }

    protected function processCheckout($payment, $futureUsage = 'on_session')
    {
        $returnUrl = $this->generateReturnUrl($payment, [
            'vs' => $payment->variable_symbol,
        ]);

        $checkoutSession = $this->stripeService->createPaymentCheckoutSession(
            payment: $payment,
            returnUrl: $returnUrl,
            setupFutureUsage: $futureUsage,
        );

        $this->stripeCheckoutSessionsRepository->add(
            subscriptionType: $payment->subscription_type,
            user: $payment->user,
            payment: $payment,
            checkoutSessionId: $checkoutSession->id,
            reference: $checkoutSession->client_reference_id,
        );

        $this->httpResponse->redirect($checkoutSession->url);
        exit();
    }

    protected function processSetupIntent(string $paymentMethodId, ActiveRow $payment, string $futureUsage = 'on_session')
    {
        $returnUrl = $this->generateReturnUrl($payment, [
            'vs' => $payment->variable_symbol,
        ]);

        try {
            $stripeCustomer = $this->stripeService->getCustomerByUser($payment->user);

            $stripePaymentMethod = $this->stripeService->retrievePaymentMethod($paymentMethodId);
            $stripePaymentMethod->attach(['customer' => $stripeCustomer->id]);

            $this->paymentIntent = $this->stripeService->createPaymentIntent(
                paymentMethod: $stripePaymentMethod,
                customer: $stripeCustomer,
                amount: (float) $payment->amount,
                setupFutureUsage: $futureUsage,
                returnUrl: $this->generateReturnUrl($payment, [
                    'vs' => $payment->variable_symbol,
                ]),
            );
        } catch (CardException $e) {
            if (isset($e->getError()->payment_intent)) {
                // the confirmation part failed, let's extract errors
                $paymentIntentId = $e->getError()->payment_intent->id;
                $this->paymentIntent = $this->stripeService->retrievePaymentIntent($paymentIntentId);
            }
        }

        if (!isset($this->paymentIntent)) {
            // if we don't have paymentIntent by this time, redirect away so it can fail gracefully
            $this->httpResponse->redirect($returnUrl);
            exit();
        }

        $this->paymentMetaRepository->add($payment, PaymentMeta::PAYMENT_INTENT_ID, $this->paymentIntent->id);

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
        $paymentIntentId = $this->paymentMetaRepository->values($payment, PaymentMeta::PAYMENT_INTENT_ID)->fetch()?->value;
        if ($paymentIntentId) {
            $this->paymentIntent = $this->stripeService->retrievePaymentIntent($paymentIntentId);
        }

        if (!isset($this->paymentIntent)) {
            $checkoutSessionRow = $this->stripeCheckoutSessionsRepository->findByPayment($payment);
            if ($checkoutSessionRow) {
                $checkoutSession = $this->stripeService->retrieveCheckoutSession($checkoutSessionRow->checkout_session_id);
                $this->paymentIntent = $checkoutSession->payment_intent;
            }
        }

        if (!isset($this->paymentIntent)) {
            return false;
        }

        return $this->paymentIntent->status === PaymentIntent::STATUS_SUCCEEDED;
    }
}
