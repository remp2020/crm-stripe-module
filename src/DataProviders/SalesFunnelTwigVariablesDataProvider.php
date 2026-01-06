<?php

namespace Crm\StripeModule\DataProviders;

use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\SalesFunnelModule\DataProviders\SalesFunnelVariablesDataProviderInterface;

class SalesFunnelTwigVariablesDataProvider implements SalesFunnelVariablesDataProviderInterface
{
    public function __construct(
        private readonly ApplicationConfig $applicationConfig,
    ) {
    }

    public function provide(array $params): array
    {
        return [
            'stripe_publishable_key' => $this->applicationConfig->get('stripe_publishable'),
        ];
    }
}
