<?php

declare(strict_types=1);

namespace Crm\StripeModule\Hermes;

use Crm\PaymentsModule\Models\PaymentProcessor;
use Crm\StripeModule\Models\StripeService;
use Crm\StripeModule\Repositories\StripeCheckoutSessionsRepository;
use Tomaj\Hermes\Handler\HandlerInterface;
use Tomaj\Hermes\Handler\RetryTrait;
use Tomaj\Hermes\MessageInterface;

class CheckoutSessionCompletedWebhookHandler implements HandlerInterface
{
    use RetryTrait;

    public function __construct(
        protected StripeService $stripeService,
        protected PaymentProcessor $paymentProcessor,
        protected StripeCheckoutSessionsRepository $stripeCheckoutSessionsRepository,
    ) {
    }

    public function handle(MessageInterface $message): bool
    {
        $payload = $message->getPayload();
        $checkoutSessionId = $payload['object_id'];

        $checkoutSessionRow = $this->stripeCheckoutSessionsRepository->findByCheckoutSessionId($checkoutSessionId);
        $payment = $this->stripeService->getPaymentForCheckoutSession($checkoutSessionRow);

        $this->paymentProcessor->complete($payment, function () {
            // nothing to do, backend confirmation
        });

        return true;
    }
}
