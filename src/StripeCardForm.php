<?php

namespace ilateral\SilverCommerce\StripeSubscriptions;

use Dompdf\FrameDecorator\Text;
use LogicException;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\View\Requirements;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Forms\TextField;
use Stripe\PaymentIntent;
use Stripe\SetupIntent;

/**
 * Simple form that allows adding of a payment card via the stripe elements js API
 * and PHP sdk
 */
class StripeCardForm extends Form
{
    const DEFAULT_NAME = "StripeCardForm";

    const INTENT_PAYMENT = 'payment';

    const INTENT_SETUP = 'setup';

    protected $template = __CLASS__;

    public function __construct(
        RequestHandler $controller,
        string $name = self::DEFAULT_NAME,
        bool $hide_address_fields = false
    ) {
        Requirements::javascript("https://js.stripe.com/v3/");
        Requirements::javascript("i-lateral/silvercommerce-stripe-subscriptions:client/dist/stripe.js");

        parent::__construct(
            $controller,
            $name,
            $this->getDefaultFields($hide_address_fields),
            $this->getDefaultActions()
        );

        $this
            ->setAttribute('data-stripecardform', 'true')
            ->addExtraClass('card');
    }

    /**
     * Set the stripe publishable key
     *
     * @param string $pk
     *
     * @return self
     */
    public function setPK(string $pk): self
    {
        return $this->setAttribute('data-stripepk', $pk);
    }

    /**
     * Set the intent type for this form
     *
     * @param string $intent
     * 
     * @throws LogicException
     *
     * @return self
     */
    public function setIntent(string $intent): self
    {
        if (!$this->isValidIntent($intent)) {
            throw new LogicException('Intent is not of the expected type');
        }
    
        $this
            ->Fields()
            ->dataFieldByName('intent')
            ->setValue($intent);
        
        return $this;
    }

    /**
     * Set a back URL for this form
     *
     * @param string $URL
     *
     * @return self
     */
    public function setBackURL(string $url): self
    {
        $this
            ->Fields()
            ->dataFieldByName('back-url')
            ->setValue($url);
        
        return $this;
    }

    /**
     * Set the current stripe secret (from a setup/payment intent)
     *
     * @param string $pk
     *
     * @return self
     */
    public function setSecret(string $secret): self
    {
        return $this->setAttribute('data-secret', $secret);
    }

    protected function getDefaultFields(bool $hide_address_fields = false): FieldList
    {
        if ($hide_address_fields) {
            $line_one = HiddenField::create('cardholder-lineone');
            $zip = HiddenField::create('cardholder-zip');
        } else {
            $line_one = TextField::create(
                'cardholder-lineone',
                _t('StripeSubscriptions.AddressLineOne', 'Address Line One')
            );
            $zip = TextField::create(
                'cardholder-zip',
                _t('StripeSubscriptions.PostZipCode', 'Zip/Postal Code')
            );
        }

        return FieldList::create(
            HiddenField::create('cardholder-name'),
            HiddenField::create('cardholder-email'),
            $line_one,
            $zip,
            HiddenField::create("intent"),
            HiddenField::create("intentid"),
            HiddenField::create("back-url"),
            LiteralField::create(
                'StripeFields',
                $this->renderWith(__NAMESPACE__ . '\StripePaymentFields')
            )
        );
    }

    protected function getDefaultActions(): FieldList
    {
        return FieldList::create(
            FormAction::create('doSubmitCardForm', _t('StripeSubscriptions.AddNewCard', "Add New Card"))
                ->setUseButtonTag(true)
                ->addExtraClass('btn btn-lg btn-primary w-100')
        );
    }

    protected function isValidIntent(string $intent): bool
    {
        if (in_array($intent, [self::INTENT_PAYMENT, self::INTENT_SETUP])) {
            return true;
        }

        return false;
    }

    public function getClassFromIntent(string $intent): string
    {
        if ($intent === self::INTENT_SETUP) {
            return SetupIntent::class;
        }

        if ($intent === self::INTENT_PAYMENT) {
            return PaymentIntent::class;
        }

        return "";
    }

    /**
     * By default, add the card details via a setup intent
     */
    public function doSubmitCardForm(array $data)
    {
        $controller = $this->getController();

        if (!isset($data['intent']) || !isset($data['intentid'])) {
            return $controller->httpError(500);
        }

        $intent = $data['intent'];
        $intent_id = $data['intentid'];

        if (!$this->isValidIntent($intent)) {
            throw new LogicException('Intent is not of the expected type');
        }

        $intent_class = $this->getClassFromIntent($intent);

        // Check intent is valid, otherwise redirect
        $intent_obj = StripeConnector::retrieve($intent_class, $intent_id);

        if (empty($intent_obj)) {
            $this->setMessage('Unable to add payment card');
            return $controller->redirectBack();
        }

        if (isset($data['back-url']) && !empty($data['back-url'])) {
            return $controller->redirect($data['back-url']);
        }

        return $controller->redirectBack();
    }
}