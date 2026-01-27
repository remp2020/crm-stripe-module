<?php

namespace Crm\StripeModule\DataProviders;

use Crm\InvoicesModule\Models\BillingDataProviderInterface;
use Crm\PaymentsModule\Models\GatewayFactory;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\StripeModule\Gateways\StripeBillingRecurrent;
use Crm\StripeModule\Models\PaymentMeta;
use Crm\StripeModule\Models\StripeBillingException;
use Crm\StripeModule\Models\StripeService;
use Nette\Application\Response;
use Nette\Application\Responses\RedirectResponse;
use Nette\Database\Table\ActiveRow;

class StripeBillingDataProvider implements BillingDataProviderInterface
{
    public function __construct(
        private readonly GatewayFactory $gatewayFactory,
        private readonly PaymentMetaRepository $paymentMetaRepository,
        private readonly StripeService $stripeService,
    ) {
    }

    public function isAvailable(ActiveRow $payment): bool
    {
        $gateway = $this->gatewayFactory->getGateway($payment->payment_gateway->code);
        return $gateway instanceof StripeBillingRecurrent;
    }

    public function isInvoiceable(ActiveRow $payment): bool
    {
        return $this->paymentMetaRepository->findByPaymentAndKey(
            payment: $payment,
            key: PaymentMeta::INVOICE_ID,
        ) !== null;
    }

    public function generate(ActiveRow $payment): Response
    {
        $stripeInvoiceId = $this->paymentMetaRepository->findByPaymentAndKey(
            payment: $payment,
            key: PaymentMeta::INVOICE_ID,
        )?->value;

        if (!$stripeInvoiceId) {
            throw new StripeBillingException("No Stripe Invoice ID available for payment {$payment->id}.");
        }

        $stripeInvoice = $this->stripeService->retrieveInvoice($stripeInvoiceId);
        return new RedirectResponse($stripeInvoice->invoice_pdf);
    }
}
