// Payment page: Stripe's card and address fields inside our own form (Stripe Payment Element).
// The card goes from the browser straight to Stripe; this page only sends the resulting card id to our server.
(() => {
    const form = document.querySelector('[data-payment-form]');
    if (!form) return;

    const button = form.querySelector('[data-payment-button]');
    const spinner = form.querySelector('[data-payment-spinner]');
    const label = form.querySelector('[data-payment-label]');
    const readyLabel = form.querySelector('[data-payment-ready-label]');
    const message = form.querySelector('[data-payment-message]');
    const token = form.querySelector('input[name="_token"]').value;

    const showError = (text) => {
        message.textContent = text;
        message.classList.remove('d-none');
    };
    const setBusy = (busy, text) => {
        button.disabled = busy;
        spinner.classList.toggle('d-none', !busy);
        label.classList.toggle('d-none', !busy);
        readyLabel.classList.toggle('d-none', busy);
        if (text) label.textContent = text;
    };

    if (typeof Stripe === 'undefined') {
        // Usually an ad or script blocker stopping js.stripe.com
        setBusy(true, 'Payment form could not load');
        spinner.classList.add('d-none');
        showError('The secure payment form could not load. Please turn off any ad or script blocker for this page and refresh.');
        return;
    }

    const stripe = Stripe(form.dataset.key);
    const elements = stripe.elements({
        clientSecret: form.dataset.secret,
        fonts: [{ cssSrc: 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap' }],
        appearance: {
            theme: 'stripe',
            variables: {
                colorPrimary: '#8cbc43',
                colorText: '#202426',
                colorDanger: '#b3261e',
                fontFamily: 'Roboto, system-ui, sans-serif',
                borderRadius: '8px',
                spacingUnit: '4px',
            },
            rules: {
                '.Input': { borderColor: '#dfe5d8', boxShadow: 'none' },
                '.Input:focus': { borderColor: '#8cbc43', boxShadow: '0 0 0 3px rgba(140, 188, 67, 0.2)' },
                '.Label': { fontWeight: '500', color: '#3e4a40' },
            },
        },
    });

    const address = elements.create('address', {
        mode: 'billing',
        defaultValues: { name: form.dataset.name, address: { country: 'US' } },
    });
    const payment = elements.create('payment', {
        layout: 'tabs',
        fields: { billingDetails: { address: 'never' } },   // the address box above already collects it
        terms: { card: 'never' },
    });
    address.mount(form.querySelector('[data-address-element]'));
    payment.mount(form.querySelector('[data-payment-element]'));

    let ready = 0;
    const markReady = () => {
        ready += 1;
        if (ready === 2) setBusy(false);
    };
    address.on('ready', markReady);
    payment.on('ready', markReady);
    payment.on('change', () => message.classList.add('d-none'));

    const post = async (url, body) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok && !data.message) {
            data.message = response.status === 422
                ? 'Please check the billing address and card details.'
                : 'Something went wrong. Please try again in a moment.';
        }
        return data;
    };

    // Our server answers with a redirect (paid), a client secret (bank approval needed) or a message (problem)
    const finish = async (data) => {
        if (data.redirect) {
            setBusy(true, 'Payment received, opening your dashboard…');
            window.location.assign(data.redirect);
            return;
        }
        if (data.confirm) {
            setBusy(true, 'Waiting for your bank to approve…');
            const { error } = await stripe.handleNextAction({ clientSecret: data.confirm });
            if (error) {
                await post(form.dataset.confirmUrl, {});   // lets the server drop the unpaid attempt
                throw new Error(error.message || 'The payment was not approved. Please try again.');
            }
            setBusy(true, 'Confirming payment…');
            return finish(await post(form.dataset.confirmUrl, {}));
        }
        throw new Error(data.message || 'The payment did not go through. Please try again.');
    };

    // A card Stripe accepted cannot be checked a second time: after a failed charge the page reloads for a fresh form
    let cardSaved = false;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (button.disabled) return;
        if (cardSaved) {
            setBusy(true, 'Reloading…');
            window.location.reload();
            return;
        }
        message.classList.add('d-none');
        setBusy(true, 'Checking your card…');

        try {
            const { complete, value } = await address.getValue();
            if (!complete) throw new Error('Please fill in the billing address.');

            // Stripe checks the card (and runs 3-D Secure when the bank asks) before anything is charged
            const { error, setupIntent } = await stripe.confirmSetup({
                elements,
                redirect: 'if_required',
                confirmParams: {
                    return_url: form.dataset.return,
                    payment_method_data: { billing_details: { name: value.name, address: value.address } },
                },
            });
            if (error) throw new Error(error.message);
            cardSaved = true;

            setBusy(true, 'Starting your plan…');
            await finish(await post(form.dataset.subscribe, {
                payment_method: setupIntent.payment_method,
                name: value.name,
                address: value.address,
            }));
        } catch (problem) {
            setBusy(false);
            showError(problem.message);
            if (cardSaved) readyLabel.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Try again';
        }
    });
})();
