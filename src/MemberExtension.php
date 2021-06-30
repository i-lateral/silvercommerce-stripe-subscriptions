<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Stripe\Customer;
use SilverStripe\Security\Group;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use Symbiote\GridFieldExtensions\GridFieldTitleHeader;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\TextField;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;
use Symbiote\GridFieldExtensions\GridFieldAddNewInlineButton;

/**
 * Add Stripe customer data to a member
 *
 */
class MemberExtension extends DataExtension
{
    private static $db = [
        'StripeCustomerID' => 'Varchar'
    ];

    private static $has_many = [
        'StripePlans' => StripePlanMember::class
    ];

    public function updateCMSFields(\SilverStripe\Forms\FieldList $fields)
    {
        $plans_field = $fields->dataFieldByName('StripePlans');

        if (isset($plans_field)) {
            $cols = new GridFieldEditableColumns();
            $cols->setDisplayFields(
                [
                    'PlanID'  => [
                        'title' => 'Plan',
                        'callback' => function ($record, $column, $grid) {
                            return DropdownField::create($column)
                                ->setSource(StripePlan::get()->map('StockID', 'Title'))
                                ->setEmptyString(_t('StripeSubscriptions.SelectPlan', 'Select a Plan'));
                        }
                    ],
                    'Expires' => [
                        'title' => 'Expires',
                        'field' => DatetimeField::class
                    ],
                    'SubscriptionID' => [
                        'title' => 'Subscription',
                        'field' => TextField::class
                    ]
                ]
            );

            $plans_field->setConfig(
                GridFieldConfig::create()
                ->addComponent(new GridFieldButtonRow('before'))
                ->addComponent(new GridFieldToolbarHeader())
                ->addComponent(new GridFieldTitleHeader())
                ->addComponent($cols)
                ->addComponent(new GridFieldDeleteAction())
                ->addComponent(new GridFieldAddNewInlineButton())
            );
        }
    }

    public function getStripeData()
    {
        return [
            'email' => $this->getOwner()->Email,
            'name' => $this->getOwner()->FirstName . ' ' . $this->getOwner()->Surname
        ];
    }

    public function onAfterDelete()
    {
        foreach ($this->getOwner()->StripePlans() as $plan) {
            $plan->delete();
        }
    }

    public function onAfterWrite()
    {
        // Is Member a front-end user? If not, finish processing
        $group = $this->getOwner()->Groups()->find('Code', 'users-frontend');

        if (empty($group)) {
            return;
        }

        StripePlan::setStripeAPIKey();

        // Either create a new user in stripe, or update the existing user
        if (empty($this->getOwner()->StripeCustomerID)) {
            $customer = Customer::create($this->getOwner()->getStripeData());

            // Assign customer ID (if available)
            if (isset($customer) && isset($customer->id)) {
                $this->getOwner()->StripeCustomerID = $customer->id;
                $this->getOwner()->write();
            }
        } else {
            $customer = Customer::update(
                $this->getOwner()->StripeCustomerID,
                $this->getOwner()->getStripeData()
            );
        }

        // Finally, if this user has subscriptions, ensure they are in the Subscribers group
        // or remove them if not
        $sub_group = Group::get()->find('Code', 'subscriber');
        if (isset($sub_group)) {
            if ($this->getOwner()->StripePlans()->exists()) {
                $this->getOwner()->Groups()->add($sub_group);
            } else {
                $this->getOwner()->Groups()->remove($sub_group);
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
