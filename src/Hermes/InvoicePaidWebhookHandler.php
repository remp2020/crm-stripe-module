<?php

declare(strict_types=1);

namespace Crm\StripeModule\Hermes;

use Crm\ApplicationModule\Models\Database\ActiveRow;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\PaymentProcessor;
use Crm\PaymentsModule\Models\RecurrentPaymentsProcessor;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\StripeModule\Gateways\StripeBillingRecurrent;
use Crm\StripeModule\Models\PaymentMeta;
use Crm\StripeModule\Models\RuntimeException;
use Crm\StripeModule\Models\StripeService;
use Crm\StripeModule\Models\UserMeta;
use Crm\StripeModule\Repositories\StripeCheckoutSessionsRepository;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesMetaRepository;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Nette\Utils\DateTime;
use Stripe\Invoice;
use Stripe\Subscription;
use Tomaj\Hermes\Handler\HandlerInterface;
use Tomaj\Hermes\Handler\RetryTrait;
use Tomaj\Hermes\MessageInterface;

class InvoicePaidWebhookHandler implements HandlerInterface
{
    use RetryTrait;

    public function __construct(
        protected StripeService $stripeService,
        protected PaymentProcessor $paymentProcessor,
        protected PaymentGatewaysRepository $paymentGatewaysRepository,
        protected PaymentsRepository $paymentsRepository,
        protected RecurrentPaymentsRepository $recurrentPaymentsRepository,
        protected SubscriptionTypesMetaRepository $subscriptionTypesMetaRepository,
        protected StripeCheckoutSessionsRepository $stripeCheckoutSessionsRepository,
        protected UserMetaRepository $userMetaRepository,
        protected PaymentMetaRepository $paymentMetaRepository,
        protected RecurrentPaymentsProcessor $recurrentPaymentsProcessor,
    ) {
    }

    public function handle(MessageInterface $message): bool
    {
        $payload = $message->getPayload();
        $invoiceId = $payload['object_id'];

        $payment = $this->paymentMetaRepository
            ->findByMeta(
                key: PaymentMeta::STRIPE_INVOICE_ID,
                value: $invoiceId,
            )
            ?->payment;
        if ($payment) {
            // payment was already processed
            return true;
        }

        $stripeInvoice = $this->stripeService->retrieveInvoice($invoiceId);
        if ($stripeInvoice->billing_reason !== Invoice::BILLING_REASON_SUBSCRIPTION_CYCLE) {
            // we care only for renewals; initial payment is handled by the checkout.session.complete event handler
            return true;
        }
        if ($stripeInvoice->parent->type !== 'subscription_details') {
            // Not a subscription payment, ignoring
            return true;
        }

        $stripeSubscription = $this->stripeService->retrieveSubscription($stripeInvoice->parent->subscription_details->subscription);
        $gateway = $this->paymentGatewaysRepository->findByCode(StripeBillingRecurrent::GATEWAY_CODE);

        $stripePriceId = $stripeSubscription->items->first()->price->id;
        $subscriptionType = $this->subscriptionTypesMetaRepository
            ->getByKeyAndValue('stripe_price_id', $stripePriceId)
            ->fetch()
            ?->subscription_type;
        if (!$subscriptionType) {
            throw new RuntimeException("Unable to process invoice.paid event, no subscription type matches Stripe price {$stripePriceId}");
        }

        $lastPayment = $this->getLastStripeSubscriptionPayment($stripeSubscription);
        $lastRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($lastPayment);

        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType));
        $paymentMeta = [
            PaymentMeta::STRIPE_INVOICE_ID => $stripeInvoice->id,
            PaymentMeta::STRIPE_SUBSCRIPTION_ID => $stripeSubscription->id,
        ];
        $payment = $this->paymentsRepository->add(
            subscriptionType: $subscriptionType,
            paymentGateway: $gateway,
            user: $this->getUser($stripeInvoice),
            paymentItemContainer: $paymentItemContainer,
            amount: $subscriptionType->price,
            metaData: $paymentMeta,
        );
        $this->paymentsRepository->update($payment, [
            'paid_at' => DateTime::from($stripeInvoice->status_transitions->paid_at),
        ]);

        if ($lastRecurrentPayment) {
            $this->recurrentPaymentsRepository->update($lastRecurrentPayment, [
                'payment_id' => $payment->id,
            ]);
            $this->recurrentPaymentsProcessor->processChargedRecurrent(
                recurrentPayment: $lastRecurrentPayment,
                paymentStatus: PaymentStatusEnum::Prepaid->value,
                resultCode: 0,
                resultMessage: 'NOTIFICATION',
                chargeAt: DateTime::from($stripeSubscription->items->first()->current_period_end),
            );
        } else {
            $payment = $this->paymentsRepository->updateStatus(
                payment: $payment,
                status: PaymentStatusEnum::Prepaid->value,
                sendEmail: true,
            );

            // create recurrent payment; Stripe's subscription ID will be used as recurrent token
            $this->recurrentPaymentsRepository->createFromPayment(
                $payment,
                $stripeSubscription->id,
            );
        }

        return true;
    }

    private function getUser(Invoice $stripeInvoice): ActiveRow
    {
        return $this->userMetaRepository->usersWithKey(
            key: UserMeta::STRIPE_CUSTOMER_ID,
            value: $stripeInvoice->customer->id,
        )->limit(1)->fetch()?->user;
    }

    private function getLastStripeSubscriptionPayment(Subscription $stripeSubscription): ?ActiveRow
    {
        return $this->paymentMetaRepository
            ->findByKeyAndValue(
                key: PaymentMeta::STRIPE_SUBSCRIPTION_ID,
                value: $stripeSubscription->id,
            )
            ->order('id DESC')
            ->limit(1)
            ->fetch()
            ?->payment;
    }
}
