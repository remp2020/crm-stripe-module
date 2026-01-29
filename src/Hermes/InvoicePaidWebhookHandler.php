<?php

declare(strict_types=1);

namespace Crm\StripeModule\Hermes;

use Crm\PaymentsModule\Models\OneStopShop\CountryResolution;
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
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesMetaRepository;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\CountriesRepository;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Database\Table\ActiveRow;
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
        protected SubscriptionTypeItemsRepository $subscriptionTypeItemsRepository,
        protected CountriesRepository $countriesRepository,
        protected UsersRepository $usersRepository,
        protected UserManager $userManager,
    ) {
    }

    public function handle(MessageInterface $message): bool
    {
        $payload = $message->getPayload();
        $invoiceId = $payload['object_id'];

        $payment = $this->paymentMetaRepository
            ->findByMeta(
                key: PaymentMeta::INVOICE_ID,
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
        $countryResolution = null;
        $paymentItemContainer = (new PaymentItemContainer());

        if (count($stripeInvoice->lines) === 1) {
            $subscriptionTypeItem = $this->subscriptionTypeItemsRepository
                ->subscriptionTypeItems($subscriptionType)
                ->fetch();

            $vatRate = null;

            $line = $stripeInvoice->lines->first();
            foreach ($line->taxes as $tax) {
                if (isset($tax->tax_rate_details->tax_rate)) {
                    $stripeTaxRate = $this->stripeService->retrieveTaxRate($tax->tax_rate_details->tax_rate);
                    $countryResolution = new CountryResolution(
                        country: $this->countriesRepository->findByIsoCode($stripeTaxRate->country),
                        reason: 'stripe_tax',
                    );
                    $vatRate = $stripeTaxRate->percentage;
                    break;
                }
            }

            $paymentItem = new SubscriptionTypePaymentItem(
                subscriptionTypeId: $subscriptionType->id,
                name: $subscriptionTypeItem->name,
                price: $line->amount / 100,
                vat: $vatRate ?? $subscriptionTypeItem->vat,
                subscriptionTypeItemId: $subscriptionTypeItem->id,
            );

            $paymentItemContainer->addItem($paymentItem);
        } else {
            $paymentItemContainer->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType));
        }

        if (!$countryResolution) {
            $resolvedCountryCode = $stripeInvoice->customer_address->country;
            $country = $this->countriesRepository->findByIsoCode($resolvedCountryCode);

            $countryResolution = new CountryResolution(
                country: $country,
                reason: 'stripe_customer',
            );
        }

        $paymentMeta = [
            PaymentMeta::INVOICE_ID => $stripeInvoice->id,
            PaymentMeta::SUBSCRIPTION_ID => $stripeSubscription->id,
        ];
        $payment = $this->paymentsRepository->add(
            subscriptionType: $subscriptionType,
            paymentGateway: $gateway,
            user: $this->getUser($stripeInvoice),
            paymentItemContainer: $paymentItemContainer,
            amount: $subscriptionType->price,
            recurrentCharge: true,
            metaData: $paymentMeta,
            paymentCountry: $countryResolution?->country,
            paymentCountryResolutionReason: $countryResolution?->reason,
        );
        $this->paymentsRepository->update($payment, [
            'paid_at' => DateTime::from($stripeInvoice->status_transitions->paid_at),
        ]);

        $lastRecurrentPayment = null;
        if ($lastPayment) {
            $lastRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($lastPayment);
        }

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
        $userRow = $this->userMetaRepository->usersWithKey(
            key: UserMeta::STRIPE_CUSTOMER_ID,
            value: $stripeInvoice->customer->id,
        )->limit(1)->fetch()?->user;

        if ($userRow) {
            return $userRow;
        }

        $email = $stripeInvoice->customer->email;
        $userRow = $this->userManager->loadUserByEmail($email);

        if ($userRow) {
            $this->userMetaRepository->add(
                user: $userRow,
                key: UserMeta::STRIPE_CUSTOMER_ID,
                value: $stripeInvoice->customer->id,
            );
            return $userRow;
        }

        $userRow = $this->userManager->addNewUser(
            email: $email,
            sendEmail: false,
            source: 'stripe',
            checkEmail: false,
            addToken: false,
            userMeta: array_filter([
                UserMeta::STRIPE_CUSTOMER_ID => $stripeInvoice->customer->id,
            ]),
        );

        return $userRow;
    }

    private function getLastStripeSubscriptionPayment(Subscription $stripeSubscription): ?ActiveRow
    {
        return $this->paymentMetaRepository
            ->findByKeyAndValue(
                key: PaymentMeta::SUBSCRIPTION_ID,
                value: $stripeSubscription->id,
            )
            ->order('id DESC')
            ->limit(1)
            ->fetch()
            ?->payment;
    }
}
