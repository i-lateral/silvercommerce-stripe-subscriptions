<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Stripe\Customer;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Security\Group;
use SilverStripe\Forms\TextField;
use SilverStripe\Security\Member;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use Symbiote\GridFieldExtensions\GridFieldTitleHeader;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;
use Symbiote\GridFieldExtensions\GridFieldAddNewInlineButton;

/**
 * Add Stripe customer data to a member
 *
 */
class MemberExtension extends DataExtension
{
    private static $casting = [
        'StripeCustomerID' => 'Varchar'
    ];

    public function getStripeCustomerID()
    {
        return $this
            ->getOwner()
            ->Contact()
            ->StripeID;
    }

    /**
     * Return a list of stripe plans from the attached contact
     *
     * @return DataList
     */
    public function getStripePlans(): DataList
    {
        return $this
            ->getOwner()
            ->Contact()
            ->StripePlans();
    }

    /**
     * Get a list of payment cards for the current contact from the stripe API
     *
     * @return ArrayList
     */
    public function getStripePaymentCards(): ArrayList
    {
        return $this
            ->getOwner()
            ->Contact()
            ->getStripePaymentCards();
    }

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

    public function getStripeData(): array
    {
        return [
            'email' => $this->getOwner()->Email,
            'name' => $this->getOwner()->FirstName . ' ' . $this->getOwner()->Surname
        ];
    }

    public function onAfterDelete()
    {
        foreach ($this->getOwner()->getStripePlans() as $plan) {
            $plan->delete();
        }
    }

    public function isSubscribed(): bool
    {
        $owner = $this->getOwner();
        $subscriber = $owner->Groups()->find('Code', 'subscriber');

        return isset($subscriber);
    }
}
