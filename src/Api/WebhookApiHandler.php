<?php

declare(strict_types=1);

namespace Crm\StripeModule\Api;

use Crm\ApiModule\Models\Api\ApiHandler;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\StripeModule\Models\ConfigurationException;
use Nette\Http\IResponse;
use Nette\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Tomaj\Hermes\Emitter;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tracy\Debugger;

class WebhookApiHandler extends ApiHandler
{
    private string $webhookSecret;

    public function __construct(
        private readonly Emitter $hermesEmitter,
        private readonly Request $httpRequest,
    ) {
        parent::__construct();
    }

    public function params(): array
    {
        return [
            (new JsonInputParam('json', file_get_contents(__DIR__ . '/webhook.schema.json')))->setRequired(),
        ];
    }

    public function setWebhookSecret(string $webhookSecret): void
    {
        $this->webhookSecret = $webhookSecret;
    }

    public function handle(array $params): ResponseInterface
    {
        $signature = $this->httpRequest->getHeader('Stripe-Signature');

        if (!$signature) {
            Debugger::log("Stripe webhook request doesn't contain Stripe-Signature header", Debugger::ERROR);
            return new JsonApiResponse(IResponse::S400_BadRequest, [
                'status' => 'error',
                'code' => 'signature_missing',
                'message' => "Request doesn't contain Stripe-Signature header",
            ]);
        }

        if (!isset($this->webhookSecret)) {
            throw new ConfigurationException("Stripe webhook initialization error: Missing stripe webhook secret (whsec_). Did you intialize it through setWebhookSecret() setup directive in your config.neon?");
        }

        try {
            $event = Webhook::constructEvent(
                payload: $this->rawPayload(),
                sigHeader: $signature,
                secret: $this->webhookSecret,
            );
        } catch (SignatureVerificationException $e) {
            Debugger::log("Stripe webhook could not be validated: Invalid signature: {$e->getMessage()}", Debugger::ERROR);
            return new JsonApiResponse(IResponse::S400_BadRequest, [
                'status' => 'error',
                'code' => 'invalid_signature',
                'message' => 'Invalid signature: ' . $e->getMessage(),
            ]);
        }

        $this->hermesEmitter->emit(new HermesMessage('stripe-webhook-' . $event->type, [
            'event_id' => $event->id,
            'object_id' => $event->data->object->id,
        ]));

        return new JsonApiResponse(IResponse::S200_OK, [
            'status' => 'ok',
        ]);
    }
}
