<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Exception;
use LogicException;
use NumberFormatter;
use Stripe\Customer;
use Stripe\PaymentIntent;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Core\Config\Config;
use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\SiteConfig\SiteConfig;
use SilverCommerce\Checkout\Control\Checkout;
use ilateral\SilverCommerce\StripeSubscriptions\StripeCardForm;
use ilateral\SilverCommerce\StripeSubscriptions\StripeConnector;
use Stripe\ApiResource;

class StripeCheckout extends Checkout
{
    /**
     * @var string
     */
    private static $url_segment = 'stripecheckout';

    private static $allowed_actions = [
        'payment',
        'PaymentForm'
    ];

    /**
     * Setup the intent for this checkout to be used by the payment
     * form. For a one of payment, this would be a Payment Intent,
     * but could also be a setup intent (if storing data for a later charge)
     *
     * @param ApiResource $customer
     *
     * @return ApiResource
     */
    protected function getStripeIntentObject(ApiResource $customer): ApiResource
    {
        $config = SiteConfig::current_site_config();
        $estimate = $this->getEstimate();

        // Get three digit currency code
        $number_format = new NumberFormatter(
            $config->SiteLocale,
            NumberFormatter::CURRENCY
        );
        $currency_code = $number_format
            ->getTextAttribute(NumberFormatter::CURRENCY_CODE);

        $intent = StripeConnector::createOrUpdate(
            PaymentIntent::class,
            [
                'amount' => round($estimate->Total * 100),
                'currency' => $currency_code,
                'description' => _t(
                    "Order.PaymentDescription",
                    "Payment for Order: {ordernumber}",
                    ['ordernumber' => $estimate->FullRef]
                ),
                'customer' => $customer->id,
                'metadata' => ['integration_check' => 'accept_a_payment'],
            ]
        );

        return $intent;
    }

    /**
     * Overwrite default payment to setup a payment intent
     */
    public function payment()
    {
        // If no estimate found, generate error
        if (!$this->hasEstimate()) {
            return $this->redirect($this->Link("noestimate"));
        }

        $estimate = $this->getEstimate();
        $key = StripeConnector::getStripeAPIKey();

        // If estimate does not have a shipping address, restart checkout
        if (empty(trim($estimate->BillingAddress))) {
            return $this->redirect($this->Link());
        }

        // If estimate is deliverable and has no billing details,
        // restart checkout
        if ($estimate->isDeliverable() && empty(trim($estimate->DeliveryAddress))) {
            return $this->redirect($this->Link());
        }

        if (!$estimate->Items()->exists()) {
            return $this->httpError(500, "Invalid products");
        }

        // Setup customer and subscription and setup payment form
        try {
            /** @var Contact */
            $contact = $estimate->Customer();

            $stripe_customer = StripeConnector::createOrUpdate(
                Customer::class,
                $contact->getStripeData(),
                $contact->StripeID
            );

            // If not currently in stripe, connect now
            if (isset($stripe_customer) && isset($stripe_customer->id)) {
                $contact->StripeID = $stripe_customer->id;
                $contact->write();
            }

            // If estimate has zero value, then automatically generate
            // a payment, mark as paid and complete
            if ($estimate->getTotal() == 0) {
                $zero_gateway = $this->config()->zero_gateway;
        
                Config::modify()->set(
                    Payment::class,
                    'allowed_gateways',
                    [$zero_gateway]
                );
            
                return $this->doSubmitPayment([], $this->PaymentForm());
            }

            // Now setup a one off payment
            $intent = $this->getStripeIntentObject($stripe_customer);

            if (!isset($intent) || !isset($intent->client_secret)) {
                throw new LogicException("Error setting up payment");
            }

            $estimate->StripeIntentID = $intent->id;
            $estimate->write();

            $gateway_form = $this->GatewayForm();
            $payment_form = $this
                ->PaymentForm()
                ->setPK($key)
                ->setIntent(StripeCardForm::INTENT_PAYMENT)
                ->setSecret($intent->client_secret)
                ->loadDataFrom([
                    'cardholder-name' => $estimate->FirstName . ' ' . $estimate->Surname,
                    'cardholder-email' => $estimate->Email,
                    'cardholder-lineone' => $estimate->Address1,
                    'cardholder-zip' => $estimate->PostCode
                ]);

            $this->customise(
                [
                    "GatewayForm" => $gateway_form,
                    "PaymentForm" => $payment_form
                ]
            );

            $this->extend("onBeforePayment");

            return $this->render();
        } catch (Exception $e) {
            return $this->httpError(
                400,
                $e->getMessage()
            );
        }
    }

    /**
     * Gateway form isn't required in this checkout session
     *
     * @return Form
     */
    public function GatewayForm()
    {
        return Form::create(
            $this,
            'GatewayForm',
            FieldList::create(
                HiddenField::create('PaymentMethodID')
            ),
            FieldList::create()
        );
    }

    /**
     * Overwrite default form and add a stripe setup form
     */
    public function PaymentForm(): StripeCardForm
    {
        $form = StripeCardForm::create($this, 'PaymentForm', true, true);

        $form
            ->Actions()
            ->fieldByName('action_doSubmitCardForm')
            ->setTitle(_t('StripeSubscriptions.PayNow', "Pay Now"));

        return $form;
    }

    /**
     * Get the submitted payment intent and setup the subscription this end
     *
     * @param array $data Submitted form data
     * @param Form $form Current form
     *
     */
    public function doSubmitCardForm(array $data, StripeCardForm $form)
    {
        $estimate = $this->getEstimate();
        $success_link = $this->getOwner()->Link('complete');
        $error_link = $this->Link('complete/error');

        if (!isset($data['intentid']) || !isset($data['intent'])) {
            return $this->redirect($error_link);
        }

        $intent_id = $data['intentid'];
        $intent = StripeConnector::retrieve(
            $form->getClassFromIntent($data['intent']),
            $intent_id
        );

        if (empty($intent) || !$intent->status == 'succeeded') {
            return $this->redirect($error_link);
        }

        // Convert estimate to invoice
        $invoice = $estimate->convertToInvoice();
        $invoice->markPaid();
        $invoice->write();

        return $this->redirect($success_link);
    }
}