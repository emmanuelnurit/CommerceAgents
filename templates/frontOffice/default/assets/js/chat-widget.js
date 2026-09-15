'use strict';

/**
 * Alpine component for the CommerceAgents shopping assistant.
 *
 * Streams /agent/chat SSE events. LLM text is only ever rendered through
 * x-text (never as HTML); rich blocks are built from structured tool results.
 */
var COMMERCE_AGENTS_STORAGE_KEY = 'commerceagents.chat';
var COMMERCE_AGENTS_MAX_PERSISTED = 50;
var COMMERCE_AGENTS_MAX_HIGHLIGHTS = 3;
var COMMERCE_AGENTS_MAX_RECALLED = 6;

// MYO-236 Lot 3: client-side signal instrumentation, kept in its own
// sessionStorage entry so it survives independently of the truncated
// conversation history in COMMERCE_AGENTS_STORAGE_KEY.
var COMMERCE_AGENTS_SIGNALS_KEY = 'commerceagents.signals';
var COMMERCE_AGENTS_HESITATION_PRODUCT_SECONDS = 45;
var COMMERCE_AGENTS_HESITATION_CART_OPENS = 2;
var COMMERCE_AGENTS_CART_IDLE_SECONDS = 60;


/**
 * Removes what the cards already say. Three shapes get dropped: a bullet
 * naming a displayed product, a paragraph naming one along with its price,
 * and a bare "see the product" line. Everything else survives, so the
 * assistant keeps its intro, its section headings and its closing sentence.
 */
function commerceAgentsWithoutRepeats(text, titles) {
    if (!text || titles.length === 0) {
        return text;
    }

    const needles = titles
        .filter(function (title) { return typeof title === 'string' && title.trim().length >= 3; })
        .map(function (title) { return title.trim().toLowerCase(); });
    if (needles.length === 0) {
        return text;
    }

    const isItem = function (line) { return /^\s*(?:[-*+]|\d+[.)])\s+/.test(line); };
    const isContinuation = function (line) { return /^\s+\S/.test(line) && !isItem(line); };
    const names = function (chunk) {
        const haystack = chunk.toLowerCase();
        return needles.some(function (needle) { return haystack.indexOf(needle) !== -1; });
    };
    const hasPrice = function (chunk) {
        return /(?:[€$£]|\bEUR\b|\bUSD\b|\bGBP\b)/i.test(chunk) && /\d/.test(chunk);
    };
    // "Voir Wilson en noir" duplicates the button the card already carries.
    const isCallToAction = function (chunk) {
        const trimmed = chunk.trim();
        return /^\[[^\]]+\]\([^)]+\)$/.test(trimmed)
            || /^(?:voir|d[ée]couvrir|consulter|see|view|shop|browse)\b/i.test(trimmed);
    };

    let dropped = false;

    const blocks = text.split(/\n\s*\n/).map(function (block) {
        const lines = block.split('\n');

        if (lines.some(isItem)) {
            const kept = [];
            for (let index = 0; index < lines.length; index += 1) {
                if (isItem(lines[index]) && names(lines[index])) {
                    dropped = true;
                    index += 1;
                    while (index < lines.length && isContinuation(lines[index])) {
                        index += 1;
                    }
                    index -= 1;
                    continue;
                }
                kept.push(lines[index]);
            }
            return kept.join('\n');
        }

        if (names(block) && (hasPrice(block) || isCallToAction(block))) {
            dropped = true;
            return '';
        }

        return block;
    });

    if (!dropped) {
        return text;
    }

    // A line that introduced the removed content would be left dangling on its colon.
    const cleaned = blocks
        .filter(function (block) { return block.trim() !== ''; })
        .map(function (block) {
            return /[:：]\s*$/.test(block) ? block.replace(/\s*[:：]\s*$/, '.') : block;
        })
        .join('\n\n')
        .trim();

    // Never blank a reply: an answer made only of repeats stays as it was.
    return cleaned === '' ? text : cleaned;
}

function commerceAgentsChat() {
    return {
        // dock: nothing said yet — collapsed: a conversation to keep an eye on
        // while browsing — open: the full-screen panel.
        state: 'dock',
        pending: false,
        input: '',
        lastSent: '',
        messages: [],
        highlights: [],
        cart: { items: [], totalTaxedAmount: 0, currency: 'EUR', itemCount: 0 },
        account: { loggedIn: false, loginUrl: '#', registerUrl: '#' },
        locale: 'en-US',
        checkoutUrl: null,
        pendingNavigationUrl: null,
        // MYO-236: a pending proactive suggestion, shown as a floating card
        // when the widget is closed/collapsed (null when there is none).
        proactive: null,
        i18n: {
            itemsLabel: 'item(s)',
            otherLines: 'other line(s)',
            inStock: 'In stock',
            outOfStock: 'Out of stock',
            inCategory: 'In the category',
            variantsTitle: 'Available options',
            optionResults: 'Variants matching',
            expandAssistant: 'Open the full conversation',
            minimiseAssistant: 'Minimise the conversation',
            addToCartPrompt: 'Add this product to my cart:',
            connectionLost: 'Connection lost',
            serviceUnavailable: 'Service unavailable',
            error: 'Something went wrong',
            proactiveLabel: 'Assistant suggestion',
            suggestionBadge: 'Suggestion',
            dismissSuggestion: 'Dismiss suggestion',
            proactiveSeeMore: 'Tell me more',
            noThanks: 'No thanks',
        },

        init() {
            this.readConfig();
            this.restore();

            this.$watch('messages', () => this.persist());
            this.$watch('state', () => {
                this.persist();
                document.body.classList.toggle('caw-body-locked', this.isOpen);
                if (this.isOpen) {
                    this.scrollDownSoon();
                }
            });

            if (this.isOpen) {
                document.body.classList.add('caw-body-locked');
                this.scrollDownSoon();
            }

            this.initInstrumentation();
        },

        get isOpen() {
            return this.state === 'open';
        },

        get isCollapsed() {
            return this.state === 'collapsed';
        },

        /**
         * Plain text of the last thing the assistant said, for the collapsed card.
         */
        get lastReply() {
            for (let index = this.messages.length - 1; index >= 0; index -= 1) {
                const message = this.messages[index];
                if (message.kind === 'text' && message.role === 'assistant') {
                    return message.text;
                }
                if (message.kind === 'error') {
                    return message.text;
                }
            }
            return '';
        },

        readConfig() {
            const root = document.getElementById('commerce-agents-widget');
            if (!root) {
                return;
            }
            let config = {};
            try {
                config = JSON.parse(root.dataset.config || '{}');
            } catch (error) {
                return;
            }
            if (config.cart) {
                this.cart = config.cart;
            }
            if (config.account) {
                this.account = config.account;
            }
            if (config.i18n) {
                this.i18n = Object.assign({}, this.i18n, config.i18n);
            }
            if (config.checkoutUrl) {
                this.checkoutUrl = config.checkoutUrl;
            }
            // The server speaks Thelia locales (fr_FR), Intl speaks BCP 47 (fr-FR).
            this.locale = (config.locale || 'en_US').replace('_', '-');
        },

        restore() {
            try {
                const saved = JSON.parse(sessionStorage.getItem(COMMERCE_AGENTS_STORAGE_KEY) || 'null');
                if (saved && Array.isArray(saved.messages)) {
                    this.messages = saved.messages;
                    this.highlights = Array.isArray(saved.highlights) ? saved.highlights : [];
                    // A page load never restores the full-screen panel: the visitor
                    // came here to read the page, not to stare at the overlay again.
                    this.state = saved.state === 'open' || saved.state === 'collapsed' || this.messages.length > 0
                        ? 'collapsed'
                        : 'dock';
                }
            } catch (error) {
                try {
                    sessionStorage.removeItem(COMMERCE_AGENTS_STORAGE_KEY);
                } catch (ignored) {
                    // storage unavailable (private browsing): nothing to clean up
                }
            }
        },

        scrollDownSoon() {
            // The panel is behind x-show + transition: right after `open` flips,
            // the list may still be hidden and its scrollHeight unusable.
            setTimeout(() => this.scrollDown(), 120);
        },

        persist() {
            try {
                const messages = this.messages.slice(-COMMERCE_AGENTS_MAX_PERSISTED).map(function (message) {
                    const copy = Object.assign({}, message);
                    delete copy.streaming;
                    return copy;
                });
                sessionStorage.setItem(COMMERCE_AGENTS_STORAGE_KEY, JSON.stringify({
                    state: this.state,
                    messages: messages,
                    highlights: this.highlights,
                }));
            } catch (error) {
                // storage full or unavailable: the chat still works, it just won't survive navigation
            }
        },

        expand(submitAfter) {
            this.state = 'open';
            this.$nextTick(() => {
                const field = this.$refs.composerInput;
                if (field && !this.pending) {
                    field.focus();
                }
                if (submitAfter) {
                    this.send();
                }
            });
        },

        close() {
            this.state = this.messages.length > 0 ? 'collapsed' : 'dock';
        },

        minimise() {
            this.state = 'dock';
        },

        /**
         * Focusing the bottom bar opens the panel only when there is nothing to
         * come back to; with a conversation running the visitor keeps the page.
         */
        focusDock() {
            if (this.state === 'dock' && this.messages.length === 0) {
                this.expand();
            }
        },

        reset() {
            this.messages = [];
            this.highlights = [];
            this.lastSent = '';
            this.input = '';
            this.state = 'dock';
            this.persist();
        },

        money(amount, currencyCode) {
            // Number(null) is 0: a product without a price must stay blank,
            // never read as free.
            if (amount === null || amount === undefined || amount === '') {
                return '';
            }
            const value = Number(amount);
            if (!isFinite(value)) {
                return '';
            }
            const currency = currencyCode || this.cart.currency || 'EUR';
            try {
                return new Intl.NumberFormat(this.locale, { style: 'currency', currency: currency }).format(value);
            } catch (error) {
                return value.toFixed(2) + ' ' + currency;
            }
        },

        hasPromo(product) {
            return typeof product.promoPrice === 'number'
                && typeof product.price === 'number'
                && product.promoPrice > 0
                && product.promoPrice < product.price;
        },

        bestPrice(product) {
            return this.hasPromo(product) ? product.promoPrice : product.price;
        },

        discountPercent(product) {
            if (!this.hasPromo(product)) {
                return 0;
            }
            return Math.round((1 - product.promoPrice / product.price) * 100);
        },

        /**
         * Every product shown as a card so far, keyed by lowercase title. The
         * assistant often names one again from memory, without calling a tool.
         */
        knownProducts() {
            const known = new Map();
            for (const message of this.messages) {
                if (message.kind !== 'products' && message.kind !== 'variants') {
                    continue;
                }
                for (const entry of message.data || []) {
                    const title = entry.productTitle || entry.title || '';
                    if (title === '' || known.has(title.toLowerCase())) {
                        continue;
                    }
                    known.set(title.toLowerCase(), {
                        id: entry.id,
                        ref: entry.ref || null,
                        productTitle: title,
                        label: entry.label || '',
                        url: entry.url || null,
                        imageUrl: entry.imageUrl || null,
                        price: entry.price,
                        promoPrice: entry.promoPrice === undefined ? null : entry.promoPrice,
                        currency: entry.currency || this.cart.currency,
                        inStock: entry.inStock !== false,
                        isVariant: !!(entry.ref && entry.label),
                    });
                }
            }
            return known;
        },

        /**
         * A reply naming a product the visitor can no longer see is a wall of
         * text: put its card back, with its picture and its buttons.
         */
        cardsFor(message, index) {
            if (message.kind !== 'text' || message.role !== 'assistant') {
                return [];
            }
            const previous = this.messages[index - 1];
            if (previous && (previous.kind === 'products' || previous.kind === 'variants')) {
                return [];
            }

            const haystack = (message.text || '').toLowerCase();
            const cards = [];
            this.knownProducts().forEach(function (card, title) {
                if (haystack.indexOf(title) !== -1) {
                    cards.push(card);
                }
            });

            return cards.slice(0, COMMERCE_AGENTS_MAX_RECALLED);
        },

        /**
         * Titles displayed alongside this message, whether by the block above it
         * or by the cards brought back under it.
         */
        shownBefore(index) {
            const previous = this.messages[index - 1];
            if (previous && (previous.kind === 'products' || previous.kind === 'variants')) {
                return (previous.data || []).map(function (entry) {
                    return entry.productTitle || entry.title || '';
                });
            }
            return this.cardsFor(this.messages[index], index).map(function (card) {
                return card.productTitle;
            });
        },

        displayText(message, index) {
            return commerceAgentsWithoutRepeats(message.text, this.shownBefore(index));
        },

        addCardToCart(card) {
            if (card.isVariant) {
                this.askAddVariantToCart(null, card);
                return;
            }
            this.askAddToCart({ title: card.productTitle });
        },

        askAddToCart(product) {
            this.sendSuggestion(this.i18n.addToCartPrompt + ' ' + product.title);
        },

        askAddVariantToCart(product, variant) {
            // The reference is what makes the turn unambiguous: "the blue one"
            // is not enough once a product has five colours.
            const title = variant.productTitle || (product && product.title) || '';
            const label = variant.label ? ' — ' + variant.label : '';
            this.sendSuggestion(this.i18n.addToCartPrompt + ' ' + title + label + ' (' + variant.ref + ')');
        },

        productsCaption(message) {
            if (message.feature) {
                return message.feature.feature + ': ' + message.feature.title;
            }
            return message.category ? this.i18n.inCategory + ' ' + message.category.title : '';
        },

        variantsHeading(message) {
            if (message.option) {
                return this.i18n.optionResults + ' ' + message.option.title;
            }
            return message.product ? message.product.title + ' — ' + this.i18n.variantsTitle : this.i18n.variantsTitle;
        },

        variantUrl(message, variant) {
            return variant.url || (message.product ? message.product.url : '#');
        },

        variantCurrency(message, variant) {
            return variant.currency || (message.product ? message.product.currency : null);
        },

        retry() {
            if (this.lastSent !== '') {
                this.streamMessage(this.lastSent);
            }
        },

        sendSuggestion(text) {
            this.input = String(text).trim();
            this.state = 'open';
            this.send();
        },

        updateCartFromResult(cart) {
            if (cart && typeof cart.itemCount === 'number') {
                this.cart = {
                    items: Array.isArray(cart.items) ? cart.items : [],
                    totalTaxedAmount: cart.totalTaxedAmount,
                    currency: cart.currency || this.cart.currency,
                    itemCount: cart.itemCount,
                };
            }
        },

        send() {
            const text = this.input.trim();
            if (text === '' || this.pending) {
                return;
            }
            this.input = '';
            if (this.state === 'dock') {
                // Resuming a minimised conversation must not throw the panel back
                // over the page the visitor asked to see.
                this.state = this.messages.length > 0 ? 'collapsed' : 'open';
            }
            this.messages.push({ kind: 'text', role: 'user', text: text });
            this.streamMessage(text);
        },

        async streamMessage(text) {
            this.lastSent = text;
            this.pending = true;
            this.scrollDown();

            try {
                const response = await fetch('/agent/chat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message: text }),
                });

                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}));
                    this.pushError(payload.error || this.i18n.serviceUnavailable);
                    return;
                }

                await this.readStream(response.body.getReader());
            } catch (error) {
                this.pushError(this.i18n.connectionLost);
            } finally {
                this.pending = false;
                this.persist();
                this.scrollDown();
            }
        },

        async readStream(reader) {
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) {
                    break;
                }
                buffer += decoder.decode(value, { stream: true });

                let separatorIndex;
                while ((separatorIndex = buffer.indexOf('\n\n')) !== -1) {
                    this.handleFrame(buffer.slice(0, separatorIndex));
                    buffer = buffer.slice(separatorIndex + 2);
                }
            }
        },

        handleFrame(frame) {
            let event = '';
            let data = '';
            for (const line of frame.split('\n')) {
                if (line.startsWith('event: ')) {
                    event = line.slice(7);
                } else if (line.startsWith('data: ')) {
                    data += line.slice(6);
                }
            }
            if (event === '') {
                return;
            }

            let payload = {};
            try {
                payload = data === '' ? {} : JSON.parse(data);
            } catch (error) {
                return;
            }

            if (event === 'text_delta') {
                this.appendAssistantText(payload.text || '');
            } else if (event === 'tool_result') {
                this.pushToolBlock(payload);
            } else if (event === 'error') {
                this.pushError(payload.message || this.i18n.error);
            } else if (event === 'done') {
                this.navigateIfRequested();
            }
            this.scrollDown();
        },

        navigateIfRequested() {
            const url = this.pendingNavigationUrl;
            this.pendingNavigationUrl = null;
            if (!url) {
                return;
            }
            // Defense in depth: the server already refused non-store URLs,
            // but never navigate cross-origin from here either.
            let resolved;
            try {
                resolved = new URL(url, window.location.origin);
            } catch (error) {
                return;
            }
            if (resolved.origin !== window.location.origin) {
                return;
            }
            this.persist();
            setTimeout(function () {
                window.location.assign(resolved.href);
            }, 700);
        },

        appendAssistantText(text) {
            const last = this.messages[this.messages.length - 1];
            if (last && last.kind === 'text' && last.role === 'assistant' && last.streaming) {
                last.text += text;
                return;
            }
            this.messages.push({ kind: 'text', role: 'assistant', text: text, streaming: true });
        },

        pushToolBlock(payload) {
            const result = payload.result || {};
            this.closeStreamingMessage();

            if (payload.name === 'search_products' && Array.isArray(result.variants) && result.variants.length > 0) {
                // An option search answers with variants: only the orange ones,
                // each carrying its own product title and reference.
                this.messages.push({
                    kind: 'variants',
                    role: 'assistant',
                    product: null,
                    option: result.matched_option || null,
                    data: result.variants,
                });
                this.highlights = result.variants.slice(0, COMMERCE_AGENTS_MAX_HIGHLIGHTS).map(function (variant) {
                    return {
                        id: variant.id,
                        title: variant.productTitle,
                        url: variant.url,
                        imageUrl: variant.imageUrl,
                        price: variant.price,
                        promoPrice: variant.promoPrice,
                        currency: variant.currency,
                    };
                });
            } else if (payload.name === 'search_products' && Array.isArray(result.products) && result.products.length > 0) {
                this.messages.push({
                    kind: 'products',
                    role: 'assistant',
                    data: result.products,
                    // Set when the keyword matched no title and the search fell
                    // back to the closest category: say so instead of pretending
                    // the visitor's word was found as such.
                    category: result.matched_category || null,
                    feature: result.matched_feature || null,
                });
                this.highlights = result.products.slice(0, COMMERCE_AGENTS_MAX_HIGHLIGHTS);
            } else if (payload.name === 'get_product_details' && result.product) {
                const product = result.product;
                const variants = Array.isArray(product.pses) ? product.pses : [];
                // One variant is just the product; several are the answer to
                // "which colours do you have?" and deserve their own cards.
                if (variants.length > 1) {
                    this.messages.push({ kind: 'variants', role: 'assistant', product: product, option: null, data: variants });
                } else {
                    this.messages.push({ kind: 'products', role: 'assistant', data: [product], category: null, feature: null });
                }
                this.highlights = [product];
            } else if ((payload.name === 'get_cart' || payload.name === 'add_to_cart') && result.cart) {
                this.messages.push({ kind: 'cart', role: 'assistant', data: result.cart });
                this.updateCartFromResult(result.cart);
                if (payload.name === 'add_to_cart') {
                    this.recordCartActivity();
                }
            } else if (payload.name === 'open_page' && result.navigation && result.navigation.url) {
                this.pendingNavigationUrl = result.navigation.url;
            }
        },

        pushError(text) {
            this.closeStreamingMessage();
            this.messages.push({ kind: 'error', role: 'assistant', text: text });
        },

        closeStreamingMessage() {
            const last = this.messages[this.messages.length - 1];
            if (last && last.streaming) {
                last.streaming = false;
            }
        },

        scrollDown() {
            this.$nextTick(() => {
                const container = this.$refs.messages;
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            });
        },

        // ---------- MYO-236 Lot 3: proactive signal instrumentation ----------
        //
        // The JS only ever *emits* signals; whether a message actually comes
        // back is entirely a server-side decision (ProactiveGuard, then the
        // scenario resolvers) — the client never invents a stock number, a
        // shipping claim or a promo code, it just tells the server what the
        // visitor did.

        readSignals() {
            const defaults = { cartOpens: 0, hesitationSent: false, cartAbandonedSent: false, dismissed: false, lastCartActivityAt: null };
            try {
                const saved = JSON.parse(sessionStorage.getItem(COMMERCE_AGENTS_SIGNALS_KEY) || 'null');
                return Object.assign({}, defaults, saved || {});
            } catch (error) {
                return defaults;
            }
        },

        writeSignals(signals) {
            try {
                sessionStorage.setItem(COMMERCE_AGENTS_SIGNALS_KEY, JSON.stringify(signals));
            } catch (error) {
                // storage full or unavailable: instrumentation is best-effort only
            }
        },

        /**
         * Set by ChatWidgetThemeHook on product pages only (product.bottom hook),
         * with the real product id — never guessed from the URL, which Thelia
         * rewrites unpredictably per catalog.
         */
        readPageContext() {
            return (window.CommerceAgentsPageContext && typeof window.CommerceAgentsPageContext === 'object')
                ? window.CommerceAgentsPageContext
                : null;
        },

        initInstrumentation() {
            this.trackCartOpen();
            this.armHesitationTimerForProductPage();
            this.watchCartActivity();
        },

        proactiveRefused() {
            return this.readSignals().dismissed === true;
        },

        sendProactiveSignal(signalType, context) {
            if (this.proactiveRefused()) {
                return Promise.resolve();
            }
            return fetch('/agent/chat/proactive-check', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ signal_type: signalType, context: context || {} }),
            }).then((response) => (response.ok ? response.json() : null))
                .then((payload) => {
                    if (payload && payload.text) {
                        this.receiveProactiveMessage(payload.scenario || signalType, payload.text, payload.card || null);
                    }
                })
                .catch(() => {
                    // best-effort: a lost connection here must not surface as a chat error
                });
        },

        receiveProactiveMessage(scenario, text, card) {
            if (this.proactiveRefused()) {
                return;
            }
            if (this.isOpen) {
                this.messages.push({ kind: 'proactive', role: 'assistant', scenario: scenario, text: text, card: card || null });
                this.persist();
                this.scrollDown();
            } else {
                // Only one suggestion is ever visible at a time (plan MYO-236
                // frequency guard): a later one simply replaces an unread one.
                this.proactive = { scenario: scenario, text: text, card: card || null };
            }
        },

        acceptProactive() {
            if (!this.proactive) {
                return;
            }
            const proactive = this.proactive;
            this.proactive = null;
            this.messages.push({ kind: 'proactive', role: 'assistant', scenario: proactive.scenario, text: proactive.text, card: proactive.card || null });
            this.persist();
            this.expand();
        },

        dismissProactive() {
            this.proactive = null;
            return this.notifyProactiveDismissed();
        },

        notifyProactiveDismissed() {
            const signals = this.readSignals();
            if (signals.dismissed) {
                return;
            }
            signals.dismissed = true;
            this.writeSignals(signals);
            return fetch('/agent/chat/proactive-dismiss', { method: 'POST' }).catch(() => {});
        },

        // ---------- Scenario 3: hesitation ----------

        triggerHesitation(productId) {
            const signals = this.readSignals();
            if (signals.hesitationSent) {
                return;
            }
            signals.hesitationSent = true;
            this.writeSignals(signals);
            return this.sendProactiveSignal('hesitation', productId ? { product_id: productId } : {});
        },

        armHesitationTimerForProductPage() {
            const context = this.readPageContext();
            if (!context || context.type !== 'product' || !context.productId) {
                return;
            }
            if (this.readSignals().hesitationSent) {
                return;
            }
            setTimeout(() => {
                this.triggerHesitation(context.productId);
            }, COMMERCE_AGENTS_HESITATION_PRODUCT_SECONDS * 1000);
        },

        /**
         * The theme has no mini-cart dropdown: "opening the cart" is a visit to
         * the cart page, detected against the server-provided checkout URL
         * rather than a hardcoded path (Thelia URLs are locale/theme-dependent).
         */
        trackCartOpen() {
            if (!this.checkoutUrl) {
                return;
            }
            let cartPath;
            try {
                cartPath = new URL(this.checkoutUrl, window.location.origin).pathname;
            } catch (error) {
                return;
            }
            if (window.location.pathname !== cartPath) {
                return;
            }

            const signals = this.readSignals();
            if (signals.hesitationSent) {
                return;
            }
            signals.cartOpens += 1;
            this.writeSignals(signals);
            if (signals.cartOpens >= COMMERCE_AGENTS_HESITATION_CART_OPENS) {
                this.triggerHesitation(null);
            }
        },

        // ---------- Scenario 4: abandoned cart (session in progress) ----------

        /**
         * Native "Add to cart" (outside the chat) re-renders the theme's
         * AddToCartToast Live Component in place — no page reload, no plain
         * DOM CustomEvent to listen for. Watching its hidden class is the
         * generic, theme-contract-free way to notice a native add happened.
         */
        observeNativeAddToCart() {
            if (typeof MutationObserver === 'undefined') {
                return;
            }
            const toast = document.querySelector('.AddToCartToast');
            if (!toast) {
                return;
            }
            const observer = new MutationObserver(() => {
                if (!toast.classList.contains('hidden')) {
                    // A native add is not reflected in this.cart (only a chat
                    // add_to_cart tool result updates it): the local count would
                    // stay stale until the next page load, so it is bumped here
                    // too, if only so checkCartAbandoned() does not read a
                    // falsely-empty cart on this same page.
                    this.cart = Object.assign({}, this.cart, { itemCount: this.cart.itemCount + 1 });
                    this.recordCartActivity();
                }
            });
            observer.observe(toast, { attributes: true, attributeFilter: ['class'] });
        },

        watchCartActivity() {
            const signals = this.readSignals();
            if (this.cart.itemCount > 0 && signals.lastCartActivityAt) {
                this.armCartIdleCheck(signals.lastCartActivityAt);
            }
            this.observeNativeAddToCart();
        },

        recordCartActivity() {
            const signals = this.readSignals();
            signals.lastCartActivityAt = Date.now();
            this.writeSignals(signals);
            this.armCartIdleCheck(signals.lastCartActivityAt);
        },

        /**
         * The idle clock is tracked in sessionStorage, not just in this page's
         * timer: across a full page navigation (this is a classic MPA, not an
         * SPA) a fresh page picks up wherever the clock was left, so the idle
         * threshold is measured from the real last activity, not reset by
         * navigation.
         */
        armCartIdleCheck(activityAt) {
            if (this.cartIdleTimer) {
                clearTimeout(this.cartIdleTimer);
            }
            const remaining = (COMMERCE_AGENTS_CART_IDLE_SECONDS * 1000) - (Date.now() - activityAt);
            if (remaining <= 0) {
                this.checkCartAbandoned();
                return;
            }
            this.cartIdleTimer = setTimeout(() => this.checkCartAbandoned(), remaining);
            // A plain number (browser setTimeout) has no unref(): only Node's test
            // environment returns a Timeout object, and only there does this matter —
            // a still-armed idle check must not hold a test process open for up to
            // COMMERCE_AGENTS_CART_IDLE_SECONDS.
            if (this.cartIdleTimer && typeof this.cartIdleTimer.unref === 'function') {
                this.cartIdleTimer.unref();
            }
        },

        checkCartAbandoned() {
            const signals = this.readSignals();
            // The cart itemCount reflects this page's server-rendered snapshot:
            // it is naturally 0 again once the order is placed, which is what
            // stops this from ever firing after checkout.
            if (signals.cartAbandonedSent || this.cart.itemCount === 0) {
                return;
            }
            signals.cartAbandonedSent = true;
            this.writeSignals(signals);
            return this.sendProactiveSignal('cart_abandoned_session', {});
        },
    };
}

window.commerceAgentsChat = commerceAgentsChat;
