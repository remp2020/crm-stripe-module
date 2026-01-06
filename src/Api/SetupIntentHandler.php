<?php

namespace Crm\StripeModule\Api;

use Crm\ApiModule\Models\Api\ApiHandler;
use Crm\StripeModule\Models\StripeService;
use Nette\Http\Response;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

class SetupIntentHandler extends ApiHandler
{
    public function __construct(
        private readonly StripeService $stripeService,
    ) {
        parent::__construct();
    }

    public function params(): array
    {
        return [];
    }

    public function handle(array $params): ResponseInterface
    {
        $setupIntent = $this->stripeService->createSetupIntent();

        $response = new JsonApiResponse(Response::S200_OK, [
            'id' => $setupIntent->id,
            'client_secret' => $setupIntent->client_secret,
        ]);
        return $response;
    }
}
