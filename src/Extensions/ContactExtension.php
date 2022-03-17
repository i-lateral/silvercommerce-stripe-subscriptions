<?php

namespace ilateral\SilverCommerce\StripeSubscriptions\Extensions;

use Stripe\Customer;
use Stripe\PaymentMethod;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Security\Group;
use SilverStripe\View\ArrayData;
use SilverStripe\Security\Member;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverCommerce\ContactAdmin\Model\Contact;
use ilateral\SilverStripe\Users\Control\AccountController;
use ilateral\SilverCommerce\StripeSubscriptions\StripeConnector;
use ilateral\SilverCommerce\StripeSubscriptions\StripePlanMember;

class ContactExtension extends DataExtension
{
    private static $db = [
        'StripeID' => 'Varchar'
    ];

    private static $has_many = [
        'StripePlans' => StripePlanMember::class
    ];

    public function getStripeData(): array
    {
        /** @var Contact */
        $owner = $this->getOwner();

        $data = [
            'email' => $owner->Email,
            'name' => $owner->FirstName . ' ' . $owner->Surname
        ];

        if ($owner->Locations()->exists()) {
            $location = $owner->DefaultLocation();

            $data['address'] = [
                'city' => $location->City,
                'country' => $location->Country,
                'line1' => $location->Address1,
                'line2' => $location->Address2,
                'postal_code' => $location->PostCode,
                'state' => $location->County
            ];
        }

        return $data;
    }

    /**
     * Get a list of payment cards for the current contact from the stripe API
     *
     * @return ArrayList
     */
    public function getStripePaymentCards(): ArrayList
    {
        /** @var Contact */
        $owner = $this->getOwner();

        StripeConnector::setStripeAPIKey(StripeConnector::KEY_SECRET);
        $stripe_id = $owner->StripeID;
        $cards = ArrayList::create();

        $raw_cards = PaymentMethod::all(
            [
                'customer' => $stripe_id,
                'type' => 'card'
            ]
        );

        // Loop through raw stripe card data for this
        foreach ($raw_cards->data as $card) {
            $remove_link = Controller::join_links(
                Injector::inst()->get(AccountController::class, true)->Link('removecard'),
                $card->id
            );

            $cards->add(
                ArrayData::create(
                    [
                        'ID' => $card->id,
                        'CardNumber' => str_pad($card->card->last4, 16, '*', STR_PAD_LEFT),
                        'Expires' => $card->card->exp_month . '/' . $card->card->exp_year,
                        'Brand' => $card->card->brand,
                        'RemoveLink' => $remove_link
                    ]
                )
            );
        }

        return $cards;
    }

    public function onAfterDelete()
    {
        foreach ($this->getOwner()->StripePlans() as $plan) {
            $plan->delete();
        }
    }

    public function onAfterWrite()
    {
        /** @var Contact */
        $owner = $this->getOwner();

        /** @var Member */
        $member = $owner->Member();

        /**
         * Is Member a front-end user? If not, finish processing
         * @var Group $group
         */
        $group = $member->Groups()->find('Code', 'users-frontend');

        if (empty($group)) {
            return;
        }

        // Push or update this contact into stripe
        StripeConnector::setStripeAPIKey(StripeConnector::KEY_SECRET);

        $customer = StripeConnector::createOrUpdate(
            Customer::class,
            $owner->getStripeData(),
            $owner->StripeCustomerID
        );

        // Assign customer ID (if available)
        if (isset($customer) && isset($customer->id)
            && $customer->id !== $owner->StripeCustomerID
        ) {
            $owner->StripeCustomerID = $customer->id;
            $owner->write();
        }

        /** 
         * Finally, if this user has subscriptions, ensure they are in the Subscribers group
         * or remove them if not
         * @var Group $sub_group
         */
        $sub_group = Group::get()->find('Code', 'subscriber');
        if (isset($sub_group)) {
            if ($owner->StripePlans()->exists()) {
                $member->Groups()->add($sub_group);
            } else {
                $member->Groups()->remove($sub_group);
            }
        }
    }

    public function isSubscribed()
    {
        $owner = $this->getOwner();
        $subscriber = $owner->Groups()->find('Code', 'subscriber');

        return isset($subscriber);
    }
}