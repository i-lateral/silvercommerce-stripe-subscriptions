<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use SilverStripe\ORM\DataExtension;

/**
 * Add Stripe data to an estimate
 */
class EstimateExtension extends DataExtension
{
    private static $db = [
        'StripeSubscriptionID' => 'Varchar',
        'StripeSessionID' => 'Varchar'
    ];
}
