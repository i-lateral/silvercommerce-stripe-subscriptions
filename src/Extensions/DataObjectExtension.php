<?php

namespace ilateral\SilverCommerce\StripeSubscriptions\Extensions;

use LogicException;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataExtension;
use ilateral\SilverCommerce\StripeSubscriptions\StripeConnector;
use ilateral\SilverCommerce\StripeSubscriptions\Interfaces\StripeSubscriptionObject;
use SilverStripe\Forms\FieldList;

class DataObjectExtension extends DataExtension
{
    /**
     * For some reason SS seems to sometimes double write to the DB
     * (causing dupliacte object creation). Cache the ID incase this happens
     *
     * @var string
     */
    private $cache_stripe_id;

    /**
     * Disable stripe ID field is set 
     */
    public function updateCMSFields(FieldList $fields)
    {
        /** @var DataObject */
        $owner = $this->getOwner();
        $disable_fields = [
            'Amount',
            'Duration',
            'DurationInMonths'
        ];

        $stripe_field = $fields->dataFieldByName('StripeID');

        if (!empty($stripe_field)) {
            $stripe_field
                ->setDisabled(true)
                ->performDisabledTransformation();
        }

        if (!$owner->isInDB()) {
            return;
        }

        foreach ($disable_fields as $field_name) {
            $field = $fields->dataFieldByName($field_name);
    
            if (!empty($field)) {
                $field
                    ->setReadonly(true)
                    ->performReadonlyTransformation();
            }
        }
    }

    public function onBeforeWrite()
    {
        /** @var DataObject */
        $owner = $this->getOwner();

        if (!$owner instanceof StripeSubscriptionObject) {
            throw new LogicException("Extended objects must implement StripeSubscriptionObject");
        }

        if (!empty($this->cache_stripe_id)) {
            $owner->setStripeID($this->cache_stripe_id);
            return;
        }

        $id = $owner->getStripeID();
        $stripe_class = $owner->getStripeClass();
        StripeConnector::setStripeAPIKey(StripeConnector::KEY_SECRET);

        if (empty($id)) {
            $obj = $stripe_class::create($owner->getStripeCreateData());
            $id = $obj->id;
            $this->cache_stripe_id = $id;
            $owner->setStripeID($id);
        } else {
            $stripe_class::update(
                $id,
                $owner->getStripeUpdateData()
            );
        }
    }
}