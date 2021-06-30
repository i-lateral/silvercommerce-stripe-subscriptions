<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use SilverStripe\Core\Extension;
use SilverStripe\View\Requirements;
use SilverStripe\Core\Config\Config;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Subscription;

/**
 * Add extra actions to the checkout to handle stripe payment flow
 */
class CheckoutExtension extends Extension
{
    private static $allowed_actions = [
        'striperedirect',
        'stripecomplete'
    ];

    /**
     * Load the redirect JS based on the current estimate session ID
     * setup in CustomCustomerDetailsForm::doComplete()
     *
     */
    public function striperedirect()
    {
        $key = Config::inst()->get(StripePlanProduct::class, 'publish_key');
        $session_id = $this->getOwner()->getEstimate()->StripeSessionID;

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
     * Process the completed payment, check with stripe that it has been
     * paid (via the API) and then convert the estimate to an order and
     * redirect.
     *
     */
    public function stripecomplete()
    {
        $session_id = $this->getOwner()->getRequest()->getVar('session_id');
        $estimate = $this->getOwner()->getEstimate();
        StripePlan::setStripeAPIKey();
        $success = false;

        // If the returned session ID does not match the estimate ID for this checkout
        // throw an error
        if ($session_id != $estimate->StripeSessionID) {
            return $this->getOwner()->httpError(500);
        }
        
        // Get session and complete order (if starting or renewing manually)
        $session = StripeCheckoutSession::retrieve(
            [
                'id' => $session_id,
                'expand' => ['setup_intent', 'setup_intent.payment_method', 'subscription']
            ]
        );

        // Attempt to retrieve a subscription ID from the returned session
        $subscription = $session->subscription;
        $intent = $session->setup_intent;
        $subscription_id = null;

        if (!empty($subscription)) {
            $subscription_id = $subscription->id;
        } elseif (!empty($intent) && isset($intent->metadata->subscription_id)) {
            $subscription_id = $intent->metadata->subscription_id;
        }

        // First convert estimate to invoice
        $invoice = $estimate->convertToInvoice();
        $invoice->markPaid();
        $invoice->write();
        $member = $invoice->Customer()->Member();
        
        // If payment intent, attach payment method to customer and subscription
        if (!empty($intent)) {
            $method = $intent->payment_method;
            $method->attach(['customer' => $member->StripeCustomerID]);

            if (isset($subscription_id)) {
                Subscription::update(
                    $subscription_id,
                    ['default_payment_method' => $intent->payment_method->id]
                );
            }
        }

        // If we are tracking a subscription, then find the plan product and attach to the member
        if (isset($subscription_id)) {
            foreach ($invoice->Items() as $item) {
                $product = $item->findStockItem();

                if (isset($product) && isset($member) && is_a($product, StripePlanProduct::class)) {
                    $plan = $member->StripePlans()->find('SubscriptionID', $subscription_id);

                    if (empty($plan)) {
                        $plan = StripePlanMember::create(
                            [
                                'PlanID' => $product->StockID,
                                'Expires' => $product->getExpireyDate(),
                                'SubscriptionID' => $subscription_id
                            ]
                        );
                    }

                    $plan->MemberID = $member->ID;
                    $plan->write();
                }
            }

            // Write member to setup relevent groups
            $member->write();
        }

        $success = true;

        if ($success) {
            $link = $this->getOwner()->Link('complete');
        } else {
            $link = $this->getOwner()->Link('complete/error');
        }

        return $this->getOwner()->redirect($link);
    }
}
