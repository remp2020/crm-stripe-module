<?php

namespace Crm\StripeModule\Gateways;

use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\PaymentsModule\Models\Gateways\GatewayAbstract;
use Crm\PaymentsModule\Models\Gateways\RecurrentPaymentInterface;
use Crm\PaymentsModule\Models\Gateways\StoppableExternallyChargedRecurrentPaymentInterface;
use Crm\PaymentsModule\Models\OneStopShop\CountryResolution;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\RecurrentPaymentFailStop;
use Crm\PaymentsModule\Models\RecurrentPaymentFailTry;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\StripeModule\Models\PaymentMeta;
use Crm\StripeModule\Models\StripeService;
use Crm\StripeModule\Repositories\StripeCheckoutSessionsRepository;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesMetaRepository;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\CountriesRepository;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Nette\Application\LinkGenerator;
use Nette\Http\Response;
use Nette\Localization\Translator;
use Nette\Utils\DateTime;
use Stripe\Checkout\Session;
use Stripe\Invoice;
use Stripe\PaymentMethod;
use Tracy\Debugger;

class StripeBillingRecurrent extends GatewayAbstract implements RecurrentPaymentInterface, StoppableExternallyChargedRecurrentPaymentInterface
{
    public const GATEWAY_CODE = 'stripe_billing_recurrent';

    protected ?Session $checkoutSession = null;

    public function __construct(
        LinkGenerator $linkGenerator,
        ApplicationConfig $applicationConfig,
        Response $httpResponse,
        Translator $translator,
        protected readonly PaymentMetaRepository $paymentMetaRepository,
        protected readonly UserMetaRepository $userMetaRepository,
        protected readonly SubscriptionTypeItemsRepository $subscriptionTypeItemsRepository,
        protected readonly StripeCheckoutSessionsRepository $stripeCheckoutSessionsRepository,
        protected readonly PaymentGatewaysRepository $paymentGatewaysRepository,
        protected readonly PaymentsRepository $paymentsRepository,
        protected readonly CountriesRepository $countriesRepository,
        protected readonly UserManager $userManager,
        protected readonly StripeService $stripeService,
        protected readonly RecurrentPaymentsRepository $recurrentPaymentsRepository,
        protected readonly SubscriptionTypesMetaRepository $subscriptionTypesMetaRepository,
    ) {
        parent::__construct($linkGenerator, $applicationConfig, $httpResponse, $translator);
    }

    public function begin($payment)
    {
        $this->httpResponse->redirect(
            $this->linkGenerator->link('Stripe:Checkout:pay', [
                'vs' => $payment->variable_symbol,
                'returnUrl' => $this->getReturnUrl(),
            ]),
        );
        exit();
    }

    public function complete($payment): ?bool
    {
        $checkoutSessionRow = $this->stripeCheckoutSessionsRepository->findByPayment($payment);
        $checkoutSession = $this->stripeService->retrieveCheckoutSession($checkoutSessionRow->checkout_session_id);

        $this->checkoutSession = $checkoutSession;

        $this->paymentMetaRepository->add($payment, PaymentMeta::STRIPE_INVOICE_ID, $checkoutSession->invoice->id);
        $this->paymentMetaRepository->add($payment, PaymentMeta::STRIPE_SUBSCRIPTION_ID, $checkoutSession->subscription->id);

        return $checkoutSession->payment_status === Session::PAYMENT_STATUS_PAID;
    }

    public function getPaymentForCheckoutSession(Session $checkoutSession)
    {
        $checkoutSessionRow = $this->stripeCheckoutSessionsRepository
            ->findByReference($checkoutSession->client_reference_id);

        if (isset($checkoutSessionRow->payment_id)) {
            return $checkoutSessionRow->payment;
        }

        $user = $checkoutSessionRow->user;
        if (!$user) {
            $email = $checkoutSession->customer_details->email;
            $user = $this->userManager->loadUserByEmail($email);
            if (!$user) {
                $user = $this->userManager->addNewUser(
                    email: $email,
                    sendEmail: true,
                    source: 'stripe',
                );
            }

            $this->stripeCheckoutSessionsRepository->linkCheckoutSessionWithUser($checkoutSessionRow, $user);
        }

        $subscriptionType = $checkoutSessionRow->subscription_type;
        $subscriptionTypePaymentItems = SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType);
        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems($subscriptionTypePaymentItems);

        $paymentGateway = $this->paymentGatewaysRepository->findByCode(self::GATEWAY_CODE);

        $resolvedCountryCode = $checkoutSession->customer_details->address->country;
        $country = $this->countriesRepository->findByIsoCode($resolvedCountryCode);

        $countryResolution = new CountryResolution(
            country: $country,
            reason: 'stripe',
        );

        $payment = $this->paymentsRepository->add(
            subscriptionType: $checkoutSessionRow->subscription_type,
            paymentGateway: $paymentGateway,
            user: $user,
            paymentItemContainer: $paymentItemContainer,
            amount: $checkoutSession->amount_total,
            paymentCountry: $countryResolution?->country,
            paymentCountryResolutionReason: $countryResolution?->getReasonValue(),
        );

        $this->stripeCheckoutSessionsRepository->linkCheckoutSessionWithPayment($checkoutSessionRow, $payment);

        return $payment;
    }

    public function charge($payment, $token): string
    {
        $stripeSubscription = $this->stripeService->retrieveSubscription($token);

        if ($stripeSubscription->canceled_at !== null) {
            throw new RecurrentPaymentFailStop("Stripe subscription {$token} was canceled at {$stripeSubscription->canceled_at}");
        }

        $latestInvoice = $stripeSubscription->latest_invoice;
        if ($latestInvoice->billing_reason === Invoice::BILLING_REASON_SUBSCRIPTION_CREATE) {
            throw new RecurrentPaymentFailTry("No charge attempt made by Stripe yet");
        }

        $latestInvoicePeriodEnd = DateTime::from($latestInvoice->period_end);

        if ($latestInvoicePeriodEnd < new DateTime()) {
            throw new RecurrentPaymentFailTry("No charge attempt made by Stripe yet; any minute now.");
        }

        if ($latestInvoice->status === Invoice::STATUS_OPEN) {
            if ($latestInvoice->attempted) {
                throw new RecurrentPaymentFailTry("Stripe attempted the payment, but it wasn't succesful.");
            } else {
                return self::CHARGE_PENDING;
            }
        }

        if ($latestInvoice->status === Invoice::STATUS_PAID) {
            // Usually this shouldn't happen because of the webhook based confirmation of automatic renewal.
            $this->paymentMetaRepository->add($payment, PaymentMeta::STRIPE_INVOICE_ID, $latestInvoice->id);
            $this->paymentMetaRepository->add($payment, PaymentMeta::STRIPE_SUBSCRIPTION_ID, $stripeSubscription->id);

            return self::CHARGE_OK;
        }

        throw new RecurrentPaymentFailTry("Invoice {$latestInvoice->id} in unpaid status {$latestInvoice->status}");
    }

    public function checkValid($token): bool
    {
        return $this->stripeService->retrieveSubscription($token) !== null;
    }

    public function checkExpire($recurrentPayments)
    {
        $result = [];

        foreach ($recurrentPayments as $token) {
            $recurrentPayment = $this->recurrentPaymentsRepository->getTable()
                ->where('payment_method.external_token', $token)
                ->order('created_at DESC')
                ->limit(1)
                ->fetch();

            if (!$recurrentPayment) {
                continue;
            }

            try {
                $stripeSubscription = $this->stripeService->retrieveSubscription($token);
            } catch (\Exception $exception) {
                Debugger::log($exception->getMessage());
                continue;
            }

            if ($stripeSubscription->default_payment_method->type !== PaymentMethod::TYPE_CARD) {
                continue;
            }

            $month = $stripeSubscription->default_payment_method->card->exp_month;
            $year = $stripeSubscription->default_payment_method->card->exp_year;
            $result[$token] = DateTime::from("$year-$month-01 00:00 next month");
        }

        return $result;
    }

    public function hasRecurrentToken(): bool
    {
        return isset($this->checkoutSession->subscription->id);
    }

    public function getRecurrentToken(): string
    {
        return $this->checkoutSession->subscription->id;
    }

    public function getResultCode(): ?string
    {
        return '0';
    }

    public function getResultMessage(): ?string
    {
        return 'CHARGE_COMMAND';
    }

    public function getReturnUrl(): string
    {
        $returnUrl = $this->linkGenerator->link('Stripe:Checkout:return', [
            'id' => 'CHECKOUT_SESSION_ID',
        ]);
        // Nette was escaping curly braces which caused Stripe not to inject checkout session ID correctly
        $returnUrl = str_replace('CHECKOUT_SESSION_ID', '{CHECKOUT_SESSION_ID}', $returnUrl);

        return $returnUrl;
    }

    public function getChargedPaymentStatus(): string
    {
        return PaymentStatusEnum::Prepaid->value;
    }

    public function getSubscriptionExpiration(string $cid = null): \DateTime
    {
        $stripeSubscription = $this->stripeService->retrieveSubscription($cid);
        return DateTime::from($stripeSubscription->items->first()->current_period_end);
    }

    public function cancelExternalSubscription(string $token): bool
    {
        $subscription = $this->stripeService->cancelSubscription($token);
        return $subscription->status === 'canceled';
    }
}
