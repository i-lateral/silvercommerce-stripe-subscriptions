<?php

namespace ilateral\SilverCommerce\StripeSubscriptions\Extensions;

use SilverStripe\ORM\DB;
use SilverStripe\Security\Group;
use SilverStripe\ORM\DataExtension;

/**
 * Generate default groups (before standard groups build)
 */
class GroupExtension extends DataExtension
{
    public function requireDefaultRecords()
    {
        $subscriber = Group::get()->find('Code', 'subscriber');
        if (empty($subscriber)) {
            Group::create(
                [
                    'Code' => 'subscriber',
                    'Title' => 'Subscriber',
                    'Sort' => 0
                ]
            )->write();
            DB::alteration_message('Subscriber Group Created', 'created');
        }
    }
}
