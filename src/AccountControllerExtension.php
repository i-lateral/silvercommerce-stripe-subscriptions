<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Exception;
use Stripe\SetupIntent;
use Stripe\Subscription;
use Stripe\PaymentMethod;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Core\Extension;
use SilverStripe\View\ArrayData;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverCommerce\Checkout\Control\Checkout;
use SilverCommerce\OrdersAdmin\Factory\OrderFactory;
use Stripe\Checkout\Session as StripeCheckoutSession;
use ilateral\SilverStripe\Users\Control\AccountController;
use ilateral\SilverCommerce\StripeSubscriptions\StripePlanMember;

class AccountControllerExtension extends Extension
{
    private static $allowed_actions = [
        "paymentdetails",
        "addcard",
        "StripeCardForm",
        "attachcard",
        "removecard",
        "subscriptions",
        "renewsubscription",
        "cancelsubscription"
    ];

    public function paymentdetails()
    {
        StripeConnector::setStripeAPIKey(StripeConnector::KEY_SECRET);
        $member = Security::getCurrentUser();
        $cards = $member->getStripePaymentCards();

        return $this
            ->getOwner()
            ->customise(
                [
                    "Title" => _t(
                        "StripeSubscriptions.PaymentDetails",
                        "Payment Details"
                    ),
                    "MetaTitle" => _t(
                        "StripeSubscriptions.PaymentDetails",
                        "Payment Details"
                    ),
                    "Content" => $this
                        ->getOwner()
                        ->renderWith(
                            __NAMESPACE__ . '\Includes\AccountPaymentDetails',
                            ['Cards' => $cards]
                        )
                ]
            )->render();
    }

    /**
     * Use stripe checkout to create a new add payment method intent (which will then be completed via `attachcard`)
     *
     * @return HTTPResponse
     */
    public function addcard()
    {
        /** @var AccountController */
        $owner = $this->getOwner();
        $member = Security::getCurrentUser();
        $contact = $member->Contact();

        // Create a setup intent for the new card 
        $intent = StripeConnector::createOrUpdate(
            SetupIntent::class,
            [
                'customer' => $contact->StripeID,
                'payment_method_types' => ['card'],
                'payment_method_options' => [
                    'card' => [
                        'request_three_d_secure' => 'automatic'
                    ]
                ],
                'usage' => 'off_session'
            ]
        );

        if (empty($intent) || !isset($intent->client_secret)) {
            return $owner->httpError(500);
        }

        $form = $this->StripeCardForm()
            ->setPK(StripeConnector::getStripeAPIKey())
            ->setIntent(StripeCardForm::INTENT_SETUP)
            ->setSecret($intent->client_secret)
            ->setBackURL($owner->Link('paymentdetails'))
            ->loadDataFrom([
                'cardholder-name' => $contact->FirstName . ' ' . $contact->Surname,
                'cardholder-email' => $contact->Email
            ]);

        return $owner
            ->customise(
                [
                    "Title" => _t(
                        "StripeSubscriptions.AddNewCard",
                        "Add New Card"
                    ),
                    "MetaTitle" => _t(
                        "StripeSubscriptions.AddNewCard",
                        "Add New Card"
                    ),
                    'Form' => $form
                ]
            )->render();
    }

    public function StripeCardForm(): StripeCardForm
    {
        return StripeCardForm::create($this->getOwner());
    }

    /**
     * Remove a selected card (for the current user) and redirect to payment details
     *
     * @return HTTPResponse
     */
    public function removecard()
    {
        /** @var AccountController */
        $owner = $this->getOwner();
        $card_id = $owner->getRequest()->param('ID');

        try {
            /** @var PaymentMethod */
            $payment_method = StripeConnector::retrieve(PaymentMethod::class, $card_id);
            $payment_method->detach();
        } catch (Exception $e) {
            return $owner->httpError(500, $e->getMessage());
        }

        return $owner->redirect($owner->Link('paymentdetails'));
    }

    public function subscriptions()
    {
        return $this
            ->getOwner()
            ->customise(
                [
                    "Title" => _t(
                        "StripeSubscriptions.Subscriptions",
                        "Subscriptions"
                    ),
                    "MetaTitle" => _t(
                        "StripeSubscriptions.Subscriptions",
                        "Subscriptions"
                    ),
                    "Content" => $this
                        ->getOwner()
                        ->renderWith(__NAMESPACE__ . '\Includes\AccountSubscriptions')
                ]
            )->render();
    }

    /**
     * Generate an invoice to renew this subscription
     */
    public function renewsubscription()
    {
        /** @var AccountController */
        $owner = $this->getOwner();

        $request = $owner->getRequest();
        $product_id = $request->param('ID');
        $product = StripePlan::get()->find('StockID', $product_id);

        if (empty($product)) {
            return $owner->httpError('404');
        }

        $factory = OrderFactory::create();
        $factory->addItem($product);
        $factory->write();

        $checkout = Injector::inst()->get(SubscriptionCheckout::class);
        $checkout->setEstimate($factory->getOrder());

        return $this->getOwner()->redirect($checkout->Link());
    }

    /**
     * Cancel this subscription
     */
    public function cancelsubscription()
    {
        /** @var AccountController */
        $owner = $this->getOwner();

        $member = Security::getCurrentUser();
        $request = $owner->getRequest();
        $product_id = $request->param('ID');
        $subscription_id = $request->param('OtherID');

        /** @var StripePlanMember */
        $plan = $member
            ->getStripePlans()
            ->filter(
                [
                    'PlanID' => $product_id,
                    'SubscriptionID' => $subscription_id
                ]
            )->first();

        if (empty($plan)) {
            return $owner->httpError('404');
        }

        try {
            $plan->cancelSubscription();
        } catch (Exception $e) {
            return $owner->httpError(500, $e->getMessage());
        }

        return $owner->redirect($this->getOwner()->Link('subscriptions'));
    }

    public function updateAccountMenu($menu)
    {
        $curr_action = $this->owner->request->param("Action");
        
        foreach ($menu as $item) {
            if (in_array($item->ID, array(0, 1, 2, 30))) {
                $menu->remove($item);
            }
        }

        $menu->add(ArrayData::create(
            [
                "ID"    => 22,
                "Title" => _t('StripeSubscriptions.PaymentDetails', "Payment Details"),
                "Link"  => $this->owner->Link("paymentdetails"),
                "LinkingMode" => ($curr_action == "paymentdetails") ? "current" : "link"
            ]
        ));

        $menu->add(ArrayData::create(
            [
                "ID"    => 23,
                "Title" => _t('StripeSubscriptions.Subscriptions', "Subscriptions"),
                "Link"  => $this->owner->Link("subscriptions"),
                "LinkingMode" => ($curr_action == "subscriptions") ? "current" : "link"
            ]
        ));
    }
}
