<?php

namespace Crm\StripeModule;

use Crm\ApiModule\Models\Api\ApiRoutersContainerInterface;
use Crm\ApiModule\Models\Authorization\NoAuthorization;
use Crm\ApiModule\Models\Router\ApiIdentifier;
use Crm\ApiModule\Models\Router\ApiRoute;
use Crm\ApplicationModule\Application\Managers\LayoutManager;
use Crm\ApplicationModule\Application\Managers\SeederManager;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\StripeModule\Api\CreateSubscriptionCheckoutSessionApiHandler;
use Crm\StripeModule\Api\SetupIntentHandler;
use Crm\StripeModule\Api\WebhookApiHandler;
use Crm\StripeModule\DataProviders\SalesFunnelTwigVariablesDataProvider;
use Crm\StripeModule\DataProviders\StripeBillingDataProvider;
use Crm\StripeModule\Hermes\CheckoutSessionCompletedWebhookHandler;
use Crm\StripeModule\Hermes\CustomerSubscriptionDeletedWebhookHandler;
use Crm\StripeModule\Hermes\InvoicePaidWebhookHandler;
use Crm\StripeModule\Seeders\ConfigsSeeder;
use Crm\StripeModule\Seeders\PaymentGatewaysSeeder;
use Crm\StripeModule\Seeders\SalesFunnelsSeeder;
use Tomaj\Hermes\Dispatcher;

class StripeModule extends CrmModule
{
    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(ConfigsSeeder::class));
        $seederManager->addSeeder($this->getInstance(PaymentGatewaysSeeder::class));
        $seederManager->addSeeder($this->getInstance(SalesFunnelsSeeder::class));
    }

    public function registerApiCalls(ApiRoutersContainerInterface $apiRoutersContainer)
    {
        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'stripe', 'setup-intent'),
                SetupIntentHandler::class,
                NoAuthorization::class,
            ),
        );

        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'stripe', 'create-checkout-session', 'POST'),
                CreateSubscriptionCheckoutSessionApiHandler::class,
                NoAuthorization::class,
            ),
        );

        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'stripe', 'webhook', 'POST'),
                WebhookApiHandler::class,
                NoAuthorization::class,
            ),
        );
    }

    public function registerDataProviders(DataProviderManager $dataProviderManager)
    {
        $dataProviderManager->registerDataProvider(
            'sales_funnel.dataprovider.twig_variables',
            $this->getInstance(SalesFunnelTwigVariablesDataProvider::class),
        );

        $dataProviderManager->registerDataProvider(
            'invoices.billing_provider',
            $this->getInstance(StripeBillingDataProvider::class),
        );
    }

    public function registerHermesHandlers(Dispatcher $dispatcher)
    {
        $dispatcher->registerHandler(
            'stripe-webhook-invoice.paid',
            $this->getInstance(InvoicePaidWebhookHandler::class),
        );

        $dispatcher->registerHandler(
            'stripe-webhook-checkout.session.completed',
            $this->getInstance(CheckoutSessionCompletedWebhookHandler::class),
        );

        $dispatcher->registerHandler(
            'stripe-webhook-customer.subscription.deleted',
            $this->getInstance(CustomerSubscriptionDeletedWebhookHandler::class),
        );
    }

    public function registerLayouts(LayoutManager $layoutManager)
    {
        $layoutManager->registerLayout(
            'plain',
            realpath(__DIR__ . '/templates/@plain_layout.latte'),
        );
    }
}
