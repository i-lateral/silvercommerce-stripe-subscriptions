function registerElements(elements, stripe, form) {
    var id = form.id;
    var pay_button = document.getElementById(id + '_action_doSubmitCardForm');

    function enablePayButton() {
        pay_button.removeAttribute('disabled');
    }

    function disablePayButton() {
        pay_button.setAttribute('disabled', true);
    }

    function triggerBrowserValidation() {
        // The only way to trigger HTML5 form validation UI is to fake a user submit event.
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
            var existing_card = document.getElementById(id + '_existing-card');
            var complete_elements = form.querySelectorAll(".stripe-field-container.StripeElement--complete");
    
            if (complete_elements.length === 3
                || (document.contains(existing_card) && existing_card.value !== "")
            ) {
                enablePayButton();
            } else {
                disablePayButton();
            }
        },
        1
    );

    // Listen on the form's 'submit' handler...
    form.addEventListener('submit', async function(e) {
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

        // Gather additional data we have collected in our form.
        var intent_type = document.getElementById(id + '_intent').value;
        var cardholdername = document.getElementById(id + '_cardholder-name');
        var cardholderemail = document.getElementById(id + '_cardholder-email');
        var cardholderlineone = document.getElementById(id + '_cardholder-lineone');
        var cardholderzip = document.getElementById(id + '_cardholder-zip');
        var existing_card = document.getElementById(id + '_existing-card');
        var secret = form.dataset.secret;

        if (document.contains(existing_card)) {
            var payment_method = existing_card.value;
        } else {
            var payment_method = {
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

        var setup_data = { payment_method: payment_method };

        // Use Stripe.js to either confirm card payment or setup and return an ID
        if (intent_type == 'payment') {
            stripe
                .confirmCardPayment(secret, setup_data)
                .then(function(result) {
                    if (result.error) {
                        disablePayButton();
                        alert(result.error.message);
                    } else {
                        document.getElementById(id + '_intentid').value = result.paymentIntent.id;
                        form.submit();
                    }
                });
        } else {
            stripe
                .confirmCardSetup(secret, setup_data)
                .then(function(result) {
                    if (result.error) {
                        disablePayButton();
                        alert(result.error.message);
                    } else {
                        document.getElementById(id + '_intentid').value = result.setupIntent.id;
                        form.submit();
                    }
                });
        }
        
        
    });

    disablePayButton();
}

function setupStripeForm(form) {
    // Create a Stripe client and an instance of elements
    var stripe_pk = form.dataset.stripepk;
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

    registerElements([cardNumber, cardExpiry, cardCvc], stripe, form);
}

window.onload = function() {
    var all_forms = document.getElementsByTagName("form");
    for(var i=0; i < all_forms.length;i++) {
        var form = all_forms[i];

        if (form.dataset.stripecardform !== undefined) {
            setupStripeForm(form);
        }
    }
}