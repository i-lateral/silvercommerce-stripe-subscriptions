<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Stripe\Customer;
use Stripe\SetupIntent;
use Stripe\Subscription;
use Stripe\PaymentIntent;
use SilverStripe\View\HTML;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FieldList;
use SilverStripe\Security\Member;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\CompositeField;
use SilverCommerce\Checkout\Control\Checkout;
use SilverCommerce\ContactAdmin\Model\Contact;
use SilverStripe\Forms\ConfirmedPasswordField;
use Stripe\Checkout\Session as StripeCheckoutSession;

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
        $order = $this->getEstimate();
        /** @var Contact */
        $contact = $order->Customer();

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

        return parent::payment();
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
    public function PaymentForm()
    {
        Requirements::javascript("https://js.stripe.com/v3/");
        Requirements::javascript("i-lateral/silvercommerce-stripe-subscriptions:client/dist/stripe.js");

        $key = StripeConnector::getStripeAPIKey();
        $estimate = $this->getEstimate();

        if (!$estimate->Items()->exists()) {
            return $this->httpError(500, "Invalid products");
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
            return $this->httpError(500, "Error setting up payment");
        }

        $estimate->StripeSubscriptionID = $sub->id;
        $estimate->write();

        $form = Form::create(
            $this,
            'PaymentForm',
            FieldList::create(
                HiddenField::create('cardholder-name')
                    ->setValue($estimate->FirstName . ' ' . $estimate->Surname),
                HiddenField::create('cardholder-email')
                    ->setValue($estimate->Email),
                HiddenField::create('cardholder-lineone')
                    ->setValue($estimate->Address1),
                HiddenField::create('cardholder-zip')
                    ->setValue($estimate->PostCode),
                HiddenField::create("payment-intent"),
                LiteralField::create(
                    'StripeFields',
                    $this->renderWith(__NAMESPACE__ . '\StripePaymentFields')
                )
            ),
            FieldList::create(
                FormAction::create('doSubscribe', _t('StripeSubscriptions.SignUpNow', "Sign Up Now"))
                    ->setUseButtonTag(true)
                    ->addExtraClass('btn btn-lg btn-primary w-100')
            )
        );

        $form
            ->setAttribute('data-stripepk', $key)
            ->setAttribute('data-secret', $sub->latest_invoice->payment_intent->client_secret);

        return $form;
    }

    /**
     * Get the submitted payment intent and setup the subscription this end
     *
     * @param array $data Submitted form data
     * @param Form $form Current form
     *
     */
    public function doSubscribe(array $data, Form $form)
    {
        $estimate = $this->getEstimate();
        $subscription_id = $estimate->StripeSubscriptionID;
        $success_link = $this->getOwner()->Link('complete');
        $error_link = $this->Link('complete/error');

        if (!isset($data['payment-intent'])) {
            return $this->redirect($error_link);
        }

        $intent_id = $data['payment-intent'];
        $intent = StripeConnector::retrieve(PaymentIntent::class, $intent_id);

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

            if (isset($product) && isset($contact) && is_a($product, StripePlanProduct::class)) {
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
                    $plan->write();
                }
            }
        }

        // Write member to setup relevent groups
        $member->write();

        return $this->redirect($success_link);
    }
}
