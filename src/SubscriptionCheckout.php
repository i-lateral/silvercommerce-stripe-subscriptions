<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Exception;
use LogicException;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\PaymentIntent;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FieldList;
use SilverStripe\Security\Member;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Security\Security;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\CompositeField;
use SilverCommerce\Checkout\Control\Checkout;
use SilverCommerce\ContactAdmin\Model\Contact;
use SilverStripe\Forms\ConfirmedPasswordField;
use ilateral\SilverCommerce\StripeSubscriptions\StripeCardForm;

/**
 * Add extra actions to the checkout to handle stripe payment flow
 */
class SubscriptionCheckout extends Checkout
{

    /**
     * URL Used to generate links to this controller.
     *
     * NOTE If you alter routes.yml, you MUST alter this.
     * \SilverStripe\GraphQL\Auth\MemberAuthenticator;
     *
     * @var    string
     * @config
     */
    private static $url_segment = 'setupsubscription';

    private static $allowed_actions = [
        'payment',
        'CustomerForm',
        'PaymentForm'
    ];

    /**
     * Overwrite default payment setup to allow for setup intents and loading a custom payment form
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

            $stripe_obj = StripeConnector::createOrUpdate(
                Customer::class,
                $contact->getStripeData(),
                $contact->StripeID
            );

            // If not currently in stripe, connect now
            if (isset($stripe_obj) && isset($stripe_obj->id)) {
                $contact->StripeID = $stripe_obj->id;
                $contact->write();
            }

            // Now setup a subscription and get payment intent
            $sub = StripeConnector::createOrUpdate(
                Subscription::class,
                $estimate->getStripeData()
            );


            if (!isset($sub) || !isset($sub->latest_invoice)
                || !isset($sub->latest_invoice->payment_intent)
                || !isset($sub->latest_invoice->payment_intent->client_secret)
            ) {
                throw new LogicException("Error setting up payment");
            }

            $estimate->StripeSubscriptionID = $sub->id;
            $estimate->write();

            $gateway_form = $this->GatewayForm();
            $payment_form = $this
                ->PaymentForm()
                ->setPK($key)
                ->setIntent(StripeCardForm::INTENT_PAYMENT)
                ->setSecret($sub->latest_invoice->payment_intent->client_secret)
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
     * Overwrite default customer form to require account creation
     * (if not logged in)
     *
     * @return CustomerDetailsForm
     */
    public function CustomerForm()
    {
        $form = parent::CustomerForm();
        $fields = $form->Fields();

        $password_field = $fields->dataFieldByName('Password');
        $member = Security::getCurrentUser();

        if (empty($password_field) && empty($member)) {
            $fields->add(
                CompositeField::create(
                    HeaderField::create(
                        'CreateAccountHeader',
                        _t('SilverCommerce\Checkout.CreateAccountRequired', 'Create Account (Required)'),
                        3
                    ),
                    $pw_field = ConfirmedPasswordField::create("Password")
                        ->setAttribute('formnovalidate', true)
                )->setName("PasswordFields")
            );
        }

        return $form;
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
            ->setTitle(_t('StripeSubscriptions.SignUpNow', "Sign Up Now"));

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
        $subscription_id = $estimate->StripeSubscriptionID;
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

        if (empty($intent)) {
            return $this->redirect($error_link);
        }

        // Convert estimate to invoice
        $invoice = $estimate->convertToInvoice();
        $invoice->markPaid();
        $invoice->write();

        // Update new subscription's default payment method
        StripeConnector::createOrUpdate(
            Subscription::class,
            ['default_payment_method' => $intent->payment_method],
            $subscription_id
        );
    
        /** @var Contact */
        $contact = $invoice->Customer();
        /** @var Member */
        $member = $contact->Member();

        foreach ($invoice->Items() as $item) {
            $product = $item->findStockItem();

            if (isset($product) && isset($contact) && is_a($product, StripePlan::class)) {
                $plan = $contact
                    ->StripePlans()
                    ->find('SubscriptionID', $subscription_id);

                if (empty($plan)) {
                    $plan = StripePlanMember::create(
                        [
                            'PlanID' => $product->StockID,
                            'Expires' => $product->getExpireyDate(),
                            'SubscriptionID' => $subscription_id
                        ]
                    );

                    $plan->ContactID = $contact->ID;
                }

                $plan->Status = 'active';
                $plan->write();
            }
        }

        // Write member to setup relevent groups
        $member->write();

        return $this->redirect($success_link);
    }
}
