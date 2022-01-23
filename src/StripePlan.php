<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Product;
use DateTime;
use Stripe\Plan;
use NumberFormatter;
use SilverStripe\i18n\i18n;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Member;
use Stripe\Exception\InvalidRequestException;

/**
 * @property string Interval
 * @property int TrialPeriod
 * @property string StripeID
 * @property string SubscriptionID
 */
class StripePlan extends Product
{
    const INTERVAL_DAY = 'day';

    const INTERVAL_WEEK = 'week';

    const INTERVAL_MONTH = 'month';

    const INTERVAL_YEAR = 'year';

    private static $table_name = "StripePlan";

    private static $singular_name = 'Stripe Plan';

    private static $plural_name = 'Stripe Plans';

    private static $description = "A product representing a subscription plan in stripe";


    /**
     * Number of times a payment can fail before action is taken on a plan
     *
     * @var int;
     */
    private static $failier_attempts = 3;

    private static $db = [
        'Interval' => 'Enum(array("day", "week", "month", "year"), "month")',
        'TrialPeriod' => 'Int'
    ];

    private static $casting = [
        'StripeID' => 'Varchar',
        'SubscriptionID' => 'Varchar',
        'NiceInterval' => 'Varchar'
    ];

    private static $field_labels = [
        'Interval' => 'Subscription interval',
        'TrialPeriod' => 'Length of trial (in days)'
    ];

    private static $readonly_on_update = [
        'StockID',
        'BasePrice',
        'TaxCategoryID',
        'TaxRateID',
        'Interval',
        'TrialPeriod'
    ];

    /**
     * Find any members linked to this plan
     *
     * @return DataList
     */
    public function getMembers(): DataList
    {
        return Member::get()->filter('StripePlans.PlanID', $this->StockID);
    }

    /**
     * Get the ID we use to identify this product in stripe
     *
     * @return string
     */
    public function getStripeID(): string
    {
        return $this->StockID;
    }

    public function getNicePrice(): string
    {
        if ($this->hasMethod('renderWith')) {
            return $this->renderWith(__CLASS__ . "_NicePrice");
        }

        return "";
    }

    public function getNiceInterval(): string
    {
        return _t(
            'StripeSubscriptions.PlanNiceInterval',
            'Per {interval}',
            ['interval' => ucwords($this->Interval)]
        );
    }

    /**
     * Get any active stripe subscription ID's for this product from the selected member
     *
     * @param \SilverStripe\Security\Member $member The member to check
     * @return string
     */
    public function getSubscriptionID(Member $member): string
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
     * @return bool
     */
    public function getDeliverable(): bool
    {
        return false;
    }

    /**
     * Return an array of data suitable to push to stripe
     *
     * @param bool $detail_product Provide more detailed product info
     * 
     * @return array
     */
    protected function getStripeCreateData(): array
    {
        $locale = i18n::get_locale();
        $number = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        return [
            'id' => $this->StripeID,
            'currency' => $number->getTextAttribute(NumberFormatter::CURRENCY_CODE),
            'amount' => (int)($this->getBasePrice() * 100),
            'interval' => $this->Interval,
            'trial_period_days' => $this->TrialPeriod,
            'product' => [
                'id' => $this->StripeID,
                'name' => $this->Title
            ]
        ];
    }

    /**
     * Get an expiry date, based on the provided date string
     * or current date if none set.
     *
     * @param string $start The start date in a usable string
     *
     * @return string
     */
    public function getExpireyDate($start = null): string
    {
        $expires = new DateTime($start);
        $expires->modify("+1 " . $this->Interval . "s");

        return $expires->format('Y-m-d H:i:s');
    }

    public function getCMSFields(): FieldList
    {
        $self = $this;

        $this->beforeUpdateCMSFields(
            function ($fields) use ($self) {
                /** @var FieldList $fields */
                $fields->addFieldsToTab(
                    'Root.Settings',
                    [
                        $self
                            ->dbObject('Interval')
                            ->scaffoldFormField($self->fieldLabel('Interval')),
                        $self
                            ->dbObject('TrialPeriod')
                            ->scaffoldFormField($self->fieldLabel('TrialPeriod'))
                    ]
                );

                // If this product is created, certain fields are locked (they cannot be edited in stripe)
                if (!$self->exists()) {
                    return;
                }

                foreach ($self->config()->readonly_on_update as $field_name) {
                    $field = $fields->dataFieldByName($field_name);

                    if (isset($field)) {
                        $fields->replaceField(
                            $field_name,
                            ReadonlyField::create(
                                $field_name,
                                $field->Title()
                            )
                        );
                    }
                }
            }
        );

        return parent::getCMSFields();
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();

        StripeConnector::setStripeAPIKey(StripeConnector::KEY_SECRET);

        // Check if this plan already exists, create a new one if not
        try {
            Plan::retrieve($this->StripeID);
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === "resource_missing") {
                Plan::create($this->getStripeCreateData());
            }
        }
    }
}
