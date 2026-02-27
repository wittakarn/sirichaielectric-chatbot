/**
 * SirichaiElectric Chatbot — Embeddable Widget
 *
 * Usage: paste ONE line before </body> on any page:
 *   <script src="https://YOUR-SERVER/sirichaielectric-chatbot/widget-embed.js"></script>
 *
 * The chat UI lives in chat.html on the same server as this script.
 * No configuration needed — chat.html URL is derived automatically.
 */
(function () {
    'use strict';

    // ── Resolve URLs relative to this script's own location ──────────
    var scripts  = document.getElementsByTagName('script');
    var scriptEl = scripts[scripts.length - 1];          // current script
    var scriptSrc = scriptEl.src || '';
    var BASE_URL  = scriptSrc.substring(0, scriptSrc.lastIndexOf('/') + 1);
    var CHAT_URL  = BASE_URL + 'chat.html';

    // ── Inject CSS ────────────────────────────────────────────────────
    var css = [
        '#se-launcher{',
            'position:fixed;bottom:24px;right:24px;',
            'width:60px;height:60px;border-radius:50%;',
            'background:linear-gradient(135deg,#cc0000 0%,#990000 100%);',
            'border:none;cursor:pointer;',
            'box-shadow:0 4px 20px rgba(204,0,0,.5);',
            'display:flex;align-items:center;justify-content:center;',
            'transition:transform .2s,box-shadow .2s;z-index:999998;',
        '}',
        '#se-launcher:hover{transform:scale(1.08);box-shadow:0 6px 28px rgba(204,0,0,.65);}',
        '#se-launcher svg{transition:opacity .2s,transform .2s;position:absolute;}',
        '#se-launcher .se-icon-chat {opacity:1;transform:rotate(0deg)    scale(1);}',
        '#se-launcher .se-icon-close{opacity:0;transform:rotate(-90deg)  scale(.5);}',
        '#se-launcher.se-open .se-icon-chat {opacity:0;transform:rotate(90deg) scale(.5);}',
        '#se-launcher.se-open .se-icon-close{opacity:1;transform:rotate(0deg)  scale(1);}',
        '#se-badge{',
            'position:absolute;top:0;right:0;',
            'width:18px;height:18px;background:#f56565;',
            'border-radius:50%;border:2px solid white;',
            'font-size:10px;font-weight:700;color:white;font-family:sans-serif;',
            'display:flex;align-items:center;justify-content:center;',
            'opacity:0;transform:scale(0);transition:opacity .2s,transform .2s;',
        '}',
        '#se-badge.se-visible{opacity:1;transform:scale(1);}',
        '#se-panel{',
            'position:fixed;bottom:96px;right:24px;',
            'width:380px;height:560px;max-height:calc(100vh - 120px);',
            'border-radius:16px;box-shadow:0 12px 48px rgba(0,0,0,.22);',
            'overflow:hidden;border:none;z-index:999999;',
            'opacity:0;transform:translateY(16px) scale(.97);',
            'transform-origin:bottom right;pointer-events:none;',
            'transition:opacity .22s ease,transform .22s ease;',
        '}',
        '#se-panel.se-open{opacity:1;transform:translateY(0) scale(1);pointer-events:auto;}',
        '@media(max-width:480px){',
            '#se-panel{right:0;left:0;bottom:0;width:100%;height:80vh;max-height:80vh;',
                'border-radius:16px 16px 0 0;transform-origin:bottom center;}',
            '#se-launcher{bottom:16px;right:16px;}',
        '}'
    ].join('');

    var styleEl = document.createElement('style');
    styleEl.textContent = css;
    document.head.appendChild(styleEl);

    // ── Inject HTML ───────────────────────────────────────────────────
    var launcher = document.createElement('button');
    launcher.id        = 'se-launcher';
    launcher.title     = 'Chat with SirichaiElectric';
    launcher.innerHTML =
        '<svg class="se-icon-chat" width="26" height="26" viewBox="0 0 24 24" fill="none"' +
            ' stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>' +
        '</svg>' +
        '<svg class="se-icon-close" width="24" height="24" viewBox="0 0 24 24" fill="none"' +
            ' stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
            '<line x1="18" y1="6" x2="6" y2="18"/>' +
            '<line x1="6"  y1="6" x2="18" y2="18"/>' +
        '</svg>' +
        '<div id="se-badge">1</div>';

    var panel = document.createElement('iframe');
    panel.id    = 'se-panel';
    panel.title = 'SirichaiElectric Chatbot';
    panel.setAttribute('allow', 'clipboard-write');

    document.body.appendChild(launcher);
    document.body.appendChild(panel);

    // ── Widget logic ──────────────────────────────────────────────────
    var badge        = document.getElementById('se-badge');
    var isOpen       = false;
    var iframeLoaded = false;

    function toggle() {
        isOpen = !isOpen;
        panel.classList.toggle('se-open', isOpen);
        launcher.classList.toggle('se-open', isOpen);

        if (isOpen) {
            badge.classList.remove('se-visible');
            if (!iframeLoaded) {
                panel.src  = CHAT_URL;
                iframeLoaded = true;
            }
        }
    }

    launcher.addEventListener('click', toggle);

    // Invite badge after 2 s
    setTimeout(function () {
        if (!isOpen) badge.classList.add('se-visible');
    }, 2000);

    // Listen for events from the iframe
    window.addEventListener('message', function (e) {
        if (!e.data) return;
        if (e.data.type === 'sirichai:newMessage' && !isOpen) {
            badge.classList.add('se-visible');
        }
        if (e.data.type === 'sirichai:close' && isOpen) {
            toggle();
        }
    });

})();
