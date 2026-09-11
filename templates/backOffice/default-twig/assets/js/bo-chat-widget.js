'use strict';

/**
 * Floating Merchant Agent popup available on every back-office page.
 *
 * Same wire protocol as the full merchant page (SSE on the admin endpoint).
 * LLM text is only rendered through the safe Markdown renderer (DOM built from
 * text, never HTML). Conversation display state survives page changes through
 * sessionStorage; the server-side conversation already follows the admin session.
 * An `open_admin_page` tool result navigates the browser to a back-office page
 * once the turn is done — only same-origin /admin URLs are honoured.
 */
function commerceAgentsBootBoWidget() {
    var STORAGE_KEY = 'commerceagents.bo-chat';
    var MAX_PERSISTED = 50;

    var root = document.getElementById('commerceagents-bo-widget');
    if (!root || document.getElementById('merchant-chat')) {
        // Not rendered, or the full merchant page already hosts the chat.
        return;
    }

    var endpoint = root.dataset.endpoint;
    var i18n = {
        connectionLost: root.dataset.i18nConnectionLost || 'Connection lost',
        serviceUnavailable: root.dataset.i18nServiceUnavailable || 'Service unavailable',
        error: root.dataset.i18nError || 'Something went wrong',
        hidden: root.dataset.i18nHidden || 'Hidden',
        stock: root.dataset.i18nStock || 'Stock'
    };

    var toggle = root.querySelector('.cabw-toggle');
    var panel = document.getElementById('cabw-panel');
    var messagesContainer = document.getElementById('cabw-messages');
    var suggestions = document.getElementById('cabw-suggestions');
    var form = document.getElementById('cabw-form');
    var input = document.getElementById('cabw-input');
    var sendButton = document.getElementById('cabw-send');

    var state = { open: false, messages: [] };
    var streamingBubble = null;
    var streamingRenderFrame = 0;
    var pendingNavigationUrl = null;

    function persist() {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                open: state.open,
                messages: state.messages.slice(-MAX_PERSISTED)
            }));
        } catch (error) {
            // storage unavailable: the popup still works within the page
        }
    }

    function restore() {
        try {
            var saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || 'null');
            if (saved && Array.isArray(saved.messages)) {
                state.messages = saved.messages;
                state.open = !!saved.open;
            }
        } catch (error) {
            sessionStorage.removeItem(STORAGE_KEY);
        }
    }

    function scrollDown() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function setOpen(isOpen) {
        state.open = isOpen;
        panel.hidden = !isOpen;
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        persist();
        if (isOpen) {
            setTimeout(scrollDown, 50);
            input.focus();
        }
    }

    function renderMessage(message) {
        var el;
        if (message.kind === 'user') {
            el = document.createElement('p');
            el.className = 'cabw-bubble cabw-user';
            el.textContent = message.text;
        } else if (message.kind === 'assistant') {
            el = document.createElement('div');
            el.className = 'cabw-bubble cabw-assistant';
            window.CommerceAgentsMarkdown.render(el, message.text);
        } else if (message.kind === 'tool') {
            el = document.createElement('div');
            el.className = 'cabw-tool';
            el.textContent = message.text;
        } else if (message.kind === 'products') {
            el = document.createElement('div');
            el.className = 'cabw-products-wrap';
            window.CommerceAgentsProductCards.render(el, message.items);
        } else {
            el = document.createElement('p');
            el.className = 'cabw-bubble cabw-error';
            el.textContent = message.text;
        }
        messagesContainer.appendChild(el);
        return el;
    }

    function hideSuggestions() {
        if (suggestions && suggestions.parentNode) {
            suggestions.remove();
        }
    }

    function pushMessage(message) {
        state.messages.push(message);
        hideSuggestions();
        var el = renderMessage(message);
        persist();
        scrollDown();
        return el;
    }

    function appendAssistantText(text) {
        if (streamingBubble === null) {
            var message = { kind: 'assistant', text: '' };
            streamingBubble = { message: message, el: pushMessage(message), settledChars: 0, settledNodes: 0 };
        }
        streamingBubble.message.text += text;
        scheduleStreamingRender();
    }

    // Deltas arrive faster than the bubble can be rebuilt and laid out:
    // paint at most once per animation frame, from the accumulated text.
    function scheduleStreamingRender() {
        if (streamingRenderFrame !== 0) {
            return;
        }
        streamingRenderFrame = window.requestAnimationFrame(function () {
            streamingRenderFrame = 0;
            renderStreamingBubble();
        });
    }

    function renderStreamingBubble() {
        if (streamingBubble === null) {
            return;
        }
        var bubble = streamingBubble;
        var text = bubble.message.text;
        // Blocks before the last blank line are final: render them once, then
        // only the tail block is rebuilt on the following frames.
        var boundary = text.lastIndexOf('\n\n');
        if (boundary >= bubble.settledChars) {
            truncateChildren(bubble.el, bubble.settledNodes);
            window.CommerceAgentsMarkdown.append(bubble.el, text.slice(bubble.settledChars, boundary));
            bubble.settledChars = boundary + 2;
            bubble.settledNodes = bubble.el.childNodes.length;
        }
        truncateChildren(bubble.el, bubble.settledNodes);
        window.CommerceAgentsMarkdown.append(bubble.el, text.slice(bubble.settledChars));
        scrollDown();
    }

    function truncateChildren(el, count) {
        while (el.childNodes.length > count) {
            el.removeChild(el.lastChild);
        }
    }

    function closeStreamingBubble() {
        if (streamingRenderFrame !== 0) {
            window.cancelAnimationFrame(streamingRenderFrame);
            streamingRenderFrame = 0;
        }
        if (streamingBubble !== null) {
            // Final full render: the same DOM as a restore from sessionStorage.
            window.CommerceAgentsMarkdown.render(streamingBubble.el, streamingBubble.message.text);
            scrollDown();
        }
        streamingBubble = null;
    }

    function handleToolResult(payload) {
        closeStreamingBubble();
        pushMessage({ kind: 'tool', text: '⚙ ' + payload.name });
        var result = payload.result || {};

        var cards = window.CommerceAgentsProductCards.fromToolResult(payload.name, result, i18n);
        if (cards) {
            pushMessage({ kind: 'products', items: cards });
        }

        if (payload.name === 'open_admin_page' && result.navigation && result.navigation.url) {
            pendingNavigationUrl = result.navigation.url;
        }
    }

    function navigateIfRequested() {
        var url = pendingNavigationUrl;
        pendingNavigationUrl = null;
        if (!url) {
            return;
        }
        var resolved;
        try {
            resolved = new URL(url, window.location.origin);
        } catch (error) {
            return;
        }
        // Defense in depth: server already refused anything outside /admin of this store.
        if (resolved.origin !== window.location.origin
            || !(resolved.pathname === '/admin' || resolved.pathname.indexOf('/admin/') === 0)) {
            return;
        }
        persist();
        setTimeout(function () {
            window.location.assign(resolved.href);
        }, 700);
    }

    function handleFrame(frame) {
        var event = '';
        var data = '';
        frame.split('\n').forEach(function (line) {
            if (line.indexOf('event: ') === 0) {
                event = line.slice(7);
            } else if (line.indexOf('data: ') === 0) {
                data += line.slice(6);
            }
        });
        if (event === '') {
            return;
        }
        var payload = {};
        try {
            payload = data === '' ? {} : JSON.parse(data);
        } catch (error) {
            return;
        }

        if (event === 'text_delta') {
            appendAssistantText(payload.text || '');
        } else if (event === 'tool_result') {
            handleToolResult(payload);
        } else if (event === 'error') {
            closeStreamingBubble();
            pushMessage({ kind: 'error', text: payload.message || i18n.error });
        } else if (event === 'done') {
            closeStreamingBubble();
            persist();
            navigateIfRequested();
        }
    }

    async function streamMessage(text) {
        sendButton.disabled = true;
        input.disabled = true;
        closeStreamingBubble();

        try {
            var response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text })
            });

            if (!response.ok) {
                var errorPayload = await response.json().catch(function () { return {}; });
                pushMessage({ kind: 'error', text: errorPayload.error || i18n.serviceUnavailable });
                return;
            }

            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';
            while (true) {
                var chunk = await reader.read();
                if (chunk.done) {
                    break;
                }
                buffer += decoder.decode(chunk.value, { stream: true });
                var separatorIndex;
                while ((separatorIndex = buffer.indexOf('\n\n')) !== -1) {
                    handleFrame(buffer.slice(0, separatorIndex));
                    buffer = buffer.slice(separatorIndex + 2);
                }
            }
        } catch (error) {
            pushMessage({ kind: 'error', text: i18n.connectionLost });
        } finally {
            closeStreamingBubble();
            sendButton.disabled = false;
            input.disabled = false;
            persist();
            input.focus();
        }
    }

    function submitText(text) {
        text = text.trim();
        if (text === '' || sendButton.disabled) {
            return;
        }
        pushMessage({ kind: 'user', text: text });
        streamMessage(text);
    }

    // --- boot ---
    restore();
    if (state.messages.length > 0) {
        hideSuggestions();
        state.messages.forEach(renderMessage);
    }
    root.hidden = false;
    setOpen(state.open);

    toggle.addEventListener('click', function () {
        setOpen(!state.open);
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var text = input.value;
        input.value = '';
        submitText(text);
    });

    messagesContainer.addEventListener('click', function (event) {
        var chip = event.target.closest('.cabw-chip');
        if (chip) {
            submitText(chip.textContent);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', commerceAgentsBootBoWidget);
} else {
    commerceAgentsBootBoWidget();
}
