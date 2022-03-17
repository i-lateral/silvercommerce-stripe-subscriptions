<?php

namespace ilateral\SilverCommerce\StripeSubscriptions\Coupons;

use Stripe\Coupon;
use NumberFormatter;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverCommerce\Discounts\Model\FixedRateDiscount;
use ilateral\SilverCommerce\StripeSubscriptions\Interfaces\StripeSubscriptionObject;

class FixedRateCoupon extends FixedRateDiscount implements StripeSubscriptionObject
{
    private static $table_name = 'Discount_StripeFixedRate';

    private static $description = "Fixed Value Stripe Coupon";

    private static $db = [
        'StripeID' => 'Varchar',
        'Duration' => "Enum('once,repeating,forever','once')",
        'DurationInMonths' => "Int"
    ];

    public function getStripeID(): string
    {
        return (string)$this->dbObject('StripeID')->getValue();
    }

    /**
     * Quick way to save the returned stripe ID into the DB
     *
     * @return self
     */
    public function setStripeID(string $id): self
    {
		$update = new SQLUpdate(
            $this->config()->table_name,
            ['StripeID' => $id],
            ['ID' => $this->ID]
        );
		$update->execute();
		$this->StripeID = $id;
        return $this;
    }

    public function getStripeClass(): string
    {
        return Coupon::class;
    }

    public function getStripeCreateData(): array
    {
        $locale = i18n::get_locale();
        $number = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $data = [
            'name' => $this->Title,
            'currency' => $number->getTextAttribute(NumberFormatter::CURRENCY_CODE),
            'amount_off' => (int)($this->Amount * 100),
            'duration' => $this->Duration
        ];

        if (!empty($this->DurationInMonths)) {
            $data['duration_in_months'] = $this->DurationInMonths;
        }

        return $data;
    }

    public function getStripeUpdateData(): array
    {
        return ['name' => $this->Title];
    }
}