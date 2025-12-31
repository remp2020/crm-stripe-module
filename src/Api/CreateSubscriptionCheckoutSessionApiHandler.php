<?php

declare(strict_types=1);

namespace Crm\StripeModule\Api;

use Crm\ApiModule\Models\Api\ApiHandler;
use Crm\PaymentsModule\Models\GatewayFactory;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\StripeModule\Gateways\StripeBillingRecurrent;
use Crm\StripeModule\Models\StripeService;
use Crm\StripeModule\Repositories\StripeCheckoutSessionsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesRepository;
use Crm\UsersModule\Repositories\AccessTokensRepository;
use Nette\Application\LinkGenerator;
use Nette\Http\Response;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

class CreateSubscriptionCheckoutSessionApiHandler extends ApiHandler
{
    public function __construct(
        private readonly StripeService $stripeService,
        private readonly StripeCheckoutSessionsRepository $stripeCheckoutSessionsRepository,
        private readonly AccessTokensRepository $accessTokensRepository,
        private readonly SubscriptionTypesRepository $subscriptionTypesRepository,
        private readonly SubscriptionTypesMetaRepository $subscriptionTypesMetaRepository,
        private readonly PaymentsRepository $paymentsRepository,
        private readonly GatewayFactory $gatewayFactory,
        LinkGenerator $linkGenerator,
    ) {
        parent::__construct();
        $this->setupLinkGenerator($linkGenerator);
    }

    public function params(): array
    {
        return [
            (new JsonInputParam('json', file_get_contents(__DIR__ . '/create-checkout-session.schema.json')))->setRequired(),
        ];
    }

    public function handle(array $params): ResponseInterface
    {
        $user = null;
        $payment = null;
        $subscriptionType = null;

        if (isset($params['json']['payment_id'])) {
            $payment = $this->paymentsRepository->find($params['json']['payment_id']);
            if (!$payment) {
                return new JsonApiResponse(Response::S200_OK, [
                    'status' => 'error',
                    'code' => 'invalid_payment_id',
                    'message' => "Payment not found: {$params['json']['payment_id']}",
                ]);
            }

            $checkoutSessionRow = $this->stripeCheckoutSessionsRepository->findByPayment($payment);
            if ($checkoutSessionRow) {
                $checkoutSession = $this->stripeService->retrieveCheckoutSession($checkoutSessionRow->checkout_session_id);
                return new JsonApiResponse(Response::S200_OK, [
                    'client_secret' => $checkoutSession->client_secret,
                ]);
            }

            $user = $payment->user;
            $subscriptionType = $payment->subscription_type;
        }

        if (!$user) {
            $accessToken = $this->accessTokensRepository->findBy('token', $params['json']['access_token']);
            if (!$accessToken) {
                return new JsonApiResponse(Response::S200_OK, [
                    'status' => 'error',
                    'code' => 'invalid_access_token',
                    'message' => "Access token not found: {$params['json']['access_token']}",
                ]);
            }
            $user = $accessToken->user;
        }

        if (!$subscriptionType) {
            $subscriptionType = $this->subscriptionTypesRepository->findByCode($params['json']['subscription_type_code']);
            if (!$subscriptionType) {
                return new JsonApiResponse(Response::S200_OK, [
                    'status' => 'error',
                    'code' => 'invalid_subscription_type_code',
                    'message' => "Subscription type doesn't exist: {$params['json']['subscription_type_code']}",
                ]);
            }
        }

        if (!$user) {
            // In the future this might not be necessary. We can create stripe customer based on an email, but the
            // email flow is not implemented yet. This API would need to accept it and handle it only for the new
            // registrations. Existing registrations should be able to use access_token scenario.

            return new JsonApiResponse(Response::S200_OK, [
                'status' => 'error',
                'code' => 'invalid_user',
                'message' => "Cannot determine user of checkout session.",
            ]);
        }

        $stripeCustomer = $this->stripeService->getStripeCustomerByUser($user);
        $priceId = $this->subscriptionTypesMetaRepository->getMetaValue($subscriptionType, 'stripe_price_id');

        /** @var StripeBillingRecurrent $stripeBillingGateway */
        $stripeBillingGateway = $this->gatewayFactory->getGateway(StripeBillingRecurrent::GATEWAY_CODE);
        $returnUrl = $stripeBillingGateway->getReturnUrl();

        $checkoutSession = $this->stripeService->createSubscriptionCheckoutSession($stripeCustomer, $priceId, $returnUrl);

        $this->stripeCheckoutSessionsRepository->add(
            subscriptionType: $subscriptionType,
            user: $user,
            payment: $payment,
            checkoutSessionId: $checkoutSession->id,
            reference: $checkoutSession->client_reference_id,
        );

        return new JsonApiResponse(Response::S200_OK, [
            'client_secret' => $checkoutSession->client_secret,
        ]);
    }
}
