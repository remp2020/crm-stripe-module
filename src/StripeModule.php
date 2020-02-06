<?php

namespace Crm\StripeModule;

use Crm\ApiModule\Api\ApiRoutersContainerInterface;
use Crm\ApiModule\Authorization\NoAuthorization;
use Crm\ApiModule\Router\ApiIdentifier;
use Crm\ApiModule\Router\ApiRoute;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\SeederManager;
use Crm\StripeModule\Api\SetupIntentHandler;
use Crm\StripeModule\Seeders\ConfigsSeeder;
use Crm\StripeModule\Seeders\PaymentGatewaysSeeder;

class StripeModule extends CrmModule
{
    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(ConfigsSeeder::class));
        $seederManager->addSeeder($this->getInstance(PaymentGatewaysSeeder::class));
    }

    public function registerApiCalls(ApiRoutersContainerInterface $apiRoutersContainer)
    {
        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'stripe', 'setup-intent'),
                SetupIntentHandler::class,
                NoAuthorization::class
            )
        );
    }
}
