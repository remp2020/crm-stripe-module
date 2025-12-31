<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class StripeCheckoutSessions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('stripe_checkout_sessions')
            ->addColumn('checkout_session_id', 'string', ['null' => false])
            ->addColumn('reference', 'string', ['null' => false])
            ->addColumn('subscription_type_id', 'integer', ['null' => false])
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('payment_id', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex('checkout_session_id', ['unique' => true])
            ->addIndex('reference', ['unique' => true])
            ->addForeignKey('subscription_type_id', 'subscription_types', 'id')
            ->addForeignKey('user_id', 'users', 'id')
            ->addForeignKey('payment_id', 'payments', 'id')
            ->create();
    }

    public function down(): void
    {
        $this->table('stripe_checkout_sessions')
            ->drop()
            ->update();
    }
}
