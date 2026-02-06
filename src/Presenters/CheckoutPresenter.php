<?php

namespace Crm\StripeModule\Presenters;

use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\PaymentsModule\Models\GatewayFactory;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\StripeModule\Gateways\StripeBillingRecurrent;
use Crm\StripeModule\Models\StripeService;
use Crm\UsersModule\Models\Auth\Access\AccessToken;
use Kdyby\Autowired\Attributes\Autowire;
use Kdyby\Autowired\AutowireProperties;
use Stripe\Checkout\Session;

class CheckoutPresenter extends FrontendPresenter
{
    use AutowireProperties;

    #[Autowire]
    public PaymentsRepository $paymentsRepository;

    #[Autowire]
    public AccessToken $accessToken;

    #[Autowire]
    public StripeService $stripeService;

    #[Autowire]
    public GatewayFactory $gatewayFactory;

    public function startup()
    {
        parent::startup();

        if ($this->layoutManager->exists($this->getLayoutName() . '_plain')) {
            $this->setLayout($this->getLayoutName() . '_plain');
        } else {
            $this->setLayout('plain');
        }
    }

    public function renderRedirect(string $checkoutSessionId)
    {
        $checkoutSession = $this->stripeService->retrieveCheckoutSession($checkoutSessionId);
        $this->template->checkoutUrl = $checkoutSession->url;
    }

    public function renderPay($vs)
    {
        $payment = $this->paymentsRepository->findLastByVS($vs);
        if (!$payment) {
            throw new \Exception("Payment with VS '{$vs}' not found'");
        }

        if ($this->getUser()->isLoggedIn() && $this->getUser()->getId() !== $payment->user_id) {
            throw new \Exception("Payment with VS '{$vs}' is not payment of user '{$this->getUser()->getId()}'");
        }

        if ($payment->status !== PaymentStatusEnum::Form->value) {
            $this->redirect(':Payments:Return:gateway', [
                'gatewayCode' => StripeBillingRecurrent::GATEWAY_CODE,
                'VS' => $payment->variable_symbol,
            ]);
        }

        $stripePublishableKey = $this->applicationConfig->get('stripe_publishable');

        $this->template->accessToken = $this->accessToken->getToken($this->getHttpRequest());
        $this->template->subscriptionType = $payment->subscription_type;
        $this->template->payment = $payment;
        $this->template->stripePublishableKey = $stripePublishableKey;
    }

    public function renderReturn($id)
    {
        $checkoutSession = $this->stripeService->retrieveCheckoutSession($id);

        if ($checkoutSession->status !== Session::STATUS_COMPLETE) {
            // TODO: display page that refreshes itself every N seconds
        }

        /** @var StripeBillingRecurrent $gateway */
        $gateway = $this->gatewayFactory->getGateway(StripeBillingRecurrent::GATEWAY_CODE);
        $payment = $gateway->getPaymentForCheckoutSession($checkoutSession);

        $this->redirect(':Payments:Return:gateway', [
            'gatewayCode' => StripeBillingRecurrent::GATEWAY_CODE,
            'VS' => $payment->variable_symbol,
        ]);
    }
}
