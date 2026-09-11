'use strict';

/**
 * Merchant Agent chat page. Streams the admin SSE endpoint.
 * LLM text is only ever rendered through textContent; tool results are
 * displayed as collapsible JSON blocks built from structured data.
 */
(function () {
    const root = document.getElementById('merchant-chat');
    if (!root) {
        return;
    }

    const endpoint = root.dataset.endpoint;
    const i18n = {
        connectionLost: root.dataset.i18nConnectionLost || 'Connection lost',
        serviceUnavailable: root.dataset.i18nServiceUnavailable || 'Service unavailable',
        error: root.dataset.i18nError || 'Something went wrong',
        hidden: root.dataset.i18nHidden || 'Hidden',
        stock: root.dataset.i18nStock || 'Stock',
    };
    const messagesContainer = document.getElementById('mc-messages');
    const form = document.getElementById('mc-form');
    const input = document.getElementById('mc-input');
    const sendButton = document.getElementById('mc-send');

    let streamingBubble = null;
    let streamingRenderFrame = 0;

    function scrollDown() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function addBubble(className, text) {
        const bubble = document.createElement('p');
        bubble.className = 'mc-bubble ' + className;
        bubble.textContent = text;
        messagesContainer.appendChild(bubble);
        scrollDown();
        return bubble;
    }

    function appendAssistantText(text) {
        if (streamingBubble === null) {
            const el = document.createElement('div');
            el.className = 'mc-bubble mc-assistant';
            messagesContainer.appendChild(el);
            streamingBubble = { el: el, rawMarkdown: '', settledChars: 0, settledNodes: 0 };
        }
        streamingBubble.rawMarkdown += text;
        scheduleStreamingRender();
    }

    // Deltas arrive faster than the bubble can be rebuilt and laid out:
    // paint at most once per animation frame, from the accumulated text.
    function scheduleStreamingRender() {
        if (streamingRenderFrame !== 0) {
            return;
        }
        streamingRenderFrame = window.requestAnimationFrame(() => {
            streamingRenderFrame = 0;
            renderStreamingBubble();
        });
    }

    function renderStreamingBubble() {
        if (streamingBubble === null) {
            return;
        }
        const bubble = streamingBubble;
        const text = bubble.rawMarkdown;
        // Blocks before the last blank line are final: render them once, then
        // only the tail block is rebuilt on the following frames.
        const boundary = text.lastIndexOf('\n\n');
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
            // Final full render, identical to a one-shot render of the reply.
            window.CommerceAgentsMarkdown.render(streamingBubble.el, streamingBubble.rawMarkdown);
            scrollDown();
        }
        streamingBubble = null;
    }

    function addToolBlock(payload) {
        closeStreamingBubble();

        const cards = window.CommerceAgentsProductCards.fromToolResult(payload.name, payload.result || {}, i18n);
        if (cards) {
            window.CommerceAgentsProductCards.render(messagesContainer, cards);
        }

        const details = document.createElement('details');
        details.className = 'mc-tool';

        const summary = document.createElement('summary');
        summary.textContent = payload.name;
        details.appendChild(summary);

        const pre = document.createElement('pre');
        pre.textContent = JSON.stringify(payload.result, null, 2);
        details.appendChild(pre);

        messagesContainer.appendChild(details);
        scrollDown();
    }

    function handleFrame(frame) {
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
            appendAssistantText(payload.text || '');
        } else if (event === 'tool_result') {
            addToolBlock(payload);
        } else if (event === 'error') {
            closeStreamingBubble();
            addBubble('mc-error', payload.message || i18n.error);
        }
    }

    async function streamMessage(text) {
        sendButton.disabled = true;
        input.disabled = true;
        closeStreamingBubble();

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text }),
            });

            if (!response.ok) {
                const payload = await response.json().catch(() => ({}));
                addBubble('mc-error', payload.error || i18n.serviceUnavailable);
                return;
            }

            const reader = response.body.getReader();
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
                    handleFrame(buffer.slice(0, separatorIndex));
                    buffer = buffer.slice(separatorIndex + 2);
                }
            }
        } catch (error) {
            addBubble('mc-error', i18n.connectionLost);
        } finally {
            closeStreamingBubble();
            sendButton.disabled = false;
            input.disabled = false;
            input.focus();
        }
    }

    function submitText(text) {
        if (text === '' || sendButton.disabled) {
            return;
        }
        const suggestions = document.getElementById('mc-suggestions');
        if (suggestions) {
            suggestions.remove();
        }
        addBubble('mc-user', text);
        streamMessage(text);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const text = input.value.trim();
        input.value = '';
        submitText(text);
    });

    messagesContainer.addEventListener('click', function (event) {
        const chip = event.target.closest('.mc-suggestion');
        if (chip) {
            submitText(chip.textContent.trim());
        }
    });
})();
