// One-page sign-up: details + Stripe's card field in one form. The card goes straight to Stripe; our server checks
// the details, takes the first payment and only then creates the account. A progress window shows each step.
(() => {
    const form = document.querySelector('[data-signup-form]');
    if (!form) return;

    const button = form.querySelector('[data-signup-button]');
    const message = form.querySelector('[data-signup-message]');
    const progress = document.querySelector('[data-signup-progress]');
    const bar = progress.querySelector('[data-progress-bar]');
    const barWrap = progress.querySelector('[data-progress-wrap]');
    const title = progress.querySelector('[data-progress-title]');
    const token = form.querySelector('input[name="_token"]').value;

    const showError = (text) => {
        message.textContent = text;
        message.classList.remove('d-none');
        message.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    if (typeof Stripe === 'undefined') {
        button.disabled = true;
        showError('The secure payment form could not load. Please turn off any ad or script blocker for this page and refresh.');
        return;
    }

    // ---- Stripe fields (no payment is prepared until the form is sent, so the page opens fast)
    const dark = document.documentElement.dataset.bsTheme === 'dark';
    const stripe = Stripe(form.dataset.key);
    const elements = stripe.elements({
        mode: 'subscription',
        amount: Number(form.dataset.amount),
        currency: 'usd',
        paymentMethodCreation: 'manual',
        paymentMethodTypes: ['card'],
        appearance: {
            theme: dark ? 'night' : 'stripe',
            variables: { colorPrimary: '#8cbc43', fontFamily: 'Roboto, system-ui, sans-serif', borderRadius: '8px' },
        },
    });
    const address = elements.create('address', { mode: 'billing', defaultValues: { address: { country: 'US' } } });
    const payment = elements.create('payment', { layout: 'tabs', fields: { billingDetails: { address: 'never' } }, terms: { card: 'never' } });
    address.mount(form.querySelector('[data-address-element]'));
    payment.mount(form.querySelector('[data-payment-element]'));

    // The business name fills the billing name until the customer types their own
    const companyInput = form.querySelector('[name="company_name"]');
    companyInput?.addEventListener('change', () => address.update?.({ defaultValues: { name: companyInput.value } }));

    // ---- Progress window
    const steps = ['check', 'card', 'pay', 'books'];
    let creep = null;
    const setProgress = (step, percent, text) => {
        progress.classList.remove('d-none');
        title.textContent = text;
        bar.style.width = `${percent}%`;
        barWrap.setAttribute('aria-valuenow', String(percent));
        const at = steps.indexOf(step);
        progress.querySelectorAll('[data-progress-step]').forEach((li) => {
            const i = steps.indexOf(li.dataset.progressStep);
            li.classList.toggle('is-done', i < at);
            li.classList.toggle('is-current', i === at);
            li.querySelector('i').className = i < at ? 'bi bi-check-circle-fill' : (i === at ? 'bi bi-arrow-right-circle' : 'bi bi-circle');
        });
        // While the server works the bar keeps moving slowly, so it never looks stuck
        clearInterval(creep);
        const ceiling = Math.min(percent + 20, 95);
        creep = setInterval(() => {
            const now = parseFloat(bar.style.width);
            if (now < ceiling) bar.style.width = `${Math.min(ceiling, now + 0.6)}%`;
        }, 250);
    };
    const hideProgress = () => {
        clearInterval(creep);
        progress.classList.add('d-none');
        button.disabled = false;
    };

    // ---- Field errors from the server, shown under each field
    const clearErrors = () => {
        message.classList.add('d-none');
        form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        form.querySelectorAll('[data-js-error]').forEach((el) => el.remove());
    };
    const showFieldErrors = (errors) => {
        let first = null;
        Object.entries(errors).forEach(([name, list]) => {
            const input = form.querySelector(`[name="${name}"]`);
            if (!input) return;
            input.classList.add('is-invalid');
            const feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            feedback.dataset.jsError = '';
            feedback.textContent = list[0];
            (input.closest('.form-floating, .form-check') || input.parentElement).append(feedback);
            first ??= input;
        });
        const other = Object.entries(errors).filter(([name]) => !form.querySelector(`[name="${name}"]`)).map(([, list]) => list[0]);
        if (other.length) showError(other.join(' '));
        else message.classList.add('d-none');
        first?.focus({ preventScroll: true });
        first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    const post = async (url, body) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));
        if (response.status === 419) data.message = 'This page was open for a long time. Please refresh it and try again.';
        if (!response.ok && !data.message) data.message = 'Something went wrong. Please try again in a moment.';
        return data;
    };

    const finish = async (data) => {
        if (data.redirect) {
            setProgress('books', 100, 'All set! Opening your dashboard…');
            progress.querySelectorAll('[data-progress-step]').forEach((li) => {
                li.classList.add('is-done');
                li.querySelector('i').className = 'bi bi-check-circle-fill';
            });
            clearInterval(creep);
            window.location.assign(data.redirect);
            return;
        }
        if (data.confirm) {
            setProgress('pay', 60, 'Waiting for your bank to approve…');
            const { error } = await stripe.handleNextAction({ clientSecret: data.confirm });
            setProgress('pay', 75, 'Confirming the payment…');
            const checked = await post(form.dataset.complete, {});
            if (error && !checked.redirect) throw new Error(error.message || checked.message);
            return finish(checked);
        }
        if (data.errors) {
            hideProgress();
            showFieldErrors(data.errors);
            return;
        }
        throw new Error(data.message || 'The payment did not go through. Please try again.');
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (button.disabled) return;
        clearErrors();
        button.disabled = true;

        try {
            setProgress('check', 8, 'Checking your details…');
            const { error: fieldError } = await elements.submit();   // card and address fields
            if (fieldError) throw new Error(fieldError.message);
            const { value } = await address.getValue();

            setProgress('card', 25, 'Securing your card…');
            const { error, paymentMethod } = await stripe.createPaymentMethod({
                elements,
                params: { billing_details: { name: value.name, address: value.address } },
            });
            if (error) throw new Error(error.message);

            setProgress('pay', 45, 'Taking the first payment…');
            const fields = Object.fromEntries(new FormData(form).entries());
            const data = await post(form.action, {
                ...fields,
                terms: form.querySelector('[name="terms"]')?.checked ? '1' : '',
                payment_method: paymentMethod.id,
                billing: { name: value.name, address: value.address },
            });
            if (data.redirect) setProgress('books', 90, 'Setting up your books…');
            await finish(data);
        } catch (problem) {
            hideProgress();
            showError(problem.message);
        }
    });
})();
