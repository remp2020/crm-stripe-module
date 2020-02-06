<?php

namespace Crm\StripeModule\DI;

use Kdyby\Translation\DI\ITranslationProvider;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;

final class StripeModuleExtension extends CompilerExtension implements ITranslationProvider
{
    private $defaults = [];

    public function loadConfiguration()
    {
        $builder = $this->getContainerBuilder();

        // set default values if user didn't define them
        $this->config = $this->validateConfig($this->defaults);

        // load services from config and register them to Nette\DI Container
        Compiler::loadDefinitions(
            $builder,
            $this->loadFromFile(__DIR__.'/../config/config.neon')['services']
        );
    }

    /**
     * Return array of directories, that contain resources for translator.
     * @return string[]
     */
    public function getTranslationResources()
    {
        return [__DIR__ . '/../lang/'];
    }
}
