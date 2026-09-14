'use strict';

// Node built-in test runner (node --test local/modules/CommerceAgents/Tests/js).
// Exercises the front chat widget component outside Alpine: the factory returns
// a plain object, so every pure helper and the tool-result mapper are callable
// against a minimal document stub.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const SOURCE = path.join(__dirname, '../../templates/frontOffice/default/assets/js/chat-widget.js');

function loadFactory(config, storage) {
    global.document = {
        body: { classList: { toggle() {}, add() {}, remove() {} } },
        getElementById: (id) => (id === 'commerce-agents-widget'
            ? { dataset: { config: config === undefined ? undefined : JSON.stringify(config) } }
            : null),
    };
    global.sessionStorage = storage || {
        store: {},
        getItem(key) { return this.store[key] || null; },
        setItem(key, value) { this.store[key] = value; },
        removeItem(key) { delete this.store[key]; },
    };
    const fakeWindow = {};
    new Function('window', fs.readFileSync(SOURCE, 'utf8'))(fakeWindow);

    return fakeWindow.commerceAgentsChat;
}

function component(config, saved) {
    const widget = loadFactory(config)();
    widget.$nextTick = () => {};
    widget.$watch = () => {};
    if (saved !== undefined) {
        global.sessionStorage.setItem('commerceagents.chat', JSON.stringify(saved));
    }
    widget.readConfig();
    widget.restore();

    return widget;
}

test('the cart snapshot and the locale come from the server payload', () => {
    const widget = component({
        locale: 'fr_FR',
        cart: { items: [{ productId: 3, title: 'Stacy', quantity: 1, totalTaxedPrice: 732, imageUrl: '/x.png' }], totalTaxedAmount: 732, currency: 'EUR', itemCount: 1 },
        i18n: { inStock: 'En stock' },
    });

    assert.equal(widget.locale, 'fr-FR');
    assert.equal(widget.cart.itemCount, 1);
    assert.equal(widget.cart.items[0].title, 'Stacy');
    assert.equal(widget.i18n.inStock, 'En stock');
    // Keys absent from the payload keep their built-in fallback.
    assert.equal(widget.i18n.outOfStock, 'Out of stock');
});

test('a broken config leaves the component usable', () => {
    global.document = { getElementById: () => ({ dataset: { config: '{not json' } }) };
    const fakeWindow = {};
    new Function('window', fs.readFileSync(SOURCE, 'utf8'))(fakeWindow);
    const widget = fakeWindow.commerceAgentsChat();
    widget.readConfig();

    assert.equal(widget.cart.itemCount, 0);
    assert.equal(widget.locale, 'en-US');
});

test('prices are formatted in the session currency', () => {
    const widget = component({ locale: 'fr_FR', cart: { items: [], totalTaxedAmount: 0, currency: 'EUR', itemCount: 0 } });

    assert.match(widget.money(732, 'EUR'), /732/);
    assert.match(widget.money(732, 'EUR'), /€/);
    assert.equal(widget.money(null, 'EUR'), '');
    assert.equal(widget.money('nope', 'EUR'), '');
    // An unknown currency code must not blow up the whole message list.
    assert.equal(widget.money(12.5, 'NOT_A_CODE'), '12.50 NOT_A_CODE');
});

test('the promo price wins only when it is lower', () => {
    const widget = component({});

    assert.equal(widget.hasPromo({ price: 100, promoPrice: 80 }), true);
    assert.equal(widget.bestPrice({ price: 100, promoPrice: 80 }), 80);
    assert.equal(widget.hasPromo({ price: 100, promoPrice: 0 }), false);
    assert.equal(widget.bestPrice({ price: 100, promoPrice: 0 }), 100);
    assert.equal(widget.hasPromo({ price: 100, promoPrice: null }), false);
    assert.equal(widget.bestPrice({ price: 100 }), 100);
});

test('a product search becomes a product block and feeds the sidebar', () => {
    const widget = component({});
    const products = [1, 2, 3, 4, 5].map((id) => ({ id: id, title: 'P' + id, price: 10 }));

    widget.pushToolBlock({ name: 'search_products', result: { products: products } });

    assert.equal(widget.messages.length, 1);
    assert.equal(widget.messages[0].kind, 'products');
    assert.equal(widget.messages[0].data.length, 5);
    assert.equal(widget.highlights.length, 3);
    assert.equal(widget.highlights[0].id, 1);
});

test('an empty product search adds no block', () => {
    const widget = component({});

    widget.pushToolBlock({ name: 'search_products', result: { products: [] } });

    assert.equal(widget.messages.length, 0);
    assert.equal(widget.highlights.length, 0);
});

test('a cart tool result refreshes the cart preview', () => {
    const widget = component({ cart: { items: [], totalTaxedAmount: 0, currency: 'EUR', itemCount: 0 } });

    widget.pushToolBlock({
        name: 'add_to_cart',
        result: { cart: { items: [{ productId: 3, title: 'Stacy', quantity: 2, totalTaxedPrice: 1464 }], totalTaxedAmount: 1464, currency: 'EUR', itemCount: 2 } },
    });

    assert.equal(widget.messages[0].kind, 'cart');
    assert.equal(widget.cart.itemCount, 2);
    assert.equal(widget.cart.items[0].quantity, 2);
    assert.equal(widget.cart.totalTaxedAmount, 1464);
});

test('a navigation tool result never leaves the store origin', () => {
    const widget = component({});

    widget.pushToolBlock({ name: 'open_page', result: { navigation: { url: '/cart' } } });

    assert.equal(widget.pendingNavigationUrl, '/cart');
    assert.equal(widget.messages.length, 0);
});

test('a fresh visitor gets the bottom bar only', () => {
    const widget = component({});

    assert.equal(widget.state, 'dock');
    assert.equal(widget.isOpen, false);
    assert.equal(widget.isCollapsed, false);
});

test('a page load never restores the full-screen panel', () => {
    const widget = component({}, {
        state: 'open',
        messages: [{ kind: 'text', role: 'assistant', text: 'Here are three chairs.' }],
        highlights: [],
    });

    assert.equal(widget.state, 'collapsed');
    assert.equal(widget.isOpen, false);
    assert.equal(widget.isCollapsed, true);
});

test('a conversation minimised before navigation comes back collapsed', () => {
    const widget = component({}, {
        state: 'dock',
        messages: [{ kind: 'text', role: 'assistant', text: 'Hello.' }],
        highlights: [],
    });

    assert.equal(widget.state, 'collapsed');
});

test('closing the panel keeps the conversation within reach', () => {
    const widget = component({});
    widget.messages = [{ kind: 'text', role: 'assistant', text: 'Hello.' }];
    widget.state = 'open';

    widget.close();

    assert.equal(widget.state, 'collapsed');
});

test('closing an empty panel goes back to the bare bar', () => {
    const widget = component({});
    widget.state = 'open';

    widget.close();

    assert.equal(widget.state, 'dock');
});

test('minimising and clearing both fall back to the bare bar', () => {
    const widget = component({});
    widget.messages = [{ kind: 'text', role: 'assistant', text: 'Hello.' }];

    widget.state = 'collapsed';
    widget.minimise();
    assert.equal(widget.state, 'dock');

    widget.state = 'collapsed';
    widget.reset();
    assert.equal(widget.state, 'dock');
    assert.equal(widget.messages.length, 0);
});

test('the bottom bar opens the panel only for a first question', () => {
    const widget = component({});

    widget.focusDock();
    assert.equal(widget.state, 'open');

    widget.state = 'dock';
    widget.messages = [{ kind: 'text', role: 'assistant', text: 'Hello.' }];
    widget.focusDock();
    assert.equal(widget.state, 'dock');
});

test('a first question opens the panel, a follow-up stays on the page', () => {
    global.fetch = () => Promise.reject(new Error('offline'));

    const first = component({});
    first.input = 'chairs';
    first.send();
    assert.equal(first.state, 'open');

    const later = component({});
    later.messages = [{ kind: 'text', role: 'assistant', text: 'Hello.' }];
    later.state = 'dock';
    later.input = 'and sofas?';
    later.send();
    assert.equal(later.state, 'collapsed');
});

test('the collapsed card shows the last thing the assistant said', () => {
    const widget = component({});
    widget.messages = [
        { kind: 'text', role: 'assistant', text: 'First reply.' },
        { kind: 'text', role: 'user', text: 'and sofas?' },
        { kind: 'text', role: 'assistant', text: 'Second reply.' },
        { kind: 'products', role: 'assistant', data: [{ id: 1, title: 'Stacy' }] },
    ];

    assert.equal(widget.lastReply, 'Second reply.');
});

test('the collapsed card surfaces an error rather than a stale reply', () => {
    const widget = component({});
    widget.messages = [
        { kind: 'text', role: 'assistant', text: 'First reply.' },
        { kind: 'error', role: 'assistant', text: 'Connection lost' },
    ];

    assert.equal(widget.lastReply, 'Connection lost');
});

test('an empty conversation has nothing to summarise', () => {
    assert.equal(component({}).lastReply, '');
});
