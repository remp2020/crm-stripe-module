<?php

namespace Crm\StripeModule\Presenters;

use Crm\ApplicationModule\Presenters\FrontendPresenter;

class CheckoutPresenter extends FrontendPresenter
{
    public function renderRedirect($checkoutSessionId)
    {
        $this->template->stripePublishable = $this->applicationConfig->get('stripe_publishable');
        $this->template->checkoutSessionId = $checkoutSessionId;
    }
}
