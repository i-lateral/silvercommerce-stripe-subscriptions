<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Exception;
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
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\Subscription;

class AccountControllerExtension extends Extension
{
    private static $allowed_actions = [
        "paymentdetails",
        "addcard",
        "attachcard",
        "removecard",
        "subscriptions",
        "renewsubscription",
        "cancelsubscription"
    ];

    public function paymentdetails()
    {
        StripeConnector::setStripeAPIKey();
        $member = Security::getCurrentUser();
        $stripe_id = $member->StripeCustomerID;
        $cards = ArrayList::create();

        $raw_cards = PaymentMethod::all(
            [
                'customer' => $stripe_id,
                'type' => 'card'
            ]
        );

        // Loop through raw stripe card data for this
        foreach ($raw_cards->data as $card) {
            $cards->add(
                ArrayData::create(
                    [
                        'ID' => $card->id,
                        'CardNumber' => str_pad($card->card->last4, 16, '*', STR_PAD_LEFT),
                        'Expires' => $card->card->exp_month . '/' . $card->card->exp_year,
                        'Brand' => $card->card->brand,
                        'RemoveLink' => $this->getOwner()->Link('removecard') . '/' . $card->id
                    ]
                )
            );
        }

        $this->getOwner()->customise(
            [
                "Title" => _t(
                    "App.PaymentDetails",
                    "Payment Details"
                ),
                "MetaTitle" => _t(
                    "App.PaymentDetails",
                    "Payment Details"
                ),
                "Content" => $this
                    ->getOwner()
                    ->renderWith('Includes\AccountPaymentDetails', ['Cards' => $cards])
            ]
        );

        return $this->getOwner()->render();
    }

    /**
     * Use stripe checkout to create a new add payment method intent (which will then be completed via `attachcard`)
     *
     * @return HTTPResponse
     */
    public function addcard()
    {
        // Build a stripe checkout session and redirect
        StripeConnector::setStripeAPIKey();
        $member = Security::getCurrentUser();
        $checkout = StripeCheckoutSession::create(
            [
                'payment_method_types' => ['card'],
                'mode' => 'setup',
                'setup_intent_data' => [
                    'metadata' => ['customer_id' => $member->StripeCustomerID]
                ],
                'success_url' => $this->getOwner()->AbsoluteLink('attachcard') . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->getOwner()->AbsoluteLink('paymentdetails')
            ]
        );
        $key = Config::inst()->get(StripePlanProduct::class, 'publish_key');
        $session_id = $checkout->id;

        Requirements::javascript("https://js.stripe.com/v3/");
        Requirements::customScript(<<<JS
var stripe = Stripe("$key");

stripe
    .redirectToCheckout({sessionId: "$session_id"})
    .then(function (result) { alert(result.error.message); });
JS
        );

        return $this->getOwner()->render();
    }

    /**
     * Attach the card added via `addcard` to the current user and then redirect to payment
     * details screen
     *
     * @return HTTPResponse
     */
    public function attachcard()
    {
        StripeConnector::setStripeAPIKey();
        $member = Security::getCurrentUser();
        $stripe_id = $member->StripeCustomerID;

        // First, setup any newley added cards and attach to the current user
        $session_id = $this->getOwner()->getRequest()->getVar('session_id');

        if (!empty($session_id)) {
            try {
                $checkout = StripeCheckoutSession::retrieve($session_id);
                $setup_intent = SetupIntent::retrieve($checkout->setup_intent);
                $payment_method = PaymentMethod::retrieve($setup_intent->payment_method);
                $payment_method->attach(['customer' => $stripe_id]);
            } catch (Exception $e) {
                return $this->getOwner()->httpError(500, "Error seting up card");
            }
        }

        return $this->getOwner()->redirect($this->getOwner()->Link('paymentdetails'));
    }

    /**
     * Remove a selected card (for the currenty user) and redirect to payment details
     *
     * @return HTTPResponse
     */
    public function removecard()
    {
        StripeConnector::setStripeAPIKey();
        $card = $this->getOwner()->getRequest()->param('ID');

        try {
            $payment_method = PaymentMethod::retrieve($card);
            $payment_method->detach();
        } catch (Exception $e) {
            return $this->getOwner()->httpError(500, $e->getMessage());
        }

        return $this->getOwner()->redirect($this->getOwner()->Link('paymentdetails'));
    }

    public function subscriptions()
    {
        $this->getOwner()->customise(
            [
                "Title" => _t(
                    "App.Subscriptions",
                    "Subscriptions"
                ),
                "MetaTitle" => _t(
                    "App.Subscriptions",
                    "Subscriptions"
                ),
                "Content" => $this
                    ->getOwner()
                    ->renderWith('Includes\AccountSubscriptions')
            ]
        );

        return $this->getOwner()->render();
    }

    /**
     * Generate an invoice to renew this subscription
     */
    public function renewsubscription()
    {
        $request = $this->getOwner()->getRequest();
        $product_id = $request->param('ID');
        $product = StripePlan::get()->find('StockID', $product_id);

        if (empty($product)) {
            return $this->getOwner()->httpError('404');
        }

        $factory = OrderFactory::create();
        $factory->addItem($product);
        $factory->write();

        $checkout = Injector::inst()->get(Checkout::class);
        $checkout->setEstimate($factory->getOrder());

        return $this->getOwner()->redirect($checkout->Link());
    }

    /**
     * Cancel this subscription
     */
    public function cancelsubscription()
    {
        StripeConnector::setStripeAPIKey();
        $member = Security::getCurrentUser();
        $request = $this->getOwner()->getRequest();
        $product_id = $request->param('ID');
        $subscription_id = $request->param('OtherID');
        $plan = $member
            ->StripePlans()
            ->filter(
                [
                    'PlanID' => $product_id,
                    'SubscriptionID' => $subscription_id
                ]
            )->first();

        if (empty($plan)) {
            return $this->getOwner()->httpError('500');
        }

        try {
            $subscription = Subscription::retrieve($subscription_id);
            $subscription->delete();
            $plan->delete();
        } catch (Exception $e) {
            return $this->getOwner()->httpError(500);
        }

        return $this->getOwner()->redirect($this->getOwner()->Link('subscriptions'));
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
