<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Exception;
use LogicException;
use Stripe\Customer;
use Stripe\ApiResource;
use Stripe\SetupIntent;
use Stripe\Subscription;
use SilverStripe\Forms\Form;
use SilverStripe\Security\Member;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Security\Security;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\CompositeField;
use SilverCommerce\Discounts\DiscountFactory;
use SilverCommerce\ContactAdmin\Model\Contact;
use SilverStripe\Forms\ConfirmedPasswordField;
use SilverCommerce\Discounts\Forms\DiscountCodeForm;
use ilateral\SilverCommerce\StripeSubscriptions\StripeCardForm;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;

/**
 * Add extra actions to the checkout to handle stripe payment flow
 */
class SubscriptionCheckout extends StripeCheckout
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
        'DiscountForm',
        'PaymentForm'
    ];

    /**
     * The subscription object should generate a payment intent
     * unless the order is zero value. Then we need to setup
     * a card for later payment with a setup intent
     *
     * @param ApiResource $customer
     *
     * @return ApiResource
     */
    protected function getStripeIntentObject(ApiResource $customer): ApiResource
    {
        // Create a setup intent for the new card 
        $intent = StripeConnector::createOrUpdate(
            SetupIntent::class,
            [
                'customer' => $customer->id,
                'payment_method_types' => ['card'],
                'payment_method_options' => [
                    'card' => [
                        'request_three_d_secure' => 'automatic'
                    ]
                ],
                'usage' => 'off_session'
            ]
        );

        return $intent;
    }

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

            $stripe_contact = StripeConnector::createOrUpdate(
                Customer::class,
                $contact->getStripeData(),
                $contact->StripeID
            );

            // If not currently in stripe, connect now
            if (isset($stripe_contact) && isset($stripe_contact->id)) {
                $contact->StripeID = $stripe_contact->id;
                $contact->write();
            }

            // Now setup a subscription and get payment intent
            $sub = StripeConnector::createOrUpdate(
                Subscription::class,
                $estimate->getStripeData()
            );

            if (!isset($sub) || !isset($sub->latest_invoice)) {
                throw new LogicException("Error generating invoice");
            }

            $total = $estimate->getTotal();

            // If initial payment isn't 0 (free trial, coupon, etc) and no invoice
            // generated, raise an error
            if ($total > 0 && (!isset($sub->latest_invoice->payment_intent) || !isset($sub->latest_invoice->payment_intent->client_secret))) {
                throw new LogicException("Payment intent error");
            }

            $gateway_form = $this->GatewayForm();
            $payment_form = $this->PaymentForm();

            // If the current invoice has a value (not using a coupon or trial),
            // load the default payment form and setup intent. If no value,
            // simply add the card and save against the subscription
            if ($total > 0) {
                $intent_type = StripeCardForm::INTENT_PAYMENT;
                $intent = $sub->latest_invoice->payment_intent;
            } else {
                $intent_type = StripeCardForm::INTENT_SETUP;
                $intent = $this->getStripeIntentObject($stripe_contact);
                $payment_form
                    ->Fields()
                    ->push(LiteralField::create(
                        'ZeroValueInfo',
                        _t('StripeSubscriptions.ZeroValueInfo', 'Payment details for future payments')
                    ));
            }

            $estimate->StripeSubscriptionID = $sub->id;
            $estimate->write();

            $payment_form
                ->setPK($key)
                ->setIntent($intent_type)
                ->setSecret($intent->client_secret)
                ->loadDataFrom([
                    'cardholder-name' => $estimate->FirstName . ' ' . $estimate->Surname,
                    'cardholder-email' => $estimate->Email,
                    'cardholder-lineone' => $estimate->Address1,
                    'cardholder-zip' => $estimate->PostCode
                ]);

            $this->customise([
                "GatewayForm" => $gateway_form,
                "PaymentForm" => $payment_form
            ]);

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
     * Form that allows you to add a discount code which then gets added
     * to the cart's list of discounts.
     *
     * @return Form
     */
    public function DiscountForm(): DiscountCodeForm
    {
        // Allow replacing of existing discounts
        Config::modify()->set(DiscountFactory::class, 'allow_replacement', true);

        $form = DiscountCodeForm::create(
            $this,
            "DiscountForm",
            $this->getEstimate()
        );

        $this->extend("updateDiscountForm", $form);
        
        return $form;
    }

    /**
     * Overwrite default form and add a stripe setup form
     */
    public function PaymentForm(): StripeCardForm
    {
        $form = parent::PaymentForm();
        
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

                $invoice->SubscriptionID = $plan->ID;
            }
        }

        $invoice->write();

        // Write member to setup relevent groups
        $member->write();

        return $this->redirect($success_link);
    }
}
