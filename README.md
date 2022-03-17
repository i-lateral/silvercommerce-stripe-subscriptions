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

## Coupons

This module now supports Stripe Coupons (via the 
SilverCommerce discounts module).

Stripe coupons are a custom type of Discount
that can be created via `SiteConfig`.

**NOTE** Subscriptions use a custom checkout process
that skips the shopping cart. In order to apply the
discount, you will have to use the discount URL OR
add the discount form to you `Checkout.ss` template:

For Example, add the form to the order summary (on
the right) via:

```HTML
  <div class="unit size1of3 col-xs-12 col-sm-4">
        <% with $Estimate %>
            <% include SilverCommerce\Checkout\Includes\OrderSummary %>
        <% end_with %>

        $DiscountForm
  </div>
```