<?php

namespace Crm\StripeModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Api\JsonResponse;
use Crm\ApiModule\Authorization\ApiAuthorizationInterface;
use Crm\StripeModule\Gateways\StripeRecurrent;
use Nette\Http\Response;

class SetupIntentHandler extends ApiHandler
{
    private $stripe;

    public function __construct(StripeRecurrent $stripe)
    {
        $this->stripe = $stripe;
    }

    public function params()
    {
    }

    public function handle(ApiAuthorizationInterface $authorization)
    {
        $intent = $this->stripe->createSetupIntent();

        $response = new JsonResponse([
            'id' => $intent->id,
            'client_secret' => $intent->client_secret,
        ]);
        $response->setHttpCode(Response::S200_OK);
        return $response;
    }
}
