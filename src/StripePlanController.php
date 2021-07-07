<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Exception;
use ProductController;
use SilverStripe\Forms\Form;
use SilverStripe\Core\Injector\Injector;
use SilverCommerce\OrdersAdmin\Factory\OrderFactory;
use SilverCommerce\ShoppingCart\Forms\AddToCartForm;
use SilverStripe\Forms\HiddenField;

class StripePlanController extends ProductController
{
    private static $allowed_actions = [
        'AddToCartForm'
    ];

    public function AddToCartForm()
    {
        $owner = $this->getOwner();
        $object = $owner->dataRecord;

        $form = AddToCartForm::create(
            $owner,
            "AddToCartForm"
        );

        // Hide qty field and rename add button
        $fields = $form->Fields();
        $actions = $form->Actions();

        $fields->replaceField('Quantity', HiddenField::create('Quantity')->setValue(1));
        $actions
            ->fieldByName('action_doAddItemToCart')
            ->setTitle(_t("StripeSubscriptions.SignUpNow", "Sign Up Now"));

        $form
            ->setProductClass($object->ClassName)
            ->setProductID($object->ID);

        return $form;
    }

    /**
     * Overwrite default add to cart and redirect directly to checkout
     */
    public function doAddItemToCart(array $data, Form $form)
    {
        $classname = $data["ClassName"];
        $id = $data["ID"];
        $object = $classname::get()->byID($id);
        $factory = OrderFactory::create();
        $error = false;

        if (!empty($object)) {
            // Try and add item to cart, return any exceptions raised
            // as a message
            try {
                $factory
                    ->addItem($object, $data['Quantity'])
                    ->write();

                $this->extend('updateAddItemToCart', $item_to_add, $factory);
            } catch (Exception $e) {
                $error = true;
                $form->sessionMessage(
                    $e->getMessage()
                );
            }
        } else {
            $error = true;
            $this->sessionMessage(
                _t("StripeSubscriptions.ErrorStartingSubscription", "Error starting subscription")
            );
        }

        if (!$error) {
            $checkout = Injector::inst()->get(SubscriptionCheckout::class);
            $checkout->setEstimate($factory->getOrder());
            return $this->redirect($checkout->Link());
        }

        return $this->redirectBack();
    }
}