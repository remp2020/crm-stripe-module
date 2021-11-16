<?php

namespace Crm\StripeModule\Presenters;

use Crm\ApplicationModule\ActiveRow;
use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\StripeModule\Gateways\StripeWallet;
use Crm\StripeModule\Models\StripeWalletClient;
use Crm\UsersModule\Repository\CountriesRepository;
use Nette\Application\BadRequestException;
use Stripe\PaymentIntent;

class StripeWalletPresenter extends FrontendPresenter
{
    /** @var PaymentsRepository @inject */
    public PaymentsRepository $paymentsRepository;

    /** @var CountriesRepository @inject */
    public CountriesRepository $countriesRepository;

    /** @var StripeWalletClient @inject */
    public StripeWalletClient $stripeWalletClient;

    public function renderDefault($id)
    {
        $payment = $this->getPayment($id);
        $intent = $this->createIntent($payment, $this->applicationConfig->get('currency'));

        $displayItems = [];
        foreach ($payment->related('payment_items') as $item) {
            $displayItems[] = [
                "label" => $item->name,
                "amount" => $item->amount * 100,
            ];
        }

        $this->template->setParameters([
            'countryCode' => $this->countriesRepository->defaultCountry()->iso_code,
            'currencyCode' => $this->applicationConfig->get('currency'),
            'paymentIntentSecret' => $intent->client_secret,
            'stripePublishableKey' => $this->applicationConfig->get('stripe_publishable'),
            'payment' => $payment,
            'displayName' => $this->applicationConfig->get('stripe_wallet_display_name'),
            'displayItems' => $displayItems,
            'confirmUrl' => $this->link("confirm", $payment->variable_symbol, $intent->id),
        ]);
    }

    private function createIntent(ActiveRow $payment, string $paymentType): PaymentIntent
    {
        $intent = $this->stripeWalletClient->createIntent($payment);
        $this->stripeWalletClient->linkPaymentWithIntent($payment, $intent->id);
        return $intent;
    }

    private function getPayment(int $id): ActiveRow
    {
        $payment = $this->paymentsRepository->findLastByVS($id);
        if (!$payment) {
            throw new BadRequestException('Payment with variable symbol not found: ' . $id);
        }

        if ($payment->payment_gateway->code !== StripeWallet::GATEWAY_CODE) {
            throw new BadRequestException('Payment with variable symbol ' . $id . ' has payment gateway ' . $payment->payment_gateway->code . ' instead of ' . StripeWallet::GATEWAY_CODE);
        }

        return $payment;
    }

    public function renderConfirm(string $id, string $intent)
    {
        $payment = $this->paymentsRepository->findByVs($id);

        if (!$payment) {
            $this->flashMessage($this->translator->translate("stripe.frontend.default.previous_payment_failed"), "alert");
            $this->redirect('default', $id);
        }

        if ($payment->payment_gateway->code !== StripeWallet::GATEWAY_CODE) {
            $this->flashMessage($this->translator->translate("stripe.frontend.default.previous_payment_failed"), "alert");
            $this->redirect('default', $id);
        }

        if ($payment->status !== PaymentsRepository::STATUS_FORM) {
            $this->flashMessage($this->translator->translate("stripe.frontend.default.previous_payment_failed"), "alert");
            $this->redirect('default', $id);
        }

        if (!$this->stripeWalletClient->validIntentForPayment($payment, $intent)) {
            $this->flashMessage($this->translator->translate("stripe.frontend.default.previous_payment_failed"), "alert");
            $this->redirect('default', $id);
        }

        if (!$this->stripeWalletClient->isIntentPaid($intent)) {
            $this->flashMessage($this->translator->translate("stripe.frontend.default.previous_payment_failed"), "alert");
            $this->redirect('default', $id);
        }

        $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PAID, true);

        $this->redirect(':SalesFunnel:SalesFunnel:success', ['variableSymbol' => $payment->variable_symbol]);
    }
}