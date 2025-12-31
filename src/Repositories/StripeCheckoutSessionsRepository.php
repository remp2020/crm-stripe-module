<?php
declare(strict_types = 1);

namespace Crm\StripeModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Nette\Database\Table\ActiveRow;

class StripeCheckoutSessionsRepository extends Repository
{
    protected $tableName = "stripe_checkout_sessions";

    public function add(
        ActiveRow $subscriptionType,
        ?ActiveRow $user,
        ?ActiveRow $payment,
        string $checkoutSessionId,
        string $reference,
    ) {
        $now = new \DateTime();

        $data = [
            'subscription_type_id' => $subscriptionType->id,
            'user_id' => $user?->id,
            'payment_id' => $payment?->id,
            'checkout_session_id' => $checkoutSessionId,
            'reference' => $reference,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return $this->insert($data);
    }

    public function findByReference(string $reference): ?ActiveRow
    {
        return $this->getTable()
            ->where('reference', $reference)
            ->limit(1)
            ->fetch();
    }

    public function findByCheckoutSessionId(string $checkoutSessionId): ?ActiveRow
    {
        return $this->getTable()
            ->where('checkout_session_id', $checkoutSessionId)
            ->limit(1)
            ->fetch();
    }

    public function findByPayment(ActiveRow $payment): ?ActiveRow
    {
        return $payment->related('stripe_checkout_sessions')->limit(1)->fetch();
    }

    public function linkCheckoutSessionWithPayment(ActiveRow $checkoutSession, ActiveRow $payment)
    {
        return $this->update($checkoutSession, ['payment_id' => $payment->id]);
    }

    public function linkCheckoutSessionWithUser(ActiveRow $checkoutSession, ActiveRow $user)
    {
        return $this->update($checkoutSession, ['user_id' => $user->id]);
    }
}
