'use strict';

/**
 * Alpine component for the CommerceAgents shopping assistant.
 *
 * Streams /agent/chat SSE events. LLM text is only ever rendered through
 * x-text (never as HTML); rich blocks are built from structured tool results.
 */
var COMMERCE_AGENTS_STORAGE_KEY = 'commerceagents.chat';
var COMMERCE_AGENTS_MAX_PERSISTED = 50;

function commerceAgentsChat() {
    return {
        open: false,
        pending: false,
        input: '',
        lastSent: '',
        messages: [],
        cartCount: 0,
        cartTotal: 0,
        currency: 'EUR',
        pendingNavigationUrl: null,

        init() {
            const root = document.getElementById('commerce-agents-widget');
            if (root) {
                this.cartCount = parseInt(root.dataset.cartCount || '0', 10);
                this.cartTotal = parseFloat(root.dataset.cartTotal || '0');
                this.currency = root.dataset.currency || 'EUR';
            }
            try {
                const saved = JSON.parse(sessionStorage.getItem(COMMERCE_AGENTS_STORAGE_KEY) || 'null');
                if (saved && Array.isArray(saved.messages)) {
                    this.messages = saved.messages;
                    this.open = !!saved.open;
                }
            } catch (error) {
                sessionStorage.removeItem(COMMERCE_AGENTS_STORAGE_KEY);
            }

            this.$watch('messages', () => this.persist());
            this.$watch('open', () => this.persist());
        },

        persist() {
            try {
                const messages = this.messages.slice(-COMMERCE_AGENTS_MAX_PERSISTED).map(function (message) {
                    const copy = Object.assign({}, message);
                    delete copy.streaming;
                    return copy;
                });
                sessionStorage.setItem(COMMERCE_AGENTS_STORAGE_KEY, JSON.stringify({ open: this.open, messages: messages }));
            } catch (error) {
                // storage full or unavailable: the chat still works, it just won't survive navigation
            }
        },

        formatPrice(product) {
            if (product.price === null || product.price === undefined) {
                return '';
            }
            const price = product.promoPrice && product.promoPrice < product.price
                ? product.promoPrice
                : product.price;
            return price + ' ' + (product.currency || '');
        },

        retry() {
            if (this.lastSent !== '') {
                this.streamMessage(this.lastSent);
            }
        },

        cartBannerLabel() {
            const isFrench = (document.documentElement.lang || '').toLowerCase().startsWith('fr');
            return this.cartCount + (isFrench ? ' article(s)' : ' item(s)') + ' — ' + this.cartTotal + ' ' + this.currency;
        },

        sendSuggestion(text) {
            this.input = text.trim();
            this.send();
        },

        updateCartFromResult(cart) {
            if (cart && typeof cart.itemCount === 'number') {
                this.cartCount = cart.itemCount;
                this.cartTotal = cart.totalTaxedAmount;
                if (cart.currency) {
                    this.currency = cart.currency;
                }
            }
        },

        send() {
            const text = this.input.trim();
            if (text === '' || this.pending) {
                return;
            }
            this.input = '';
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
                    this.pushError(payload.error || 'Service unavailable');
                    return;
                }

                await this.readStream(response.body.getReader());
            } catch (error) {
                this.pushError('Connection lost');
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
                this.pushError(payload.message || 'Something went wrong');
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
                this.messages.push({ kind: 'products', role: 'assistant', data: result.products });
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
