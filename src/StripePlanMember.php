<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use DateTime;
use Stripe\Subscription;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use ilateral\SilverStripe\Users\Control\AccountController;

/**
 * Join object that handles mapping a Stripe Plan to a user.
 *
 * The stripe plan is joined via the Projuct stock ID, incase the plan product is accidentally
 * deleted.
 *
 * Finally, this plan is also tracked with an expirey time (setup at the point of payment/renewal)
 * that will determine if this subscription is still active.
 */
class StripePlanMember extends DataObject
{
    private static $table_name = "StripePlanMember";

    private static $db = [
        'PlanID' => 'Varchar', // Stock ID
        'Expires' => 'Datetime',
        'SubscriptionID' => 'Varchar' // Stripe Subscription ID
    ];

    private static $has_one = [
        'Member' => Member::class
    ];

    private static $summary_fields = [
        'PlanID',
        'Expires',
        'SubscriptionID'
    ];

    private static $field_labels = [
        'PlanID' => 'Plan'
    ];

    /**
     * Generate a link to renew this subscription
     *
     * @return string
     */
    public function getRenewLink()
    {
        $controller = Injector::inst()->get(AccountController::class);

        return Controller::join_links(
            $controller->Link('renewsubscription'),
            $this->PlanID,
            $this->SubscriptionID
        );
    }

    /**
     * Generate a link to cancel this subscription
     *
     * @return string
     */
    public function getCancelLink()
    {
        $controller = Injector::inst()->get(AccountController::class);

        return Controller::join_links(
            $controller->Link('cancelsubscription'),
            $this->PlanID,
            $this->SubscriptionID
        );
    }

    /**
     * Return the current linked plan (or an empty object if none found)
     *
     * @return StripePlan
     */
    public function getPlan()
    {
        $plan = StripePlan::get()->find('StockID', $this->PlanID);

        if (empty($plan)) {
            $plan = StripePlan::create(['ID' => -1]);
        }

        return $plan;
    }

    /**
     * Get the info about this subscription from stripe's API
     *
     * @return \Stripe\Subscription
     */
    public function getStripeSubscription()
    {
        StripeConnector::setStripeAPIKey();
        return Subscription::retrieve(
            [
                'id' => $this->SubscriptionID,
                'expand' => ['default_payment_method']
            ]
        );
    }

    public function getDefaultPaymentMethod()
    {
        $subscription = $this->getStripeSubscription();
        $card = $subscription->default_payment_method->card;

        if (isset($card)) {
            return str_pad($card->last4, 16, '*', STR_PAD_LEFT);
        }

        return "-";
    }

    /**
     * Is this plan expired (probably needs manual renewal)?
     *
     * @return bool
     */
    public function isExpired()
    {
        $now = new DateTime(
            DBDatetime::now()->format(DBDatetime::ISO_DATETIME)
        );
        $expirey = new DateTime($this->Expires);

        return ($now > $expirey);
    }
}
