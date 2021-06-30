<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Product;
use DateTime;
use Stripe\Plan;
use Stripe\Stripe;
use LogicException;
use NumberFormatter;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Member;
use Stripe\Exception\InvalidRequestException;

class StripePlan extends Product
{
    const INTERVAL_DAY = 'day';

    const INTERVAL_WEEK = 'week';

    const INTERVAL_MONTH = 'month';

    const INTERVAL_YEAR = 'year';

    private static $table_name = "StripePlanProduct";
 
    private static $description = "A product representing a subscription plan in stripe";

    /**
     * Stripe Publish Key
     * 
     * @var string
     */
    private static $publish_key;

    /**
     * Stripe Secret Key
     *
     * @var string
     */
    private static $secret_key;

    /**
     * Number of times a payment can fail before action is taken on a plan
     *
     * @var int;
     */
    private static $failier_attempts = 3;

    private static $db = [
        'Interval' => 'Enum(array("day", "week", "month", "year"), "month")'
    ];

    private static $casting = [
        'SubscriptionID' => 'Varchar'
    ];

    /**
     * Find any members linked to this plan
     *
     * @return \SilverStripe\ORM\SS_List
     */
    public function getMembers()
    {
        return Member::get()->filter('StripePlans.PlanID', $this->StockID);
    }

    /**
     * Get any active stripe subscription ID's for this product from the selected member
     *
     * @param \SilverStripe\Security\Member $member The member to check
     * @return string
     */
    public function getSubscriptionID(Member $member)
    {
        $subscription = $member->StripePlans()->find('PlanID', $this->StockID);

        if (empty($subscription)) {
            return "";
        }

        return $subscription->SubscriptionID;
    }

    /**
     * Downloadable products are not deliverable. This will be
     * detected by the shopping cart to disable delivery options.
     *
     * @return boolean
     */
    public function getDeliverable()
    {
        return false;
    }

    /**
     * Return an array of data suitable to push to stripe
     *
     * @return array
     */
    public function getStripeData()
    {
        $locale = i18n::get_locale();
        $number = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        return [
            'id' => $this->StockID,
            'currency' => $number->getTextAttribute(NumberFormatter::CURRENCY_CODE),
            'amount' => (int)$this->getBasePrice() * 100,
            'interval' => $this->Interval,
            'product' => [
                'id' => $this->StockID . '-' . 'product',
                'name' => $this->Title
            ]
        ];
    }

    /**
     * Get an expirey date, based on the provided date string
     * or current date if none set.
     *
     * @param string $start The start date in a usable string
     *
     * @return string
     */
    public function getExpireyDate($start = null)
    {
        $expires = new DateTime($start);
        $expires->modify("+1 " . $this->Interval . "s");

        return $expires->format('Y-m-d H:i:s');
    }

    /**
     * Get a stripe object from the Stripe SDK (with the chosen key type)
     *
     * @param string $type The type of key (publish_key or secret_key)
     *
     * @throws LogicException
     *
     * @return null
     */
    public static function setStripeAPIKey($type = 'secret_key')
    {
        if (!in_array($type, ['secret_key', 'publish_key'])) {
            throw new LogicException('setStripeAPIKey requires either publish_key or secret_key');
        }

        Stripe::setApiKey(self::config()->get($type));
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();

        self::setStripeAPIKey();
        $data = $this->getStripeData();

        // Check if this plan already exists, create a new one if not
        try {
            Plan::retrieve($data['id']);
        } catch (InvalidRequestException $e) {
            if (strpos($e->getMessage(), "No such plan") !== false) {
                Plan::create($data);
            }
        }
    }
}
