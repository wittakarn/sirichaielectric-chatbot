// Shared reply rendering for chat.html and demo.html: product cards + suggestion chips.
// Needs marked (loaded before this file) and a global sendQuickQuestion(text) + isProcessing.
(function () {
    const style = document.createElement('style');
    style.textContent = `
        .message.has-products { flex-direction: column; align-items: flex-start; gap: 8px; }
        .product-list {
            width: 92%; background: #f9fafb; border: 1px solid #e5e7eb;
            border-radius: 12px; overflow: hidden; font-size: 13px;
        }
        .product-list-head { padding: 8px 12px; font-weight: 600; color: #374151; }
        .product-card { display: flex; gap: 10px; padding: 10px 12px; background: white; border-top: 1px solid #e5e7eb; }
        .product-card img {
            width: 64px; height: 64px; object-fit: contain; flex-shrink: 0;
            border-radius: 6px; background: #f3f4f6;
        }
        .product-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
        .product-name { color: #1f2937; text-decoration: none; line-height: 1.35; }
        .product-name:hover { text-decoration: underline; }
        .product-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: auto; }
        .product-price { color: #cc0000; font-weight: 700; }
        .product-price small { color: #6b7280; font-weight: 400; }
        .product-cart {
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 28px; border-radius: 6px;
            background: linear-gradient(135deg, #cc0000 0%, #990000 100%);
        }
        .product-cart.added { background: #16a34a; }
        .product-pager {
            display: flex; align-items: center; justify-content: space-between;
            padding: 8px 12px; border-top: 1px solid #e5e7eb; color: #374151;
        }
        .product-pager button {
            padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 6px;
            background: white; font: inherit; font-size: 12px; cursor: pointer;
        }
        .product-pager button:disabled { opacity: 0.4; cursor: not-allowed; }
        .suggest-chips { display: flex; flex-wrap: wrap; gap: 6px; max-width: 92%; }
        .suggest-chip {
            padding: 5px 12px; border: 1px solid #cc0000; border-radius: 16px;
            background: white; color: #cc0000; font: inherit; font-size: 12px; cursor: pointer;
        }
        .suggest-chip:hover { background: #fef2f2; }
    `;
    document.head.appendChild(style);
})();

function markdownToHtml(md) {
    const renderer = new marked.Renderer();
    renderer.link = (token) => {
        const t = token.title ? ` title="${token.title}"` : '';
        return `<a href="${token.href}"${t} target="_blank" rel="noopener noreferrer">${token.text}</a>`;
    };
    marked.setOptions({ breaks: true, gfm: true, renderer });
    return marked.parse(md);
}

function renderMarkdown(md) {
    const div = document.createElement('div');
    div.className = 'message-content';
    div.innerHTML = markdownToHtml(md);
    return div;
}

// Product line format is fixed by system-prompt.txt: "[Name](https://shop.sirichaielectric.com/product/Id) ราคา: Price บาท/Unit"
const PRODUCT_LINE = /^\s*(?:[-*+]|\d+[.)])?\s*\**\[([^\]]+)\]\((https:\/\/shop\.sirichaielectric\.com\/product\/\d+)\)\**\s*[-–:]?\s*(ราคา|Price)\s*:?\s*\**([\d,.]+)\**\s*(?:บาท|THB)\s*\/\s*(.+?)\s*$/;

function extractProducts(content) {
    const before = [], after = [], products = [];
    let priceWord = false;
    for (const line of content.split('\n')) {
        const m = line.match(PRODUCT_LINE);
        if (m) {
            products.push({ name: m[1], url: m[2], price: m[4], unit: m[5] });
            priceWord = priceWord || m[3] === 'Price';
        } else {
            (products.length ? after : before).push(line);
        }
    }
    // AI replies in the customer's language; product names can be Thai either way, so judge by the text around them.
    const text = (before.join('') + after.join('')).replace(/\[[^\]]*\]\([^)]*\)/g, '');
    const english = /[A-Za-z฀-๿]/.test(text) ? !/[฀-๿]/.test(text) : priceWord;
    return { before: before.join('\n'), products, after: after.join('\n'), english };
}

// Shop image path: product name with each space, '.' and '/' → '-' (1x2.5 → 1x2-5, 1/4GALLON → 1-4GALLON; double spaces stay double).
// (image/image-url.php?id= exists but returns a PHP warning instead of the image.)
function productImageUrl(name) {
    return SHOP_ORIGIN + '/image/product/' + encodeURIComponent(name.trim().replace(/[\s.\/]/g, '-')) + '.jpg';
}

// Chip line format is fixed by system-prompt.txt (SUGGESTION CHIPS): "[[suggest: A | B | C]]"
const CHIP_LINE = /^\s*\[\[\s*suggest\s*:([^\]]*)\]\]\s*$/im;

function extractChips(content) {
    const m = content.match(CHIP_LINE);
    if (!m) return { text: content, chips: [] };
    const chips = m[1].split('|').map(s => s.trim()).filter(Boolean).slice(0, 4);
    return { text: content.replace(CHIP_LINE, '').trim(), chips };
}

function renderChips(chips) {
    const box = document.createElement('div');
    box.className = 'suggest-chips';
    for (const c of chips) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'suggest-chip';
        b.textContent = c;
        b.onclick = () => { if (!isProcessing) sendQuickQuestion(c); };
        box.appendChild(b);
    }
    return box;
}

const PAGE_SIZE = 5;
const CART_ICON = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';

// Cart bridge: the shop page owns the cart (shop repo js/ChatbotCart.js documents the protocol).
// Until the shop answers cartReady, the cart button is a plain link to the product page.
const SHOP_ORIGIN = 'https://shop.sirichaielectric.com';
let shopCartReady = false;
const pendingCart = new Map(); // requestId → callback(ok)

window.addEventListener('message', (e) => {
    if (e.origin !== SHOP_ORIGIN || e.source !== window.parent || !e.data) return;
    if (e.data.type === 'sirichai:cartReady') shopCartReady = true;
    if (e.data.type === 'sirichai:cartAdded' && pendingCart.has(e.data.requestId)) {
        pendingCart.get(e.data.requestId)(e.data.ok === true);
        pendingCart.delete(e.data.requestId);
    }
});
if (window.parent !== window) window.parent.postMessage({ type: 'sirichai:cartHello' }, SHOP_ORIGIN);

function addToShopCart(productId, done) {
    const requestId = Date.now().toString(36) + Math.random().toString(36).slice(2);
    pendingCart.set(requestId, done);
    window.parent.postMessage({ type: 'sirichai:addToCart', requestId, productId, quantity: 1 }, SHOP_ORIGIN);
}

function renderProductList(products, english) {
    const box = document.createElement('div');
    box.className = 'product-list';
    const pages = Math.ceil(products.length / PAGE_SIZE);
    let page = 0;

    const draw = () => {
        box.innerHTML = '';
        const head = document.createElement('div');
        head.className = 'product-list-head';
        head.textContent = english ? `Found ${products.length} items` : `พบ ${products.length} รายการ`;
        box.appendChild(head);

        for (const p of products.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE)) {
            const card = document.createElement('div');
            card.className = 'product-card';

            const img = document.createElement('img');
            img.src = productImageUrl(p.name);
            img.alt = p.name;
            img.loading = 'lazy';
            img.onerror = () => { img.onerror = null; img.style.visibility = 'hidden'; };

            const info = document.createElement('div');
            info.className = 'product-info';
            const name = document.createElement('a');
            name.className = 'product-name';
            name.href = p.url; name.target = '_blank'; name.rel = 'noopener noreferrer';
            name.textContent = p.name;

            const row = document.createElement('div');
            row.className = 'product-row';
            const price = document.createElement('span');
            price.className = 'product-price';
            price.textContent = '฿ ' + p.price + ' ';
            const unit = document.createElement('small');
            unit.textContent = '/' + p.unit;
            price.appendChild(unit);
            const cart = document.createElement('a');
            cart.className = 'product-cart';
            cart.href = p.url; cart.target = '_blank'; cart.rel = 'noopener noreferrer';
            cart.title = shopCartReady ? (english ? 'Add to cart' : 'เพิ่มลงตะกร้า') : (english ? 'View product' : 'ดูสินค้า');
            cart.setAttribute('aria-label', cart.title + ' ' + p.name);
            cart.innerHTML = CART_ICON;
            cart.onclick = (e) => {
                if (!shopCartReady) return; // not on the shop: follow the link
                e.preventDefault();
                addToShopCart(p.url.split('/').pop(), (ok) => {
                    cart.classList.toggle('added', ok);
                    cart.title = ok
                        ? (english ? 'Added — click to add 1 more' : 'เพิ่มแล้ว — กดอีกครั้งเพื่อเพิ่ม 1 ชิ้น')
                        : (english ? 'Could not add to cart' : 'เพิ่มลงตะกร้าไม่สำเร็จ');
                });
            };

            row.append(price, cart);
            info.append(name, row);
            card.append(img, info);
            box.appendChild(card);
        }

        if (pages > 1) {
            const pager = document.createElement('div');
            pager.className = 'product-pager';
            const prev = document.createElement('button');
            prev.textContent = english ? '‹ Prev' : '‹ ก่อนหน้า';
            prev.disabled = page === 0;
            prev.onclick = () => { page--; draw(); };
            const label = document.createElement('span');
            label.textContent = (english ? 'Page ' : 'หน้า ') + (page + 1) + ' / ' + pages;
            const next = document.createElement('button');
            next.textContent = english ? 'Next ›' : 'ถัดไป ›';
            next.disabled = page === pages - 1;
            next.onclick = () => { page++; draw(); };
            pager.append(prev, label, next);
            box.appendChild(pager);
        }
    };
    draw();
    return box;
}


// Fills msgDiv (a ".message assistant" div) with the reply: markdown bubbles, product cards, chips.
function renderAssistant(msgDiv, content) {
    const { text, chips } = extractChips(content);
    const { before, products, after, english } = extractProducts(text);
    if (!products.length && !chips.length) {
        msgDiv.appendChild(renderMarkdown(text));
        return;
    }
    msgDiv.classList.add('has-products');
    if (before.trim()) msgDiv.appendChild(renderMarkdown(before));
    if (products.length) msgDiv.appendChild(renderProductList(products, english));
    if (after.trim()) msgDiv.appendChild(renderMarkdown(after));
    if (chips.length) msgDiv.appendChild(renderChips(chips));
}
