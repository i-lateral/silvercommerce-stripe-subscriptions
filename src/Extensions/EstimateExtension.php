<?php

namespace ilateral\SilverCommerce\StripeSubscriptions\Extensions;

use SilverStripe\ORM\DataExtension;
use SilverCommerce\ContactAdmin\Model\Contact;
use SilverCommerce\OrdersAdmin\Model\Estimate;
use SilverCommerce\Discounts\Model\AppliedDiscount;
use ilateral\SilverCommerce\StripeSubscriptions\StripePlanMember;
use ilateral\SilverCommerce\StripeSubscriptions\Interfaces\StripeSubscriptionObject;

/**
 * Add Stripe data to an estimate
 */
class EstimateExtension extends DataExtension
{
    private static $db = [
        'StripeSubscriptionID' => 'Varchar',
        'StripeIntentID' => 'Varchar'
    ];

    private static $has_one = [
        'Subscription' => StripePlanMember::class
    ];

    public function getStripeData(): array
    {
        /** @var Estimate */
        $owner = $this->getOwner();

        // retrieve customer direct from DB, so we get updated data
        $customer = Contact::get()->byID($owner->CustomerID);

        $product = $owner->Items()->first()->FindStockItem();

        $data = [
            'payment_behavior' => 'default_incomplete',
            'expand' => ['latest_invoice.payment_intent'],
        ];
        $items = [];

        foreach ($owner->Items() as $line_item) {
            $product = $line_item->FindStockItem();

            if (!isset($product) || empty($product->StripeID)) {
                continue;
            }

            $items[] = ['price' => $product->StripeID];
        }

        if (count($items) > 0) {
            $data['items'] = $items;
        }

        if (isset($customer) && !empty($customer->StripeID)) {
            $data['customer'] = $customer->StripeID;
        }

        // If any applied discounts are Stripe Coupons, apply them to
        // the stripe data
        foreach ($owner->Discounts() as $applied_discount) {
            /** @var AppliedDiscount $applied_discount */
            $discount = $applied_discount->getDiscount();

            if (!empty($discount) && $discount instanceof StripeSubscriptionObject) {
                $data['coupon'] = $discount->getStripeID();
            }
        }

        return $data;
    }
}
