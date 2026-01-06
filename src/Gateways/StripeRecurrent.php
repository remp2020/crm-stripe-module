<?php

namespace Crm\StripeModule\Gateways;

use Crm\PaymentsModule\Models\Gateways\RecurrentPaymentInterface;
use Crm\PaymentsModule\Models\RecurrentPaymentFailStop;
use Crm\PaymentsModule\Models\RecurrentPaymentFailTry;
use Crm\StripeModule\Models\PaymentMeta;
use Omnipay\Common\Exception\InvalidRequestException;
use Stripe\ErrorObject;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;

class StripeRecurrent extends AbstractStripe implements RecurrentPaymentInterface
{
    public const GATEWAY_CODE = 'stripe_recurrent';

    private ErrorObject $paymentIntentError;

    public function begin($payment): void
    {
        // check if there's payment method already associated (by collecting card data on frontend)
        $paymentMethodId = $this->paymentMetaRepository
            ->findByPaymentAndKey($payment, PaymentMeta::PAYMENT_METHOD_ID)
            ?->value;
        if ($paymentMethodId) {
            $this->processSetupIntent($paymentMethodId, $payment, PaymentIntent::SETUP_FUTURE_USAGE_OFF_SESSION);
        }

        $this->processCheckout($payment, PaymentIntent::SETUP_FUTURE_USAGE_OFF_SESSION);
    }

    public function charge($payment, $token): string
    {
        try {
            $stripeCustomer = $this->stripeService->getCustomerByUser($payment->user);
            $stripePaymentMethod = $this->stripeService->retrievePaymentMethod($token);

            $this->paymentIntent = $this->stripeService->createPaymentIntent(
                paymentMethod: $stripePaymentMethod,
                customer: $stripeCustomer,
                amount: (float) $payment->amount,
                offSession: true,
            );
        } catch (CardException $e) {
            $this->paymentIntentError = $e->getError();
            $paymentIntentId = $e->getError()->payment_intent->id;
            $this->paymentIntent = $this->stripeService->retrievePaymentIntent($paymentIntentId);
        }

        $this->paymentMetaRepository->add($payment, PaymentMeta::PAYMENT_INTENT_ID, $this->paymentIntent->id);

        if ($this->paymentIntent->status !== PaymentIntent::STATUS_SUCCEEDED) {
            if ($this->hasStopRecurrentPayment($payment, $this->paymentIntent->status)) {
                throw new RecurrentPaymentFailStop();
            }
            throw new RecurrentPaymentFailTry();
        }

        return self::CHARGE_OK;
    }

    /**
     * @inheritDoc
     */
    public function checkValid($token)
    {
        throw new InvalidRequestException("stripe recurrent gateway doesn't support checking if token is still valid");
    }

    /**
     * @inheritDoc
     */
    public function checkExpire($recurrentPayments)
    {
        throw new InvalidRequestException("stripe recurrent gateway doesn't support token expiration checking (it should never expire)");
    }

    /**
     * @inheritDoc
     */
    public function hasRecurrentToken(): bool
    {
        return isset($this->paymentIntent->payment_method);
    }

    /**
     * @inheritDoc
     */
    public function getRecurrentToken()
    {
        return $this->paymentIntent->payment_method;
    }

    /**
     * @inheritDoc
     */
    public function getResultCode(): ?string
    {
        if (isset($this->paymentIntent->last_payment_error['code'])) {
            return sprintf('%s: %s', $this->paymentIntent->last_payment_error['code'], $this->paymentIntent->last_payment_error['decline_code']);
        }
        if (isset($this->paymentIntentError)) {
            return $this->paymentIntentError->message;
        }
        return $this->paymentIntent->status;
    }

    /**
     * @inheritDoc
     */
    public function getResultMessage(): ?string
    {
        if (isset($this->paymentIntent->last_payment_error['message'])) {
            return $this->paymentIntent->last_payment_error['message'];
        }
        if (isset($this->paymentIntentError)) {
            return $this->paymentIntentError->message;
        }
        return $this->paymentIntent->status;
    }
}
