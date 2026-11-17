// Voice call (SPIKE). Loaded by chat.html. The 📞 button shows only when the server has CALL_ENABLED=1
// AND the page asked for it: chat.html?call=1 (the host passes it via widget-embed.js?call=1) or localhost.
// Uses chat.html globals: API_BASE_URL, getChatToken, chatToken, addMessage.
// Browser streams mic audio straight to Gemini Live with a single-use token from call.php;
// tools run on call.php. Any failure here leaves the text chat as it was.
if (new URLSearchParams(location.search).get('call') === '1' || ['localhost', '127.0.0.1'].includes(location.hostname)) {
    fetch(API_BASE_URL + 'call.php').then(r => r.ok && initCall()).catch(() => {});
}

function initCall() {
    if (!navigator.mediaDevices || !window.WebSocket) return; // no mic API (old browser / plain http)

    const WS = 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContentConstrained?access_token=';
    const btn = document.createElement('button');
    btn.id = 'callButton';
    btn.title = 'โทรคุยกับผู้ช่วย';
    btn.setAttribute('aria-label', 'โทรคุยกับผู้ช่วย');
    const PHONE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1z"/></svg>';
    btn.innerHTML = PHONE;
    // Same look as #sendButton (red gradient pill); grey while a call is up.
    const css = document.createElement('style');
    css.textContent = '#callButton{display:inline-flex;align-items:center;justify-content:center;gap:4px;min-width:36px;height:36px;padding:0 10px;margin-right:6px;'
        + 'background:linear-gradient(135deg,#cc0000 0%,#990000 100%);color:#fff;border:none;border-radius:20px;font-size:13px;font-weight:600;cursor:pointer;'
        + 'transition:transform .2s,box-shadow .2s,opacity .2s;white-space:nowrap}'
        + '#callButton:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 3px 10px rgba(204,0,0,.4)}'
        + '#callButton:disabled{opacity:.5;cursor:not-allowed}'
        + '#callButton.in-call{background:linear-gradient(135deg,#555 0%,#333 100%)}';
    document.head.appendChild(css);
    const send = document.getElementById('sendButton');
    send.parentNode.insertBefore(btn, send);

    let call = null; // {ws, mic, inCtx, outCtx, timer, ...} while a call is up

    btn.onclick = () => (call ? hangUp() : start().catch(e => { console.error(e); hangUp('โทรไม่ได้ขณะนี้ ลองพิมพ์แทนได้เลยค่ะ'); }));

    async function callPost(action, body) {
        for (let i = 0; i < 2; i++) {
            const res = await fetch(API_BASE_URL + 'call.php?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Chat-Token': await getChatToken() },
                body: JSON.stringify(body || {})
            });
            if (res.status !== 401) return res.json();
            chatToken = null; sessionStorage.removeItem('sirichai_chat_token');
        }
        throw new Error('verify failed');
    }

    async function start() {
        btn.disabled = true; btn.textContent = '…';
        const mic = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, channelCount: 1 } });
        const t = await callPost('token');
        if (!t.success) throw new Error(t.error);

        const ws = new WebSocket(WS + encodeURIComponent(t.token));
        call = { ws, mic, userText: '', botText: '', log: [], sources: new Set(), playAt: 0, ends: Date.now() + t.maxSeconds * 1000 };
        call.inCtx = new AudioContext({ sampleRate: 16000 });
        call.outCtx = new AudioContext({ sampleRate: 24000 });

        ws.onopen = () => ws.send(JSON.stringify({ setup: { model: t.model } }));
        ws.onclose = (e) => { if (call && call.ws === ws) hangUp(e.code === 1000 ? '' : 'สายหลุด (' + e.code + (e.reason ? ' ' + e.reason : '') + ')'); };
        ws.onmessage = async (e) => {
            const msg = JSON.parse(typeof e.data === 'string' ? e.data : await e.data.text());
            if (msg.setupComplete) return startMic();
            if (msg.toolCall) return runTools(msg.toolCall.functionCalls || []);
            const sc = msg.serverContent;
            if (!sc) return;
            if (sc.inputTranscription) call.userText += sc.inputTranscription.text || '';
            if (sc.outputTranscription) { flushUser(); call.botText += sc.outputTranscription.text || ''; }
            if (sc.interrupted) stopPlayback();
            ((sc.modelTurn && sc.modelTurn.parts) || []).forEach(p => {
                if (p.inlineData && p.inlineData.data) { flushUser(); play(p.inlineData.data); }
            });
            if (sc.turnComplete) { flushUser(); flushBot(); }
        };

        call.timer = setInterval(() => {
            const s = Math.max(0, Math.round((call.ends - Date.now()) / 1000));
            btn.innerHTML = PHONE + ' ' + Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
            if (!s) hangUp('ครบเวลาโทร 10 นาทีค่ะ');
        }, 1000);
        btn.disabled = false; btn.classList.add('in-call'); btn.title = 'วางสาย';
    }

    function startMic() {
        const src = call.inCtx.createMediaStreamSource(call.mic);
        // ponytail: ScriptProcessor is deprecated but works everywhere; AudioWorklet if it gets dropped
        const proc = call.inCtx.createScriptProcessor(4096, 1, 1);
        proc.onaudioprocess = (ev) => {
            if (!call || call.ws.readyState !== 1) return;
            const f = ev.inputBuffer.getChannelData(0), pcm = new Int16Array(f.length);
            for (let i = 0; i < f.length; i++) pcm[i] = Math.max(-1, Math.min(1, f[i])) * 0x7fff;
            call.ws.send(JSON.stringify({ realtimeInput: { audio: { mimeType: 'audio/pcm;rate=16000', data: b64(pcm.buffer) } } }));
        };
        src.connect(proc); proc.connect(call.inCtx.destination);
        call.proc = proc;
    }

    function play(base64) {
        const bytes = Uint8Array.from(atob(base64), c => c.charCodeAt(0));
        const pcm = new Int16Array(bytes.buffer, 0, bytes.length >> 1);
        const buf = call.outCtx.createBuffer(1, pcm.length, 24000);
        const ch = buf.getChannelData(0);
        for (let i = 0; i < pcm.length; i++) ch[i] = pcm[i] / 0x8000;
        const s = call.outCtx.createBufferSource();
        s.buffer = buf; s.connect(call.outCtx.destination);
        call.playAt = Math.max(call.playAt, call.outCtx.currentTime);
        s.start(call.playAt); call.playAt += buf.duration;
        call.sources.add(s); s.onended = () => call && call.sources.delete(s);
    }

    function stopPlayback() {
        call.sources.forEach(s => { try { s.stop(); } catch (e) {} });
        call.sources.clear(); call.playAt = 0;
    }

    async function runTools(calls) {
        flushUser();
        const responses = await Promise.all(calls.map(async fc => {
            let result;
            try { result = (await callPost('tool', { name: fc.name, args: fc.args || {} })).result; }
            catch (e) { result = 'Tool failed, ask the customer to try again.'; }
            if (fc.name === 'generate_quotation') {
                // Service answers {"pdfUrl":"https:\/\/…"} — JSON-escaped slashes, so unescape before matching.
                const url = String(result).replace(/\\\//g, '/').match(/https?:\/\/[^\s"'<>\\]+/);
                if (url) {
                    addMessage('assistant', '📄 [ดาวน์โหลดใบเสนอราคา](' + url[0] + ')');
                    if (call) call.log.push({ role: 'assistant', content: '📄 [ดาวน์โหลดใบเสนอราคา](' + url[0] + ')' });
                }
            }
            return { id: fc.id, name: fc.name, response: { result: String(result) } };
        }));
        if (call) call.ws.send(JSON.stringify({ toolResponse: { functionResponses: responses } }));
    }

    function flushUser() { if (call && call.userText.trim()) { addMessage('user', '🎙 ' + call.userText.trim()); call.log.push({ role: 'user', content: call.userText.trim() }); call.userText = ''; } }
    function flushBot() { if (call && call.botText.trim()) { addMessage('assistant', call.botText.trim()); call.log.push({ role: 'assistant', content: call.botText.trim() }); call.botText = ''; } }

    // One POST per call (not per turn) so a call doesn't eat the per-minute limit.
    // keepalive lets it finish when the tab closes; browsers cap keepalive bodies at 64KB.
    function saveLog(lines) {
        if (!lines.length || !chatToken) return;
        const body = JSON.stringify({ lines });
        fetch(API_BASE_URL + 'call.php?action=log', {
            method: 'POST', keepalive: body.length < 60000,
            headers: { 'Content-Type': 'application/json', 'X-Chat-Token': chatToken },
            body
        }).catch(() => {});
    }
    window.addEventListener('pagehide', () => { if (call) hangUp(); });

    function hangUp(note) {
        if (call) {
            flushUser(); flushBot();
            saveLog(call.log);
            clearInterval(call.timer);
            try { call.ws.close(1000); } catch (e) {}
            if (call.proc) call.proc.disconnect();
            if (call.mic) call.mic.getTracks().forEach(t => t.stop());
            if (call.inCtx) call.inCtx.close();
            if (call.outCtx) call.outCtx.close();
            call = null;
        }
        if (note) addMessage('assistant', note);
        btn.disabled = false; btn.innerHTML = PHONE; btn.classList.remove('in-call'); btn.title = 'โทรคุยกับผู้ช่วย';
    }

    function b64(buf) {
        const b = new Uint8Array(buf); let s = '';
        for (let i = 0; i < b.length; i += 0x8000) s += String.fromCharCode.apply(null, b.subarray(i, i + 0x8000));
        return btoa(s);
    }
}
