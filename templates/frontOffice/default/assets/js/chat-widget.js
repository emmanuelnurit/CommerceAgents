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

        askAddToCart(product) {
            this.sendSuggestion(this.i18n.addToCartPrompt + ' ' + product.title);
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

            if (payload.name === 'search_products' && Array.isArray(result.products) && result.products.length > 0) {
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
                this.messages.push({ kind: 'products', role: 'assistant', data: [result.product], category: null });
                this.highlights = [result.product];
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
