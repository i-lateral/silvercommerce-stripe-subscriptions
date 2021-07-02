# SilverCommerce Stripe Subscriptions

Setup and manage Stripe Subscriptsion via a SilverCommerce install

## Install

Install via composer

    composer require i-lateral/silvercommerce-stripe-subscriptions

## Configure

You will need to add stripe keys in order for this module to talk to stripe.

This can be done via `config.yml`, eg:

---
Name: stripeconfig
---
ilateral\SilverCommerce\StripeSubscriptions\StripePlan:
  publish_key: LIVE_KEY
  secret_key: LIVE_KEY

---
Except:
  environment: 'live'
---
ilateral\SilverCommerce\StripeSubscriptions\StripePlan:
  publish_key: TEST_KEY
  secret_key: TEST_KEY