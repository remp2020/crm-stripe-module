<?php

namespace Crm\StripeModule\Seeders;

use Crm\ApplicationModule\Builder\ConfigBuilder;
use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Config\Repository\ConfigCategoriesRepository;
use Crm\ApplicationModule\Config\Repository\ConfigsRepository;
use Crm\ApplicationModule\Seeders\ISeeder;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigsSeeder implements ISeeder
{
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

        $this->addPaymentConfig(
            $output,
            $category,
            'stripe_publishable',
            'stripe.config.publishable.name',
            '',
            $sorting++,
            'stripe.config.publishable.description'
        );
        $this->addPaymentConfig(
            $output,
            $category,
            'stripe_secret',
            'stripe.config.secret.name',
            '',
            $sorting++,
            'stripe.config.secret.description'
        );
    }

    private function addPaymentConfig(OutputInterface $output, $category, $name, $displayName, $value, $sorting, $description = null)
    {
        $config = $this->configsRepository->loadByName($name);
        if (!$config) {
            $this->configBuilder->createNew()
                ->setName($name)
                ->setDisplayName($displayName)
                ->setDescription($description)
                ->setValue($value)
                ->setType(ApplicationConfig::TYPE_STRING)
                ->setAutoload(true)
                ->setConfigCategory($category)
                ->setSorting($sorting)
                ->save();
            $output->writeln("  <comment>* config item <info>$name</info> created</comment>");
        } else {
            $output->writeln("  * config item <info>$name</info> exists");

            if ($config->has_default_value && $config->value !== $value) {
                $this->configsRepository->update($config, ['value' => $value, 'has_default_value' => true]);
                $output->writeln("  <comment>* config item <info>$name</info> updated</comment>");
            }

            if ($config->category->name != $category->name) {
                $this->configsRepository->update($config, [
                    'config_category_id' => $category->id
                ]);
                $output->writeln("  <comment>* config item <info>$name</info> updated</comment>");
            }
        }
    }
}
