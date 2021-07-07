<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use SilverStripe\ORM\DataExtension;
use SilverCommerce\ContactAdmin\Model\Contact;
use SilverCommerce\OrdersAdmin\Model\Estimate;

/**
 * Add Stripe data to an estimate
 */
class EstimateExtension extends DataExtension
{
    private static $db = [
        'StripeSubscriptionID' => 'Varchar',
        'StripeSessionID' => 'Varchar'
    ];

    public function getStripeData(): array
    {
        /** @var Estimate */
        $owner = $this->getOwner();

        // retrieve customer direct from DB, so we get updated data
        $customer = Contact::get()->byID($owner->CustomerID);

        $product = $owner->Items()->first()->FindStockItem();

        $data = [
            'items' => [],
            'payment_behavior' => 'default_incomplete',
            'expand' => ['latest_invoice.payment_intent'],
        ];

        foreach ($owner->Items() as $line_item) {
            $product = $line_item->FindStockItem();

            if (!isset($product) || empty($product->StripeID)) {
                continue;
            }

            $data['items'][] = ['price' => $product->StripeID];
        }

        if (isset($customer) && !empty($customer->StripeID)) {
            $data['customer'] = $customer->StripeID;
        }

        return $data;
    }
}
