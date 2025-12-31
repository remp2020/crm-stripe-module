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
    public function __construct(
        private readonly SalesFunnelsRepository $salesFunnelsRepository,
        private readonly PaymentGatewaysRepository $paymentGatewaysRepository,
        private readonly SalesFunnelsPaymentGatewaysRepository $salesFunnelsPaymentGatewaysRepository,
    ) {
    }

    public function seed(OutputInterface $output)
    {
        foreach (glob(__DIR__ . '/sales_funnels/*.twig') as $filename) {
            $info = pathinfo($filename);
            $key = $info['filename'];

            $funnel = $this->salesFunnelsRepository->findByUrlKey($key);
            if (!$funnel) {
                $funnel = $this->salesFunnelsRepository->add(
                    name: $key,
                    urlKey: $key,
                    body: file_get_contents($filename),
                    isActive: false,
                );
                $output->writeln('  <comment>* funnel <info>' . $key . '</info> created</comment>');
            } else {
                $output->writeln('  * funnel <info>' . $key . '</info> exists');
            }

            $this->salesFunnelsPaymentGatewaysRepository->add(
                salesFunnel: $funnel,
                paymentGateway: $this->paymentGatewaysRepository->findByCode(Stripe::GATEWAY_CODE),
            );
            $this->salesFunnelsPaymentGatewaysRepository->add(
                salesFunnel: $funnel,
                paymentGateway: $this->paymentGatewaysRepository->findByCode(StripeRecurrent::GATEWAY_CODE),
            );
        }
    }
}
