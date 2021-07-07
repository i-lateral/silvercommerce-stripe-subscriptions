<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Stripe\Stripe;
use LogicException;
use Stripe\ApiResource;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Simple helper class to handle abstracting pushing SS data into Stripe
 * (via the API)
 * 
 */
class StripeConnector
{
    use Configurable, Injectable;

    const KEY_PUBLISH = 'publish_key';

    const KEY_SECRET = 'secret_key';

    /**
     * Stripe Publish Key
     * 
     * @var string
     */
    private static $publish_key;

    /**
     * Stripe Secret Key
     *
     * @var string
     */
    private static $secret_key;

    /**
     * Get the stripe API key
     *
     * @param string $type The type of key (publish_key or secret_key)
     *
     * @throws LogicException
     *
     * @return string
     */
    public static function getStripeAPIKey($type = self::KEY_PUBLISH): string
    {
        if (!in_array($type, [self::KEY_SECRET, self::KEY_PUBLISH])) {
            throw new LogicException('getStripeAPIKey requires either publish_key or secret_key');
        }

        return self::config()->get($type);
    }

    /**
     * Globaly set the API key via the Stripe SDK
     *
     * @param string $type The type of key (publish or secret)
     *
     * @throws LogicException
     *
     * @return null
     */
    public static function setStripeAPIKey($type = self::KEY_PUBLISH): void
    {
        if (!in_array($type, [self::KEY_SECRET, self::KEY_PUBLISH])) {
            throw new LogicException('setStripeAPIKey requires either publish_key or secret_key');
        }

        Stripe::setApiKey(self::config()->get($type));
    }

    /**
     * Create a new, or update an existing record (if the stripe ID is passed)
     */
    public static function createOrUpdate(string $type, array $data, string $stripe_id = null): ApiResource
    {
        if (!is_a($type, ApiResource::class, true)) {
            throw new LogicException("Type must be a type of API Resopurce");
        }

        self::setStripeAPIKey(self::KEY_SECRET);

        // Either create a new user in stripe, or update the existing user
        if (empty($stripe_id)) {
            $object = $type::create($data);
        } else {
            $object = $type::update($stripe_id, $data);
        }

        return $object;
    }
}
