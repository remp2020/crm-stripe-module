<?php

namespace Crm\StripeModule\Seeders;

use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\SalesFunnelModule\Repositories\SalesFunnelsPaymentGatewaysRepository;
use Crm\SalesFunnelModule\Repositories\SalesFunnelsRepository;
use Crm\StripeModule\Gateways\Stripe;
use Crm\StripeModule\Gateways\StripeRecurrent;
use Symfony\Component\Console\Output\OutputInterface;

class SalesFunnelsSeeder implements ISeeder
{
    private $salesFunnelsRepository;

    private $paymentGatewaysRepository;

    private $salesFunnelsPaymentGatewaysRepository;

    public function __construct(
        SalesFunnelsRepository $salesFunnelsRepository,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        SalesFunnelsPaymentGatewaysRepository $salesFunnelsPaymentGatewaysRepository,
    ) {
        $this->salesFunnelsRepository = $salesFunnelsRepository;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->salesFunnelsPaymentGatewaysRepository = $salesFunnelsPaymentGatewaysRepository;
    }

    public function seed(OutputInterface $output)
    {
        foreach (glob(__DIR__ . '/sales_funnels/*.twig') as $filename) {
            $info = pathinfo($filename);
            $key = $info['filename'];

            $funnel = $this->salesFunnelsRepository->findByUrlKey($key);
            if (!$funnel) {
                $funnel = $this->salesFunnelsRepository->add($key, $key, file_get_contents($filename));
                $output->writeln('  <comment>* funnel <info>' . $key . '</info> created</comment>');
            } else {
                $output->writeln('  * funnel <info>' . $key . '</info> exists');
            }

            $this->salesFunnelsPaymentGatewaysRepository->add($funnel, $this->paymentGatewaysRepository->findByCode(Stripe::GATEWAY_CODE));
            $this->salesFunnelsPaymentGatewaysRepository->add($funnel, $this->paymentGatewaysRepository->findByCode(StripeRecurrent::GATEWAY_CODE));
        }
    }
}
