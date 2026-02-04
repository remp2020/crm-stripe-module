<?php

namespace Crm\StripeModule\Gateways;

use Crm\PaymentsModule\Models\Gateways\RefundStatusEnum;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\StripeModule\Models\PaymentMeta;
use Crm\StripeModule\Models\StripeService;
use Nette\Database\Table\ActiveRow;
use Stripe\Exception\ApiErrorException;

/**
 * @property PaymentMetaRepository $paymentMetaRepository
 * @property StripeService $stripeService
 */
trait RefundTrait
{
    public const REFUND_ID = 'stripe_refund_id';
    public const REFUND_AMOUNT = 'refund_amount';
    public const REFUND_DATE = 'refund_date';
    public const REFUND_FAILURE_REASON = 'refund_failure_reason';
    public const REFUND_FAILURE_BALANCE_TRANSACTION = 'refund_failure_balance_transaction';

    public function refund(ActiveRow $payment, float $amount): RefundStatusEnum
    {
        $paymentIntentId = $this->paymentMetaRepository->values($payment, PaymentMeta::PAYMENT_INTENT_ID)->fetch()?->value;
        if (!$paymentIntentId) {
            return RefundStatusEnum::Failure;
        }

        $paymentIntent = $this->stripeService->retrievePaymentIntent($paymentIntentId);

        try {
            $refund = $this->stripeService->createRefund(
                paymentIntent: $paymentIntent,
                amount: (float) abs($amount),
            );

            $this->paymentMetaRepository->add($payment, self::REFUND_ID, $refund->id);
            $this->paymentMetaRepository->add($payment, self::REFUND_AMOUNT, $amount);
            $this->paymentMetaRepository->add($payment, self::REFUND_DATE, (new \DateTime)->format(DATE_RFC3339));

            return RefundStatusEnum::Success;
        } catch (ApiErrorException $e) {
            $this->paymentMetaRepository->add($payment, self::REFUND_FAILURE_REASON, $e->getMessage());
            return RefundStatusEnum::Failure;
        }
    }
}
