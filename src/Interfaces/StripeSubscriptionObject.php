<?php

namespace ilateral\SilverCommerce\StripeSubscriptions\Interfaces;

/**
 * An object that interfaces with the stripe API
 */
interface StripeSubscriptionObject
{
    /**
     * Return the classname to be used by this class VIA the
     * stripe SDK
     *
     * @return string
     */
    public function getStripeClass(): string;

    /**
     * All objects must return a unique ID that links it to Stripe
     *
     * @return string
     */
    public function getStripeID(): string;

    /**
     * Allow stripe API to return and set the StripeID for this object
     *
     * @return self
     */
    public function setStripeID(string $id): self;

    /**
     * Return data for this object that can be pushed into stripe
     * when the object is created
     */
    public function getStripeCreateData(): array;

    /**
     * Return data for this object that can be pushed into stripe
     * when the object is updated
     */
    public function getStripeUpdateData(): array;
}