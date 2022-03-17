<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use DateTime;
use SilverCommerce\OrdersAdmin\Factory\OrderFactory;
use SilverCommerce\OrdersAdmin\Model\Invoice;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use Stripe\Event;
use Stripe\Subscription;
use Stripe\SubscriptionItem;

class WebhookEndpoint extends Controller
{
    /*
     * The json data provided as part of this
     *
     * @var stdClass
     */
    protected $data;

    /**
     * Current posted data wrapped in a stripe event
     *
     * @var Event
     */
    protected $event;

    private static $allowed_actions = [
        'subscriptionupdate',
        'renew',
        'furtheraction'
    ];

    /**
     * Get the json data from the current post
     * and convert to an object
     * 
     * @return Object
     */
    protected function get_json_data()
    {
        $input = @file_get_contents("php://input");
        return json_decode($input, true);
    }

    /**
     * Standard initialiser for this controller. Mainly this is used to
     * deal with checking the data submitted to it and setting it.
     *
     * @return void
     */
    public function init()
    {
        parent::init();

        try {
            $this->data = $this->get_json_data();
            $this->event = Event::constructFrom($this->data);
        } catch(\UnexpectedValueException $e) {
            return $this->httpError(200, $e->getMessage());
        }

        return;
    }

    /**
     * Has the subscription failed initial payment
     *
     * @param HTTPRequest $request
     */
    public function subscriptionupdate(HTTPRequest $request)
    {
        $event = $this->event;
        
        // If event is invalid, return error
        if ($event->type != Event::CUSTOMER_SUBSCRIPTION_UPDATED
            || !isset($event->data) || !isset($event->data->object)
            || !is_a($event->data->object, Subscription::class, true)
        ) {
            return $this->httpError(200);
        }

        /**
         * Find a plan for the subscription data
         *
         * @var Subscription $sub
         * @var StripePlanMember $plan_member
         */
        $sub = $event->data->object;
        $plan_member = StripePlanMember::get()->find('SubscriptionID', $sub->id);

        if (empty($plan_member)) {
            return $this->httpError(200);
        }

        $expires = new DateTime();
        $expires->setTimestamp($sub->current_period_end);

        $plan_member->Status = $sub->status;
        $plan_member->Expires = $expires->format('Y-m-d H:i:s');
        $plan_member->write();

        $invoice = Invoice::get()->find('StripeSubscriptionID', $sub->id);
        $created = false;

        // If invoice hasn't been created (most likely, a new instance of a subscription)
        // create one now.
        if (empty($invoice)) {
            $factory = OrderFactory::create(true);

            /** @var SubscriptionItem $sub_item */
            foreach ($sub->items as $sub_item) {
                $product = StripePlan::get()->find('StockID', $sub_item->id);

                if (empty($product)) {
                    continue;
                }

                $factory
                    ->addItem($product, $sub_item->quantity)
                    ->write();
            }

            $invoice = $factory->getOrder();
            $invoice->SubscriptionID = $plan_member->ID;
            $created = true;
        }

        $previous_status = null;

        // check the status of the subscription
        if (isset($event->data->previous_attributes)
            && isset($event->data->previous_attributes->status)
        ) {
            $previous_status = $event->data->previous_attributes->status;
        }

        if (empty($previous_status)) {
            return;
        }

        // If previous status was active and now "past due", then
        // subscription payment failed. Otherwise, if active, mark as paid
        if ($sub->status == Subscription::STATUS_PAST_DUE
            && $previous_status == Subscription::STATUS_ACTIVE)
        {
            $invoice->Status = 'failed';
            $invoice->write();
        } elseif ($sub->status == Subscription::STATUS_ACTIVE
            && $created == true
        ) {
            $invoice->markPaid();
        }

        return;
    }
}
