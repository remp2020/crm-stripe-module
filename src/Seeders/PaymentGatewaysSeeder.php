<?php

namespace Crm\StripeModule\Seeders;

use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\StripeModule\Gateways\Stripe;
use Crm\StripeModule\Gateways\StripeRecurrent;
use Crm\StripeModule\Gateways\StripeWallet;
use Symfony\Component\Console\Output\OutputInterface;

class PaymentGatewaysSeeder implements ISeeder
{
    private $paymentGatewaysRepository;
    
    public function __construct(PaymentGatewaysRepository $paymentGatewaysRepository)
    {
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
    }

    public function seed(OutputInterface $output)
    {
        $code = Stripe::GATEWAY_CODE;
        if (!$this->paymentGatewaysRepository->exists($code)) {
            $this->paymentGatewaysRepository->add(
                'Stripe',
                $code,
                120,
                true,
                false,
            );
            $output->writeln("  <comment>* payment type <info>{$code}</info> created</comment>");
        } else {
            $output->writeln("  * payment type <info>{$code}</info> exists");
        }

        $code = StripeRecurrent::GATEWAY_CODE;
        if (!$this->paymentGatewaysRepository->exists($code)) {
            $this->paymentGatewaysRepository->add(
                'Stripe Recurrent',
                $code,
                121,
                true,
                true,
            );
            $output->writeln("  <comment>* payment type <info>{$code}</info> created</comment>");
        } else {
            $output->writeln("  * payment type <info>{$code}</info> exists");
        }

        $code = StripeWallet::GATEWAY_CODE;
        if (!$this->paymentGatewaysRepository->exists($code)) {
            $this->paymentGatewaysRepository->add(
                'Stripe Wallet',
                $code,
                122,
                true,
                false,
            );
            $output->writeln('  <comment>* payment gateway <info>{$code)</info> created</comment>');
        } else {
            $output->writeln('  * payment gateway <info>{$code}</info> exists');
        }
    }
}
