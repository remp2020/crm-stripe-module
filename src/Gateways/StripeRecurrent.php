<?php

namespace Crm\StripeModule\Gateways;

use Crm\PaymentsModule\Gateways\RecurrentPaymentInterface;
use Crm\PaymentsModule\RecurrentPaymentFailStop;
use Crm\PaymentsModule\RecurrentPaymentFailTry;
use Money\Currency;
use Omnipay\Common\Exception\InvalidRequestException;
use Stripe\ErrorObject;
use Stripe\PaymentIntent;

class StripeRecurrent extends AbstractStripe implements RecurrentPaymentInterface
{
    public const GATEWAY_CODE = 'stripe_recurrent';

    /** @var ErrorObject */
    private $paymentIntentError;

    public function begin($payment)
    {
        $this->initialize();

        // check if there's payment method already associated (by collecting card data on frontend)
        $paymentMethodId = $this->paymentMetaRepository->values($payment, 'payment_method_id')->fetchField('value');
        if ($paymentMethodId) {
            $this->processSetupIntent($paymentMethodId, $payment, 'off_session');
        }

        $this->processCheckout($payment, 'off_session');
    }

    public function charge($payment, $token): string
    {
        $this->initialize();

        $stripeCustomerId = $this->userMetaRepository->userMetaValueByKey($payment->user, 'stripe_customer');

        try {
            $currency = new Currency($this->applicationConfig->get('currency'));
            $this->paymentIntent = PaymentIntent::create([
                'amount' => $this->calculateStripeAmount($payment->amount, $currency),
                'currency' => $currency->getCode(),
                'customer' => $stripeCustomerId,
                'payment_method' => $token,
                'confirm' => true,
                'off_session' => true,
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            $this->paymentIntentError = $e->getError();
            $paymentIntentId = $e->getError()->payment_intent->id;
            $this->paymentIntent = PaymentIntent::retrieve($paymentIntentId);
        }

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
    public function getResultCode()
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
    public function getResultMessage()
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
