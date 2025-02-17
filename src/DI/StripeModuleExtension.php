<?php

namespace Crm\StripeModule\DI;

use Contributte\Translation\DI\TranslationProviderInterface;
use Nette\Application\IPresenterFactory;
use Nette\DI\CompilerExtension;

final class StripeModuleExtension extends CompilerExtension implements TranslationProviderInterface
{
    public function loadConfiguration()
    {
        // load services from config and register them to Nette\DI Container
        $this->compiler->loadDefinitionsFromConfig(
            $this->loadFromFile(__DIR__.'/../config/config.neon')['services']
        );
    }

    /**
     * Return array of directories, that contain resources for translator.
     * @return string[]
     */
    public function getTranslationResources(): array
    {
        return [__DIR__ . '/../lang/'];
    }

    public function beforeCompile()
    {
        $builder = $this->getContainerBuilder();
        // load presenters from extension to Nette
        $builder->getDefinition($builder->getByType(IPresenterFactory::class))
            ->addSetup('setMapping', [['Stripe' => 'Crm\StripeModule\Presenters\*Presenter']]);
    }
}
