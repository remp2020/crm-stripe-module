<?php

namespace Crm\StripeModule\Seeders;

use Crm\ApplicationModule\Builder\ConfigBuilder;
use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Config\Repository\ConfigCategoriesRepository;
use Crm\ApplicationModule\Config\Repository\ConfigsRepository;
use Crm\ApplicationModule\Seeders\ConfigsTrait;
use Crm\ApplicationModule\Seeders\ISeeder;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigsSeeder implements ISeeder
{
    use ConfigsTrait;

    private $configCategoriesRepository;

    private $configsRepository;

    private $configBuilder;

    public function __construct(
        ConfigCategoriesRepository $configCategoriesRepository,
        ConfigsRepository $configsRepository,
        ConfigBuilder $configBuilder
    ) {
        $this->configCategoriesRepository = $configCategoriesRepository;
        $this->configsRepository = $configsRepository;
        $this->configBuilder = $configBuilder;
    }

    public function seed(OutputInterface $output)
    {
        $category = $this->configCategoriesRepository->findBy('name', 'payments.config.category');
        $sorting = 1600;

        $this->addConfig(
            $output,
            $category,
            'stripe_publishable',
            ApplicationConfig::TYPE_STRING,
            'stripe.config.publishable.name',
            'stripe.config.publishable.description',
            '',
            $sorting++
        );
        $this->addConfig(
            $output,
            $category,
            'stripe_secret',
            ApplicationConfig::TYPE_STRING,
            'stripe.config.secret.name',
            'stripe.config.secret.description',
            '',
            $sorting++
        );
        $this->addConfig(
            $output,
            $category,
            'stripe_wallet_display_name',
            ApplicationConfig::TYPE_STRING,
            'stripe.config.display_name.name',
            'stripe.config.display_name.description',
            null,
            $sorting++
        );
    }
}
