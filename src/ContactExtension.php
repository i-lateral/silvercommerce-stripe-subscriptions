<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Stripe\Customer;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\ORM\DataExtension;
use SilverCommerce\ContactAdmin\Model\Contact;

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