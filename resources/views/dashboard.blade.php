<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>PayFlow Dashboard</title>
<style>
    :root {
        --bg: #0f1115;
        --panel: #171a21;
        --border: #2a2e38;
        --text: #e6e8ec;
        --muted: #8b909c;
        --accent: #4f8cff;
        --green: #3ecf8e;
        --red: #ff6b6b;
        --orange: #e8a53d;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: -apple-system, Segoe UI, Roboto, sans-serif;
        background: var(--bg);
        color: var(--text);
    }
    header {
        padding: 12px 20px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
    header h1 { font-size: 16px; margin: 0; }
    header .sub { color: var(--muted); font-size: 12px; }
    header input { margin-left: auto; }
    header a { color: var(--accent); font-size: 13px; text-decoration: none; }
    input, select, button {
        background: var(--panel);
        border: 1px solid var(--border);
        color: var(--text);
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 13px;
    }
    button { cursor: pointer; }
    button.primary { background: var(--accent); color: white; border: none; }
    button:disabled { opacity: 0.5; cursor: default; }
    #app {
        display: grid;
        grid-template-columns: 380px 1fr;
        gap: 20px;
        padding: 20px;
        align-items: start;
    }
    section {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 16px;
    }
    section h2 { font-size: 14px; margin: 0 0 12px; display: flex; justify-content: space-between; align-items: center; }
    section h3 { font-size: 12px; color: var(--muted); text-transform: uppercase; margin: 16px 0 8px; }
    .field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
    .field label { font-size: 12px; color: var(--muted); }
    .field input, .field select { width: 100%; }
    .row { display: flex; gap: 10px; }
    .row .field { flex: 1; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { text-align: left; padding: 6px 4px; border-bottom: 1px solid var(--border); }
    th { color: var(--muted); font-weight: normal; font-size: 11px; text-transform: uppercase; }
    tbody tr { cursor: pointer; }
    tbody tr:hover { background: rgba(255,255,255,0.03); }
    tbody tr.selected { background: rgba(79,140,255,0.12); }
    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
    }
    .badge-pending { background: rgba(232,165,61,0.15); color: var(--orange); }
    .badge-authorized { background: rgba(79,140,255,0.15); color: var(--accent); }
    .badge-paid { background: rgba(62,207,142,0.15); color: var(--green); }
    .badge-failed { background: rgba(255,107,107,0.15); color: var(--red); }
    .badge-partially_refunded { background: rgba(232,165,61,0.15); color: var(--orange); }
    .badge-refunded { background: rgba(139,144,156,0.15); color: var(--muted); }
    .error { color: var(--red); font-size: 12px; margin-top: 6px; }
    .muted { color: var(--muted); }
    #detailEmpty { color: var(--muted); font-size: 13px; }
    ul.timeline { list-style: none; margin: 0; padding: 0; font-size: 13px; }
    ul.timeline li { padding: 6px 0; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; gap: 10px; }
    ul.timeline li:last-child { border-bottom: none; }
    ul.timeline .meta { color: var(--muted); font-size: 11px; }
    .success-dot, .fail-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: 6px; }
    .success-dot { background: var(--green); }
    .fail-dot { background: var(--red); }
    details summary { cursor: pointer; color: var(--muted); font-size: 12px; margin-bottom: 8px; }
</style>
</head>
<body>

<header>
    <h1>PayFlow Dashboard</h1>
    <span class="sub">demo rozhraní — žádné vlastní přihlášení stránky, jen API klíč níže (viz storage/docs/15-payments-dashboard.md)</span>
    <a href="/copilot">→ AI Copilot</a>
    <input id="apiKey" type="text" placeholder="Authorization: Bearer <api_key>" value="pf_demo_local_testing_key">
</header>

<div id="app">
    <div>
        <section>
            <h2>Nová platba</h2>
            <form id="createForm">
                <div class="field">
                    <label for="orderId">Order ID (bez prefixu)</label>
                    <input id="orderId" type="text" placeholder="order-1234">
                </div>
                <div class="field">
                    <label for="idempotencyKey">Idempotency-Key</label>
                    <div style="display:flex; gap:6px;">
                        <input id="idempotencyKey" type="text" style="flex:1; font-family: ui-monospace, monospace; font-size:12px;">
                        <button type="button" id="regenIdempotencyKey" title="Vygenerovat nový klíč">↻</button>
                    </div>
                    <span class="muted" style="font-size:11px">Stejný klíč + stejné parametry = stejná platba (ne duplikát). Stejný klíč + jiné parametry = 409 konflikt. Klíč se needituje sám - nech ho být pro demo replay, klikni ↻ pro novou platbu.</span>
                </div>
                <div class="field">
                    <label for="scenario">Scénář (fake provider)</label>
                    <select id="scenario">
                        <option value="">Úspěch</option>
                        <option value="DECLINE-">Zamítnuto</option>
                        <option value="TIMEOUT-">Timeout</option>
                        <option value="SLOW-">Pomalá odpověď</option>
                        <option value="DUPLICATE-">Duplicitní callback</option>
                        <option value="INVALID-">Neplatný podpis callbacku</option>
                    </select>
                </div>
                <div class="row">
                    <div class="field">
                        <label for="amount">Částka (v haléřích)</label>
                        <input id="amount" type="number" min="1" value="259900">
                    </div>
                    <div class="field">
                        <label for="currency">Měna</label>
                        <input id="currency" type="text" value="CZK">
                    </div>
                </div>
                <details>
                    <summary>Volitelné (return_url, callback_url)</summary>
                    <div class="field">
                        <label for="returnUrl">return_url</label>
                        <input id="returnUrl" type="text" placeholder="https://...">
                    </div>
                    <div class="field">
                        <label for="callbackUrl">callback_url</label>
                        <input id="callbackUrl" type="text" placeholder="https://...">
                    </div>
                </details>
                <button type="submit" class="primary" id="createSubmitBtn">Založit platbu</button>
                <div id="createError" class="error"></div>
            </form>
        </section>

        <section>
            <h2>Platby <button id="refreshBtn">↻ Aktualizovat</button></h2>
            <table>
                <thead>
                    <tr><th>Order ID</th><th>Částka</th><th>Status</th></tr>
                </thead>
                <tbody id="paymentsBody"></tbody>
            </table>
            <div id="listError" class="error"></div>
        </section>
    </div>

    <section id="detailSection">
        <div id="detailEmpty">Vyber platbu vlevo, nebo založ novou.</div>
        <div id="detailContent" hidden>
            <h2 id="detailTitle"></h2>
            <div id="detailMeta" class="muted"></div>

            <h3>Historie (payment_events)</h3>
            <ul class="timeline" id="eventsList"></ul>

            <h3>Webhook doručení merchantovi</h3>
            <ul class="timeline" id="deliveriesList"></ul>
            <p class="muted" style="font-size:12px">Znovu-poslání webhooku je záměrně jen přes <a href="/copilot" style="color:var(--accent)">AI Copilota</a> (human-in-the-loop demo) - není zdvojené i sem.</p>

            <h3>Refundy</h3>
            <ul class="timeline" id="refundsList"></ul>
            <form id="refundForm" hidden>
                <div class="field">
                    <label for="refundIdempotencyKey">Idempotency-Key</label>
                    <div style="display:flex; gap:6px;">
                        <input id="refundIdempotencyKey" type="text" style="flex:1; font-family: ui-monospace, monospace; font-size:12px;">
                        <button type="button" id="regenRefundIdempotencyKey" title="Vygenerovat nový klíč">↻</button>
                    </div>
                </div>
                <div class="row">
                    <div class="field">
                        <label for="refundAmount">Částka k vrácení (haléře)</label>
                        <input id="refundAmount" type="number" min="1">
                    </div>
                    <div class="field" style="justify-content:flex-end">
                        <button type="submit" class="primary" id="refundSubmitBtn">Vytvořit refund</button>
                    </div>
                </div>
                <div id="refundError" class="error"></div>
            </form>
        </div>
    </section>
</div>

<script>
const apiKeyInput = document.getElementById('apiKey');
const paymentsBody = document.getElementById('paymentsBody');
const listError = document.getElementById('listError');
const createForm = document.getElementById('createForm');
const createError = document.getElementById('createError');
const detailEmpty = document.getElementById('detailEmpty');
const detailContent = document.getElementById('detailContent');
const detailTitle = document.getElementById('detailTitle');
const detailMeta = document.getElementById('detailMeta');
const eventsList = document.getElementById('eventsList');
const deliveriesList = document.getElementById('deliveriesList');
const refundsList = document.getElementById('refundsList');
const refundForm = document.getElementById('refundForm');
const refundError = document.getElementById('refundError');

let selectedPaymentId = null;
let pollTimer = null;

function authHeaders() {
    return { Authorization: 'Bearer ' + apiKeyInput.value.trim() };
}

async function api(method, path, body, extraHeaders = {}) {
    const res = await fetch('/api' + path, {
        method,
        headers: { 'Content-Type': 'application/json', ...authHeaders(), ...extraHeaders },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const err = new Error(data.error ? data.error.message : `HTTP ${res.status}`);
        err.status = res.status;
        throw err;
    }
    return data;
}

function money(amount, currency) {
    return (amount / 100).toFixed(2) + ' ' + currency;
}

function badge(status) {
    return `<span class="badge badge-${status}">${status}</span>`;
}

function formatDate(iso) {
    return new Date(iso).toLocaleString('cs-CZ');
}

async function refreshPayments() {
    listError.textContent = '';
    try {
        const { data } = await api('GET', '/payments?per_page=25');
        paymentsBody.innerHTML = data.map(p => `
            <tr data-id="${p.id}" class="${p.id === selectedPaymentId ? 'selected' : ''}">
                <td>${p.order_id}</td>
                <td>${money(p.amount, p.currency)}</td>
                <td>${badge(p.status)}</td>
            </tr>
        `).join('');
        paymentsBody.querySelectorAll('tr').forEach(tr => {
            tr.addEventListener('click', () => selectPayment(tr.dataset.id));
        });
    } catch (e) {
        listError.textContent = 'Chyba: ' + e.message;
    }
}

function stopPolling() {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
}

function selectPayment(id) {
    selectedPaymentId = id;
    stopPolling();
    document.querySelectorAll('#paymentsBody tr').forEach(tr => {
        tr.classList.toggle('selected', tr.dataset.id === id);
    });
    loadDetail(id);
}

// Recursive setTimeout, not setInterval - avoids overlapping requests if one call
// takes longer than the poll interval, and lets us stop cleanly the moment the
// payment leaves 'pending' instead of firing one extra tick.
async function loadDetail(id) {
    try {
        const [paymentRes, eventsRes, deliveriesRes, refundsRes] = await Promise.all([
            api('GET', `/payments/${id}`),
            api('GET', `/payments/${id}/events`),
            api('GET', `/payments/${id}/webhook-deliveries`),
            api('GET', `/payments/${id}/refunds`),
        ]);

        if (selectedPaymentId !== id) return; // user picked something else meanwhile

        renderDetail(paymentRes.data, eventsRes.data, deliveriesRes.data, refundsRes.data);

        if (paymentRes.data.status === 'pending') {
            pollTimer = setTimeout(() => loadDetail(id), 2000);
        } else {
            refreshPayments(); // status just resolved (or already had) - sync the table's badge too
        }
    } catch (e) {
        detailMeta.innerHTML = `<span class="error">Chyba: ${e.message}</span>`;
    }
}

function renderDetail(payment, events, deliveries, refunds) {
    detailEmpty.hidden = true;
    detailContent.hidden = false;

    detailTitle.innerHTML = `${payment.order_id} ${badge(payment.status)}`;
    detailMeta.innerHTML = `
        ${money(payment.amount, payment.currency)} &middot;
        ID: ${payment.id} &middot;
        založeno ${formatDate(payment.created_at)}
        ${payment.status === 'pending' ? ' &middot; <span class="muted">čeká se na potvrzení od providera...</span>' : ''}
    `;

    eventsList.innerHTML = events.map(e => `
        <li>
            <span>${e.type}</span>
            <span class="meta">${formatDate(e.created_at)}</span>
        </li>
    `).join('') || '<li class="muted">Zatím žádné události.</li>';

    deliveriesList.innerHTML = deliveries.map(d => `
        <li>
            <span><span class="${d.successful ? 'success-dot' : 'fail-dot'}"></span>pokus #${d.attempt} - HTTP ${d.http_status ?? '—'}</span>
            <span class="meta">${formatDate(d.sent_at)}</span>
        </li>
    `).join('') || '<li class="muted">Zatím žádné doručení.</li>';

    const refundedTotal = refunds.reduce((sum, r) => sum + r.amount, 0);
    const remaining = payment.amount - refundedTotal;

    refundsList.innerHTML = refunds.map(r => `
        <li>
            <span>${money(r.amount, payment.currency)}</span>
            <span class="meta">${formatDate(r.created_at)}</span>
        </li>
    `).join('') || '<li class="muted">Zatím žádné refundy.</li>';

    const refundable = ['paid', 'partially_refunded'].includes(payment.status) && remaining > 0;
    refundForm.hidden = !refundable;
    refundForm.dataset.paymentId = payment.id;
    if (refundable) {
        document.getElementById('refundAmount').max = remaining;
        document.getElementById('refundAmount').placeholder = `max ${remaining}`;
    }
}

const createSubmitBtn = document.getElementById('createSubmitBtn');
const refundSubmitBtn = document.getElementById('refundSubmitBtn');
const idempotencyKeyInput = document.getElementById('idempotencyKey');
const refundIdempotencyKeyInput = document.getElementById('refundIdempotencyKey');

// Real clients generate/manage this key themselves (often tied to their own order
// id, reused on retry) - a human never types it during a real checkout. But this is
// a TESTING tool, and the single most important thing to be able to demo live is
// idempotent replay: same key + same params -> same payment back, same key +
// different params -> 409. So unlike a real integration, the key is a visible,
// editable field here - prefilled with a fresh one so the common "just click"
// case needs zero typing, but left untouched after a successful submit (not
// auto-regenerated) so clicking "Založit platbu" again immediately re-sends the
// same key on purpose, if that's what you want to demonstrate.
function regenerateKey(input) {
    input.value = crypto.randomUUID();
}
regenerateKey(idempotencyKeyInput);
regenerateKey(refundIdempotencyKeyInput);
document.getElementById('regenIdempotencyKey').addEventListener('click', () => regenerateKey(idempotencyKeyInput));
document.getElementById('regenRefundIdempotencyKey').addEventListener('click', () => regenerateKey(refundIdempotencyKeyInput));

createForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    createError.textContent = '';

    const prefix = document.getElementById('scenario').value;
    const baseOrderId = document.getElementById('orderId').value.trim() || `order-${Date.now()}`;
    const amount = parseInt(document.getElementById('amount').value, 10);
    const currency = document.getElementById('currency').value.trim() || 'CZK';
    const returnUrl = document.getElementById('returnUrl').value.trim();
    const callbackUrl = document.getElementById('callbackUrl').value.trim();
    const idempotencyKey = idempotencyKeyInput.value.trim() || crypto.randomUUID();

    // Guards against an accidental double-click/double-submit while a request is in
    // flight - a DIFFERENT concern from the idempotency key above. That key protects a
    // deliberate retry with the SAME key; this protects against firing two requests
    // (each possibly with different in-flight field values) before the first even
    // returns.
    createSubmitBtn.disabled = true;
    try {
        const body = { order_id: prefix + baseOrderId, amount, currency };
        if (returnUrl) body.return_url = returnUrl;
        if (callbackUrl) body.callback_url = callbackUrl;

        const { data } = await api('POST', '/payments', body, { 'Idempotency-Key': idempotencyKey });

        document.getElementById('orderId').value = '';
        await refreshPayments();
        selectPayment(data.id); // jump straight to the new payment and start polling it
    } catch (e) {
        createError.textContent = 'Chyba: ' + e.message;
    } finally {
        createSubmitBtn.disabled = false;
    }
});

refundForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    refundError.textContent = '';
    const paymentId = refundForm.dataset.paymentId;
    const amount = parseInt(document.getElementById('refundAmount').value, 10);
    const idempotencyKey = refundIdempotencyKeyInput.value.trim() || crypto.randomUUID();

    // Same double-submit guard as createForm above - a different concern from the
    // idempotency key, which is left as-is after submit on purpose (see above).
    refundSubmitBtn.disabled = true;
    try {
        await api('POST', `/payments/${paymentId}/refunds`, { amount }, { 'Idempotency-Key': idempotencyKey });
        document.getElementById('refundAmount').value = '';
        loadDetail(paymentId);
    } catch (e) {
        refundError.textContent = 'Chyba: ' + e.message;
    } finally {
        refundSubmitBtn.disabled = false;
    }
});

document.getElementById('refreshBtn').addEventListener('click', refreshPayments);

refreshPayments();
</script>

</body>
</html>
