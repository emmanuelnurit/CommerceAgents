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


/**
 * Drops the bullet points that merely restate a product already displayed as a
 * card. The intro and the closing sentence are kept: the assistant may still
 * conclude or open on something else, it just stops reciting the cards.
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
    const repeats = function (line) {
        const haystack = line.toLowerCase();
        return needles.some(function (needle) { return haystack.indexOf(needle) !== -1; });
    };

    const lines = text.split('\n');
    const kept = [];
    let dropped = false;

    for (let index = 0; index < lines.length; index += 1) {
        if (isItem(lines[index]) && repeats(lines[index])) {
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

    if (!dropped) {
        return text;
    }

    // A line that introduced the removed list would be left dangling on its colon.
    const cleaned = kept
        .map(function (line) { return /[:：]\s*$/.test(line) ? line.replace(/\s*[:：]\s*$/, '.') : line; })
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
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
        locale: 'en-US',
        pendingNavigationUrl: null,
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
            if (config.i18n) {
                this.i18n = Object.assign({}, this.i18n, config.i18n);
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
         * Titles already on screen just above this message, if any.
         */
        shownBefore(index) {
            const previous = this.messages[index - 1];
            if (!previous || (previous.kind !== 'products' && previous.kind !== 'variants')) {
                return [];
            }
            return (previous.data || []).map(function (entry) {
                return entry.productTitle || entry.title || '';
            });
        },

        displayText(message, index) {
            return commerceAgentsWithoutRepeats(message.text, this.shownBefore(index));
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
                    this.messages.push({ kind: 'products', role: 'assistant', data: [product], category: null });
                }
                this.highlights = [product];
            } else if ((payload.name === 'get_cart' || payload.name === 'add_to_cart') && result.cart) {
                this.messages.push({ kind: 'cart', role: 'assistant', data: result.cart });
                this.updateCartFromResult(result.cart);
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
    };
}

window.commerceAgentsChat = commerceAgentsChat;
