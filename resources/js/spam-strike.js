(() => {
    if (window.SpamStrike) return;

    const tokenUrl = document.currentScript?.dataset.tokenUrl;
    const minimumMs = Number(document.currentScript?.dataset.minimumSeconds || 3) * 1000 + 200;
    const tokens = new WeakMap();

    // Fetched on the first touch of a form, never on page load: a visit alone asks nothing of the server.
    const token = (form) => {
        if (!tokens.has(form)) {
            const pending = fetch(tokenUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((answer) => (answer.ok ? answer.json() : Promise.reject(answer)))
                .then((data) => {
                    const field = form.querySelector('[data-spam-strike-token]');
                    if (field) field.value = data.token;
                    return { token: data.token, receivedAt: performance.now() };
                })
                .catch((error) => {
                    tokens.delete(form);
                    throw error;
                });
            tokens.set(form, pending);
        }

        return tokens.get(form);
    };

    // Resolves once the form holds a token that is old enough to be accepted.
    const ready = async (form) => {
        const { token: value, receivedAt } = await token(form);
        const wait = minimumMs - (performance.now() - receivedAt);
        if (wait > 0) await new Promise((resolve) => setTimeout(resolve, wait));
        return value;
    };

    const protectedForm = (target) => target instanceof Element && target.closest('form[data-spam-strike]');

    ['focusin', 'pointerdown', 'input'].forEach((type) => {
        document.addEventListener(type, (event) => {
            const form = protectedForm(event.target);
            if (form) token(form).catch(() => {});
        }, true);
    });

    // In the capture phase, before the page's own submit handlers: a form without a
    // ready token waits for one and is then submitted again.
    const settled = new WeakSet();
    document.addEventListener('submit', (event) => {
        const form = protectedForm(event.target);
        // data-spam-strike="manual": the page awaits SpamStrike.ready(form) in its own handler.
        if (!form || settled.has(form) || form.dataset.spamStrike === 'manual') return;

        event.preventDefault();
        event.stopImmediatePropagation();

        ready(form).then(() => {
            settled.add(form);
            form.requestSubmit(event.submitter && form.contains(event.submitter) ? event.submitter : undefined);
            settled.delete(form);
        }).catch(() => {
            form.dispatchEvent(new CustomEvent('spam-strike:error', { bubbles: true }));
        });
    }, true);

    // For a form sent with fetch(): await SpamStrike.ready(form) before building the FormData.
    window.SpamStrike = { ready, token };
})();
