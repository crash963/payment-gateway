<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>PayFlow Copilot</title>
<style>
    :root {
        --bg: #0f1115;
        --panel: #171a21;
        --border: #2a2e38;
        --text: #e6e8ec;
        --muted: #8b909c;
        --accent: #4f8cff;
        --user-bubble: #2a3a5c;
        --assistant-bubble: #1f232c;
        --tool-bubble: #14261c;
        --confirm-bubble: #3a2a14;
        --danger: #ff6b6b;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: -apple-system, Segoe UI, Roboto, sans-serif;
        background: var(--bg);
        color: var(--text);
        display: flex;
        flex-direction: column;
        height: 100vh;
    }
    header {
        padding: 12px 20px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
    header h1 { font-size: 16px; margin: 0; flex: 0 0 auto; }
    header .sub { color: var(--muted); font-size: 12px; }
    header input {
        background: var(--panel);
        border: 1px solid var(--border);
        color: var(--text);
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 12px;
        width: 260px;
    }
    #log {
        flex: 1;
        overflow-y: auto;
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        max-width: 820px;
        width: 100%;
        margin: 0 auto;
    }
    .msg { padding: 10px 14px; border-radius: 10px; max-width: 75%; line-height: 1.45; white-space: pre-wrap; }
    .msg.user { align-self: flex-end; background: var(--user-bubble); }
    .msg.assistant { align-self: flex-start; background: var(--assistant-bubble); }
    .msg.tool {
        align-self: center;
        background: var(--tool-bubble);
        color: var(--muted);
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 12px;
        max-width: 90%;
        border: 1px solid var(--border);
    }
    .msg.confirm {
        align-self: flex-start;
        background: var(--confirm-bubble);
        border: 1px solid #6b4a1a;
        max-width: 85%;
    }
    .msg.confirm .actions { margin-top: 10px; display: flex; gap: 8px; }
    .msg.confirm button {
        border: none;
        border-radius: 6px;
        padding: 6px 14px;
        cursor: pointer;
        font-size: 13px;
    }
    .btn-confirm { background: var(--accent); color: white; }
    .btn-cancel { background: transparent; color: var(--muted); border: 1px solid var(--border) !important; }
    footer {
        border-top: 1px solid var(--border);
        padding: 14px 20px;
        display: flex;
        gap: 10px;
        max-width: 820px;
        width: 100%;
        margin: 0 auto;
    }
    #input {
        flex: 1;
        background: var(--panel);
        border: 1px solid var(--border);
        color: var(--text);
        padding: 10px 14px;
        border-radius: 8px;
        font-size: 14px;
    }
    #send {
        background: var(--accent);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
    }
    #send:disabled { opacity: 0.5; cursor: default; }
    .error { color: var(--danger); font-size: 13px; }
</style>
</head>
<body>

<header>
    <h1>PayFlow Copilot</h1>
    <span class="sub">demo rozhraní — žádné vlastní přihlášení stránky, jen API klíč níže</span>
    <input id="apiKey" type="text" placeholder="Authorization: Bearer <api_key>" value="pf_demo_local_testing_key">
</header>

<div id="log"></div>

<footer>
    <input id="input" type="text" placeholder="Zeptej se na platbu, refund, webhook..." autocomplete="off">
    <button id="send">Odeslat</button>
</footer>

<script>
const log = document.getElementById('log');
const input = document.getElementById('input');
const sendBtn = document.getElementById('send');
const apiKeyInput = document.getElementById('apiKey');

let conversation = [];

function addBubble(role, text) {
    const el = document.createElement('div');
    el.className = 'msg ' + role;
    el.textContent = text;
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
    return el;
}

function addToolTrace(name, args) {
    addBubble('tool', `🔧 ${name}(${JSON.stringify(args)})`);
}

function addConfirmBubble(details, onConfirm) {
    const el = document.createElement('div');
    el.className = 'msg confirm';
    const desc = document.createElement('div');
    desc.textContent = 'Copilot navrhuje akci se skutečným efektem: ' + JSON.stringify(details, null, 2);
    desc.style.whiteSpace = 'pre-wrap';
    el.appendChild(desc);
    const actions = document.createElement('div');
    actions.className = 'actions';
    const confirmBtn = document.createElement('button');
    confirmBtn.className = 'btn-confirm';
    confirmBtn.textContent = 'Potvrdit a provést';
    confirmBtn.onclick = () => { el.remove(); onConfirm(); };
    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn-cancel';
    cancelBtn.textContent = 'Zrušit';
    cancelBtn.onclick = () => el.remove();
    actions.appendChild(confirmBtn);
    actions.appendChild(cancelBtn);
    el.appendChild(actions);
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
}

// Renders whatever new messages CopilotService appended since our last request
// (tool calls, tool results, the final assistant answer) - assistant/tool-call
// messages have no 'content' when the model only called a tool, so we skip those.
function renderNewMessages(before, after) {
    for (let i = before; i < after.length; i++) {
        const m = after[i];
        if (m.role === 'assistant' && Array.isArray(m.tool_calls)) {
            for (const tc of m.tool_calls) {
                let args = {};
                try { args = JSON.parse(tc.function.arguments); } catch (e) {}
                addToolTrace(tc.function.name, args);
            }
        } else if (m.role === 'tool') {
            let parsed = {};
            try { parsed = JSON.parse(m.content); } catch (e) {}
            if (parsed.requires_confirmation) {
                addConfirmBubble(parsed.details, () => {
                    sendMessage('Ano, potvrzuji.');
                });
            }
        }
        // Plain assistant text replies are shown separately via the top-level
        // `message` field in the response, not here - avoids double-rendering.
    }
}

async function sendMessage(text) {
    conversation.push({ role: 'user', content: text });
    addBubble('user', text);

    sendBtn.disabled = true;
    const before = conversation.length;

    try {
        const res = await fetch('/api/copilot/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + apiKeyInput.value.trim(),
            },
            body: JSON.stringify({ messages: conversation }),
        });

        const data = await res.json();

        if (!res.ok) {
            addBubble('tool', 'Chyba: ' + (data.error ? data.error.message : res.status));
            return;
        }

        renderNewMessages(before, data.conversation);
        conversation = data.conversation;

        if (data.message) {
            addBubble('assistant', data.message);
        }
    } catch (e) {
        addBubble('tool', 'Chyba spojení: ' + e.message);
    } finally {
        sendBtn.disabled = false;
    }
}

sendBtn.addEventListener('click', () => {
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    sendMessage(text);
});

input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') sendBtn.click();
});
</script>

</body>
</html>
