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
        try {
            $gateway = $this->gatewayFactory->getGateway($payment->payment_gateway->code);
            return $gateway instanceof StripeBillingRecurrent;
        } catch (\Crm\PaymentsModule\Models\UnknownPaymentMethodCode $e) {
            // Payment has obsolete/unregistered gateway, Stripe billing is not available
            return false;
        }
    }

    public function isInvoiceable(ActiveRow $payment): bool
    {
        $invoiceUrl = $this->paymentMetaRepository->findByPaymentAndKey(
            payment: $payment,
            key: PaymentMeta::INVOICE_URL,
        );
        if ($invoiceUrl) {
            return true;
        }

        $invoiceId = $this->paymentMetaRepository->findByPaymentAndKey(
            payment: $payment,
            key: PaymentMeta::INVOICE_ID,
        );
        if ($invoiceId) {
            return true;
        }

        return false;
    }

    public function generate(ActiveRow $payment): Response
    {
        $stripeInvoiceUrl = $this->paymentMetaRepository->findByPaymentAndKey(
            payment: $payment,
            key: PaymentMeta::INVOICE_URL,
        )?->value;

        if ($stripeInvoiceUrl) {
            return new RedirectResponse($stripeInvoiceUrl);
        }

        $stripeInvoiceId = $this->paymentMetaRepository->findByPaymentAndKey(
            payment: $payment,
            key: PaymentMeta::INVOICE_ID,
        )?->value;

        if ($stripeInvoiceId) {
            $stripeInvoice = $this->stripeService->retrieveInvoice($stripeInvoiceId);
            return new RedirectResponse($stripeInvoice->invoice_pdf);
        }

        throw new StripeBillingException("No Stripe Invoice ID or URL available for payment {$payment->id}.");
    }
}
