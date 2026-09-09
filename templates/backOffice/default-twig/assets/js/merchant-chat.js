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
    };
    const messagesContainer = document.getElementById('mc-messages');
    const form = document.getElementById('mc-form');
    const input = document.getElementById('mc-input');
    const sendButton = document.getElementById('mc-send');

    let streamingBubble = null;

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
            streamingBubble = document.createElement('div');
            streamingBubble.className = 'mc-bubble mc-assistant';
            streamingBubble.rawMarkdown = '';
            messagesContainer.appendChild(streamingBubble);
        }
        streamingBubble.rawMarkdown += text;
        window.CommerceAgentsMarkdown.render(streamingBubble, streamingBubble.rawMarkdown);
        scrollDown();
    }

    function addToolBlock(payload) {
        streamingBubble = null;
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
            streamingBubble = null;
            addBubble('mc-error', payload.message || i18n.error);
        }
    }

    async function streamMessage(text) {
        sendButton.disabled = true;
        input.disabled = true;
        streamingBubble = null;

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
            streamingBubble = null;
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
