<?php

declare(strict_types=1);

namespace Crm\StripeModule\Hermes;

use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\StripeModule\Models\StripeService;
use Crm\StripeModule\Models\UserMeta;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Tomaj\Hermes\Handler\HandlerInterface;
use Tomaj\Hermes\Handler\RetryTrait;
use Tomaj\Hermes\MessageInterface;
use Tracy\Debugger;

class CustomerSubscriptionDeletedWebhookHandler implements HandlerInterface
{
    use RetryTrait;

    public function __construct(
        protected RecurrentPaymentsRepository $recurrentPaymentsRepository,
        protected StripeService $stripeService,
        protected UserMetaRepository $userMetaRepository,
    ) {
    }

    public function handle(MessageInterface $message): bool
    {
        $payload = $message->getPayload();
        $subscriptionId = $payload['object_id'];

        $stripeSubscription = $this->stripeService->retrieveSubscription($subscriptionId);
        $stripeCustomerId = $stripeSubscription->customer;

        $userRow = $this->userMetaRepository->usersWithKey(
            key: UserMeta::STRIPE_CUSTOMER_ID,
            value: $stripeCustomerId,
        )->limit(1)->fetch()?->user;

        if (!$userRow) {
            Debugger::log(
                "Stripe subscription [{$subscriptionId}] deleted, but no user found for Stripe customer [{$stripeCustomerId}]",
                Debugger::WARNING,
            );
            return true;
        }

        $activeRecurrentPayments = $this->recurrentPaymentsRepository
            ->getUserActiveRecurrentPayments(userId: $userRow->id)
            ->where('cid = ?', $subscriptionId);

        foreach ($activeRecurrentPayments as $recurrentPayment) {
            $this->recurrentPaymentsRepository->stoppedBySystem($recurrentPayment->id);
        }

        return true;
    }
}
