<?php

namespace Crm\StripeModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\StripeModule\Gateways\StripeRecurrent;
use Nette\Http\Response;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

class SetupIntentHandler extends ApiHandler
{
    private $stripe;

    public function __construct(StripeRecurrent $stripe)
    {
        $this->stripe = $stripe;
    }

    public function params(): array
    {
        return [];
    }

    public function handle(array $params): ResponseInterface
    {
        $intent = $this->stripe->createSetupIntent();

        $response = new JsonApiResponse(Response::S200_OK, [
            'id' => $intent->id,
            'client_secret' => $intent->client_secret,
        ]);
        return $response;
    }
}
