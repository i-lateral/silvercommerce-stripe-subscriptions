function registerElements(elements, stripe) {
    var form = document.getElementById('Form_PaymentForm');
    var pay_button = document.getElementById('Form_PaymentForm_action_doSubscribe')

    function enablePayButton() {
        pay_button.removeAttribute('disabled');
    }

    function disablePayButton() {
        pay_button.setAttribute('disabled', true);
    }

    function triggerBrowserValidation() {
        // The only way to trigger HTML5 form validation UI is to fake a user submit
        // event.
        var submit = document.createElement('input');
        submit.type = 'submit';
        submit.style.display = 'none';
        form.appendChild(submit);
        submit.click();
        submit.remove();
    }

    // Check if the stripe form valid, if so, enable payment button
    window.setInterval(
        function() {
            var complete_elements = form.querySelectorAll(".stripe-field-container.StripeElement--complete");
            
            if (complete_elements.length === 3) {
                enablePayButton();
            } else {
                disablePayButton();
            }
        },
        1
    );

    // Listen on the form's 'submit' handler...
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        // Trigger HTML5 validation UI on the form if any of the inputs fail
        // validation.
        var plainInputsValid = true;
        Array.prototype.forEach.call(
            form.querySelectorAll('input'),
            function(input) {
                if (input.checkValidity && !input.checkValidity()) {
                    plainInputsValid = false;
                    return;
                }
            }
        );

        if (!plainInputsValid) {
            triggerBrowserValidation();
            return;
        }

        // Gather additional customer data we may have collected in our form.
        var cardholdername = document.getElementById('cardholder-name');
        var cardholderemail = document.getElementById('cardholder-email');
        var cardholderlineone = document.getElementById('cardholder-lineone');
        var cardholderzip = document.getElementById('cardholder-zip');
        var secret = form.dataset.secret;

        // Use Stripe.js to create a token. We only need to pass in one Element
        // from the Element group in order to create a token. We can also pass
        // in the additional customer data we collected in our form.
        stripe
            .confirmCardPayment(
                secret,
                {
                    payment_method: {
                        card: elements[0],
                        billing_details: {
                            name: cardholdername.value,
                            email: cardholderemail.value,
                            address: {
                                "line1": cardholderlineone.value,
                                "postal_code": cardholderzip.value
                            }
                        }
                    }
                }
            ).then(function(result) {
                if (result.error) {
                    disablePayButton();
                } else {
                    document.getElementById('paymentMethod').value = result.paymentMethod.id;
                    form.submit();
                }
            });
    });

    disablePayButton();
}

window.onload = function() {
    var form = document.getElementById('Form_PaymentForm');

    if (document.contains(form)) {

        // Create a Stripe client and an instance of elements
        var stripe_pk = form.dataset.stripe-pk;
        var stripe = Stripe(stripe_pk);
        var elements = stripe.elements();
    
        // Custom styling can be passed to options when creating an Element.
        // (Note that this demo uses a wider set of styles than the guide below.)
        var style = {
            base: {
                color: '#000000',
                fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
                fontSize: '16px',
                fontSmoothing: 'antialiased'
            },
            invalid: {
                color: '#fa755a',
                iconColor: '#fa755a'
            }
        };
    
        var cardNumber = elements.create('cardNumber', {style: style});
        cardNumber.mount('#stripe-card-number');
    
        var cardExpiry = elements.create('cardExpiry', {style: style});
        cardExpiry.mount('#stripe-card-expiry');
    
        var cardCvc = elements.create('cardCvc', {style: style});
        cardCvc.mount('#stripe-card-cvc');
    
        registerElements([cardNumber, cardExpiry, cardCvc], stripe);
    }
}