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

    const dark = document.documentElement.dataset.bsTheme === 'dark';
    const stripe = Stripe(form.dataset.key);
    const elements = stripe.elements({
        clientSecret: form.dataset.secret,
        fonts: [{ cssSrc: 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap' }],
        appearance: {
            theme: dark ? 'night' : 'stripe',
            variables: {
                colorPrimary: '#8cbc43',
                ...(dark ? {} : { colorText: '#202426' }),
                colorDanger: '#b3261e',
                fontFamily: 'Roboto, system-ui, sans-serif',
                borderRadius: '8px',
                spacingUnit: '4px',
            },
            rules: dark ? {} : {
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

// Shared by the two windows below: post JSON to our server with the page's CSRF token
const billingPost = async (form, url, body) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
        },
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok && !data.message) data.message = 'Something went wrong. Please try again in a moment.';
    return data;
};

const stripeAppearance = {
    theme: document.documentElement.dataset.bsTheme === 'dark' ? 'night' : 'stripe',
    variables: { colorPrimary: '#8cbc43', fontFamily: 'Roboto, system-ui, sans-serif', borderRadius: '8px' },
};

// My profile → Change card: Stripe's card field inside our window, loaded when the window opens
(() => {
    const form = document.querySelector('[data-card-form]');
    if (!form || typeof Stripe === 'undefined') return;

    const modal = form.closest('.modal');
    const button = form.querySelector('[data-card-button]');
    const spinner = form.querySelector('[data-card-spinner]');
    const message = form.querySelector('[data-card-message]');
    const stripe = Stripe(form.dataset.key);
    let elements = null;
    let card = null;

    const busy = (on) => {
        button.disabled = on;
        spinner.classList.toggle('d-none', !on);
    };
    const fail = (text) => {
        busy(false);
        message.textContent = text;
        message.classList.remove('d-none');
    };

    modal.addEventListener('shown.bs.modal', async () => {
        if (card) return;
        busy(true);
        const data = await billingPost(form, form.dataset.intent, {});
        if (!data.secret) return fail(data.message || 'The card form could not load. Please try again.');
        elements = stripe.elements({ clientSecret: data.secret, appearance: stripeAppearance });
        card = elements.create('payment', { layout: 'tabs', terms: { card: 'never' } });
        card.on('ready', () => busy(false));
        card.mount(form.querySelector('[data-card-element]'));
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!card || button.disabled) return;
        message.classList.add('d-none');
        busy(true);
        const { error, setupIntent } = await stripe.confirmSetup({
            elements,
            redirect: 'if_required',
            confirmParams: { return_url: form.dataset.return },
        });
        if (error) return fail(error.message);

        const data = await billingPost(form, form.dataset.save, { payment_method: setupIntent.payment_method });
        if (data.redirect) {
            window.location.assign(data.redirect);
            window.location.reload();
            return;
        }
        // A card Stripe already checked cannot be checked again: a fresh form loads on the next try
        card.destroy();
        card = null;
        fail(data.message || 'The card could not be saved. Please try again.');
        modal.dispatchEvent(new Event('shown.bs.modal'));
    });
})();

// Add-on purchase window: pays on our page; a bank approval (3-D Secure) opens right here
(() => {
    const form = document.querySelector('[data-addon-form]');
    if (!form) return;

    const button = form.querySelector('[data-addon-button]');
    const spinner = form.querySelector('[data-addon-spinner]');
    const message = form.querySelector('[data-addon-message]');
    const stripe = typeof Stripe !== 'undefined' && form.dataset.key ? Stripe(form.dataset.key) : null;

    const busy = (on) => {
        button.disabled = on;
        spinner.classList.toggle('d-none', !on);
    };
    const fail = (text) => {
        busy(false);
        message.textContent = text;
        message.classList.remove('d-none');
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (button.disabled) return;
        message.classList.add('d-none');
        busy(true);

        let data = await billingPost(form, form.action, {});
        if (data.confirm) {
            if (!stripe) return fail('Your bank wants to approve this payment, but the secure window could not load. Please turn off any ad blocker and try again.');
            const { error } = await stripe.handleNextAction({ clientSecret: data.confirm });
            // Either way the server checks the payment itself and takes the add-on back off when it was not paid
            data = await billingPost(form, form.dataset.confirmUrl, { payment: data.payment });
            if (error && !data.redirect) return fail(error.message || data.message);
        }
        if (data.redirect) {
            window.location.assign(data.redirect);
            return;
        }
        fail(data.message || 'The payment did not go through. Please try again.');
    });
})();
