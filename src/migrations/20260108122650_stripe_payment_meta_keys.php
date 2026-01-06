<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class StripePaymentMetaKeys extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE user_meta SET `key` = 'stripe_customer_id' WHERE `key` = 'stripe_customer'");
        $this->execute("UPDATE payment_meta SET `key` = 'stripe_payment_method_id' WHERE `key` = 'payment_method_id'");
        $this->execute("UPDATE payment_meta SET `key` = 'stripe_payment_intent_id' WHERE `key` = 'payment_intent_id'");
    }
}
