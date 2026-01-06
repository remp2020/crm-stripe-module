<?php

namespace Crm\StripeModule\Presenters;

use Crm\ApplicationModule\Presenters\FrontendPresenter;

class CheckoutPresenter extends FrontendPresenter
{
    public function __construct(
        private readonly PaymentsRepository $paymentsRepository,
        private readonly AccessToken $accessToken,
        private readonly StripeService $stripeService,
        private readonly GatewayFactory $gatewayFactory,
    ) {
        parent::__construct();
    }

    public function renderRedirect(string $checkoutSessionId)
    {
        $checkoutSession = $this->stripeService->retrieveCheckoutSession($checkoutSessionId);
        $this->template->checkoutUrl = $checkoutSession->url;
    }
}
