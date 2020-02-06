<?php

namespace Crm\StripeModule\Seeders;

use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\StripeModule\Gateways\Stripe;
use Crm\StripeModule\Gateways\StripeRecurrent;
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
                100,
                true,
                false
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
                110,
                true,
                true
            );
            $output->writeln("  <comment>* payment type <info>{$code}</info> created</comment>");
        } else {
            $output->writeln("  * payment type <info>{$code}</info> exists");
        }
    }
}
