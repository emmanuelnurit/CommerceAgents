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

function loadFactory(config, storage, windowExtras) {
    global.document = {
        body: { classList: { toggle() {}, add() {}, remove() {} } },
        getElementById: (id) => (id === 'commerce-agents-widget'
            ? { dataset: { config: config === undefined ? undefined : JSON.stringify(config) } }
            : null),
        querySelector: () => null,
    };
    global.sessionStorage = storage || {
        store: {},
        getItem(key) { return this.store[key] || null; },
        setItem(key, value) { this.store[key] = value; },
        removeItem(key) { delete this.store[key]; },
    };
    // MYO-236 Lot 3 instrumentation reads window.location / window.CommerceAgentsPageContext:
    // the source is wrapped as `new Function('window', source)`, so `window` inside it is this
    // object, not Node's (nonexistent) global — tests inject what they need through windowExtras.
    const fakeWindow = Object.assign({ location: { pathname: '/', origin: 'https://shop.example' } }, windowExtras || {});
    new Function('window', fs.readFileSync(SOURCE, 'utf8'))(fakeWindow);

    return fakeWindow.commerceAgentsChat;
}

function component(config, saved, windowExtras) {
    const widget = loadFactory(config, undefined, windowExtras)();
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

test('quick replies are attached to the last assistant message only', () => {
    const widget = component({});
    widget.messages.push({ kind: 'text', role: 'assistant', text: 'Voici nos chaises' });

    widget.applySuggestions([
        { id: 'cat-9', label: 'Voir aussi les tabourets', action: { type: 'navigate', url: '/tabourets.html' } },
    ], false);

    const message = widget.messages[widget.messages.length - 1];
    assert.equal(message.suggestions.length, 1);
    assert.equal(message.suggestions[0].label, 'Voir aussi les tabourets');
    assert.equal(message.suggestionsDefault, false);
});

test('quick replies never attach to a message that is not the last assistant text turn', () => {
    const widget = component({});
    widget.messages.push({ kind: 'products', role: 'assistant', data: [] });

    widget.applySuggestions([{ id: 'x', label: 'X', action: { type: 'message', text: 'X' } }], false);

    assert.equal(widget.messages[widget.messages.length - 1].suggestions, undefined);
});

test('an empty or missing suggestions array leaves the message untouched', () => {
    const widget = component({});
    widget.messages.push({ kind: 'text', role: 'assistant', text: 'Bonjour' });

    widget.applySuggestions([], false);
    widget.applySuggestions(undefined, false);

    assert.equal(widget.messages[widget.messages.length - 1].suggestions, undefined);
});

test('the default-state flag is carried onto the message for the anti-collision CSS rule', () => {
    const widget = component({});
    widget.messages.push({ kind: 'text', role: 'assistant', text: 'Bonjour' });

    widget.applySuggestions([{ id: 'default-product', label: 'X', action: { type: 'message', text: 'X' } }], true);

    assert.equal(widget.messages[widget.messages.length - 1].suggestionsDefault, true);
});

test('clicking a message quick reply sends it like a hero chip', () => {
    const widget = component({});
    let sent = null;
    widget.sendSuggestion = (text) => { sent = text; };

    widget.sendQuickReply({ action: { type: 'message', text: 'Montrez-moi les chaises en orange' } });

    assert.equal(sent, 'Montrez-moi les chaises en orange');
});

test('clicking a navigate quick reply is a no-op in JS (rendered as a real <a href>)', () => {
    const widget = component({});
    let sent = null;
    widget.sendSuggestion = (text) => { sent = text; };

    widget.sendQuickReply({ action: { type: 'navigate', url: '/tabourets.html' } });

    assert.equal(sent, null);
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

function detailsPayload(variants) {
    return {
        name: 'get_product_details',
        result: {
            product: {
                id: 1, title: 'Horatio', url: '/horatio.html', currency: 'EUR',
                price: 267.6, promoPrice: 238.8, imageUrl: '/img/horatio.jpg',
                pses: variants,
            },
        },
    };
}

test('a product with several variants becomes a variant block', () => {
    const widget = component({});

    widget.pushToolBlock(detailsPayload([
        { id: 1, ref: 'PROD001-0', label: 'Colors: Blue', price: 267.6, promoPrice: 238.8, inStock: true, imageUrl: '/img/blue.jpg' },
        { id: 2, ref: 'PROD001-1', label: 'Colors: Pink', price: 267.6, promoPrice: null, inStock: false, imageUrl: '/img/pink.jpg' },
    ]));

    assert.equal(widget.messages.length, 1);
    assert.equal(widget.messages[0].kind, 'variants');
    assert.equal(widget.messages[0].data.length, 2);
    assert.equal(widget.messages[0].product.title, 'Horatio');
    assert.equal(widget.highlights.length, 1);
});

test('a product with a single variant stays a plain product card', () => {
    const widget = component({});

    widget.pushToolBlock(detailsPayload([{ id: 1, ref: 'PROD001-0', label: '', price: 90, promoPrice: null, inStock: true }]));

    assert.equal(widget.messages[0].kind, 'products');
    assert.equal(widget.messages[0].data[0].title, 'Horatio');
});

test('a product with no variant at all still renders', () => {
    const widget = component({});

    widget.pushToolBlock({ name: 'get_product_details', result: { product: { id: 1, title: 'Horatio' } } });

    assert.equal(widget.messages[0].kind, 'products');
});

test('adding a variant to the cart names it by reference', () => {
    const widget = component({ i18n: { addToCartPrompt: 'Ajoute ce produit à mon panier :' } });
    const sent = [];
    widget.sendSuggestion = (text) => sent.push(text);

    widget.askAddVariantToCart(
        { title: 'Horatio' },
        { id: 3, ref: 'PROD001-2', label: 'Colors: Red' },
    );

    assert.equal(sent[0], 'Ajoute ce produit à mon panier : Horatio — Colors: Red (PROD001-2)');
});

test('a variant without attributes is still unambiguous', () => {
    const widget = component({ i18n: { addToCartPrompt: 'Add to cart:' } });
    const sent = [];
    widget.sendSuggestion = (text) => sent.push(text);

    widget.askAddVariantToCart({ title: 'Tina' }, { id: 9, ref: 'PROD011-0', label: '' });

    assert.equal(sent[0], 'Add to cart: Tina (PROD011-0)');
});

test('the promo price of a variant wins over the product price', () => {
    const widget = component({});
    const variant = { price: 267.6, promoPrice: 238.8 };

    assert.equal(widget.hasPromo(variant), true);
    assert.equal(widget.bestPrice(variant), 238.8);
});

test('an option search shows the matching variants, not whole products', () => {
    const widget = component({ i18n: { optionResults: 'Déclinaisons correspondant à' } });

    widget.pushToolBlock({
        name: 'search_products',
        result: {
            count: 2,
            matched_option: { id: 3, title: 'Orange', attribute: 'Couleur', variantCount: 11 },
            variants: [
                { id: 12, ref: 'PROD003-2', productId: 3, productTitle: 'Stacy', url: '/stacy.html', label: 'Couleur: Orange', price: 783.6, promoPrice: 732, currency: 'EUR', inStock: true, imageUrl: '/img/stacy.jpg' },
                { id: 40, ref: 'PROD011-1', productId: 11, productTitle: 'Tina', url: '/tina.html', label: 'Couleur: Orange', price: 90, promoPrice: null, currency: 'EUR', inStock: true, imageUrl: '/img/tina.jpg' },
            ],
        },
    });

    assert.equal(widget.messages.length, 1);
    assert.equal(widget.messages[0].kind, 'variants');
    assert.equal(widget.messages[0].product, null);
    assert.equal(widget.messages[0].option.title, 'Orange');
    assert.equal(widget.messages[0].data.length, 2);
    assert.equal(widget.variantsHeading(widget.messages[0]), 'Déclinaisons correspondant à Orange');
});

test('an option search feeds the sidebar with the product behind each variant', () => {
    const widget = component({});

    widget.pushToolBlock({
        name: 'search_products',
        result: {
            matched_option: { id: 3, title: 'Orange' },
            variants: [
                { id: 12, ref: 'PROD003-2', productTitle: 'Stacy', url: '/stacy.html', imageUrl: '/img/stacy.jpg', price: 783.6, promoPrice: 732, currency: 'EUR' },
            ],
        },
    });

    assert.equal(widget.highlights[0].title, 'Stacy');
    assert.equal(widget.highlights[0].url, '/stacy.html');
    assert.equal(widget.bestPrice(widget.highlights[0]), 732);
});

test('an option search with nothing matching leaves the thread alone', () => {
    const widget = component({});

    widget.pushToolBlock({ name: 'search_products', result: { count: 0, variants: [], matched_option: null, unknown_option: 'fluo' } });

    assert.equal(widget.messages.length, 0);
});

test('a variant card falls back to the product of the details block', () => {
    const widget = component({ i18n: { variantsTitle: 'Options disponibles' } });
    const message = { kind: 'variants', product: { title: 'Horatio', url: '/horatio.html', currency: 'EUR' }, option: null, data: [] };

    assert.equal(widget.variantsHeading(message), 'Horatio — Options disponibles');
    assert.equal(widget.variantUrl(message, { url: null }), '/horatio.html');
    assert.equal(widget.variantCurrency(message, { currency: null }), 'EUR');
});

test('adding an option-search variant to the cart names its own product', () => {
    const widget = component({ i18n: { addToCartPrompt: 'Ajoute :' } });
    const sent = [];
    widget.sendSuggestion = (text) => sent.push(text);

    widget.askAddVariantToCart(null, { ref: 'PROD003-2', productTitle: 'Stacy', label: 'Couleur: Orange' });

    assert.equal(sent[0], 'Ajoute : Stacy — Couleur: Orange (PROD003-2)');
});

function threadWithCards(text) {
    const widget = component({});
    widget.messages = [
        { kind: 'text', role: 'user', text: 'des produits orange' },
        { kind: 'variants', role: 'assistant', product: null, option: { title: 'Orange' }, data: [
            { id: 1, ref: 'PROD002-1', productTitle: 'Travis' },
            { id: 2, ref: 'PROD011-1', productTitle: 'Tina' },
        ] },
        { kind: 'text', role: 'assistant', text: text },
    ];

    return widget;
}

test('bullets that repeat the cards are dropped, the closing sentence stays', () => {
    const widget = threadWithCards([
        'Voici les produits disponibles en orange :',
        '',
        '- **Travis** : un tabouret à 22,80 € en promo.',
        '- **Tina** : une chaise légère à 90,00 €.',
        '',
        'Dites-moi la pièce visée et je vous propose des fauteuils assortis.',
    ].join('\n'));

    const shown = widget.displayText(widget.messages[2], 2);

    assert.equal(shown.includes('Travis'), false);
    assert.equal(shown.includes('Tina'), false);
    assert.ok(shown.includes('Voici les produits disponibles en orange.'));
    assert.ok(shown.includes('je vous propose des fauteuils assortis'));
});

test('a wrapped repeat is dropped whole, not left half there', () => {
    const widget = threadWithCards([
        '- **Travis** : un tabouret',
        '  empilable et léger.',
        '- Autre chose à voir.',
    ].join('\n'));

    const shown = widget.displayText(widget.messages[2], 2);

    assert.equal(shown.includes('empilable'), false);
    assert.ok(shown.includes('Autre chose à voir.'));
});

test('a list that names nothing shown is left alone', () => {
    const widget = threadWithCards('- Livraison offerte dès 50 €\n- Retours sous 14 jours');

    assert.equal(widget.displayText(widget.messages[2], 2), '- Livraison offerte dès 50 €\n- Retours sous 14 jours');
});

test('a reply made only of repeats is kept rather than blanked', () => {
    const widget = threadWithCards('- **Travis** : 22,80 €\n- **Tina** : 90,00 €');

    assert.ok(widget.displayText(widget.messages[2], 2).includes('Travis'));
});

test('text that follows no card block is untouched', () => {
    const widget = component({});
    widget.messages = [{ kind: 'text', role: 'assistant', text: '- **Travis** : un tabouret.' }];

    assert.equal(widget.displayText(widget.messages[0], 0), '- **Travis** : un tabouret.');
});

test('a product block also feeds the repeat filter', () => {
    const widget = component({});
    widget.messages = [
        { kind: 'products', role: 'assistant', category: null, data: [{ id: 3, title: 'Stacy' }] },
        { kind: 'text', role: 'assistant', text: '- **Stacy** : 732 €\n\nBelle pièce.' },
    ];

    const shown = widget.displayText(widget.messages[1], 1);

    assert.equal(shown.includes('732'), false);
    assert.ok(shown.includes('Belle pièce.'));
});

function threadWithHistory(replyText) {
    const widget = component({});
    widget.messages = [
        { kind: 'text', role: 'user', text: 'des produits orange' },
        { kind: 'variants', role: 'assistant', product: null, option: { title: 'Orange' }, data: [
            { id: 8, ref: 'PROD002-1', productTitle: 'Travis', label: 'Couleur: Orange', url: '/travis.html', imageUrl: '/img/travis-orange.jpg', price: 30, promoPrice: 22.8, currency: 'EUR', inStock: true },
            { id: 45, ref: 'PROD011-1', productTitle: 'Tina', label: 'Couleur: Orange', url: '/tina.html', imageUrl: '/img/tina-orange.jpg', price: 90, promoPrice: null, currency: 'EUR', inStock: true },
        ] },
        { kind: 'text', role: 'assistant', text: 'Première réponse.' },
        { kind: 'text', role: 'user', text: 'et le moins cher ?' },
        { kind: 'text', role: 'assistant', text: replyText },
    ];

    return widget;
}

test('a product named from memory gets its card back', () => {
    const widget = threadWithHistory('Le moins cher est le **Travis** à 22,80 €, une bonne affaire.');

    const cards = widget.cardsFor(widget.messages[4], 4);

    assert.equal(cards.length, 1);
    assert.equal(cards[0].productTitle, 'Travis');
    assert.equal(cards[0].imageUrl, '/img/travis-orange.jpg');
    assert.equal(cards[0].label, 'Couleur: Orange');
    assert.equal(cards[0].isVariant, true);
});

test('a reply naming two known products brings both cards', () => {
    const widget = threadWithHistory('Entre Travis et Tina, je prendrais le premier.');

    assert.deepEqual(widget.cardsFor(widget.messages[4], 4).map((c) => c.productTitle), ['Travis', 'Tina']);
});

test('no card is repeated right under the block that already shows it', () => {
    const widget = threadWithHistory('peu importe');
    widget.messages[2].text = 'Voici Travis et Tina.';

    // messages[2] sits straight after the variants block.
    assert.deepEqual(widget.cardsFor(widget.messages[2], 2), []);
});

test('a reply naming no product brings no card', () => {
    const widget = threadWithHistory('La livraison est offerte dès 50 €.');

    assert.deepEqual(widget.cardsFor(widget.messages[4], 4), []);
});

test('a user turn never carries cards', () => {
    const widget = threadWithHistory('peu importe');

    assert.deepEqual(widget.cardsFor(widget.messages[3], 3), []);
});

test('the bullets are stripped against the cards brought back', () => {
    const widget = threadWithHistory('Les moins chers :\n\n- **Travis** : 22,80 €\n- **Tina** : 90 €\n\nJe prendrais Travis.');

    const shown = widget.displayText(widget.messages[4], 4);

    assert.equal(shown.includes('22,80'), false);
    assert.ok(shown.includes('Je prendrais Travis.'));
});

test('a recalled product card adds the right thing to the cart', () => {
    const widget = threadWithHistory('Le **Travis** est le moins cher.');
    const sent = [];
    widget.sendSuggestion = (text) => sent.push(text);
    widget.i18n.addToCartPrompt = 'Ajoute :';

    widget.addCardToCart(widget.cardsFor(widget.messages[4], 4)[0]);

    assert.equal(sent[0], 'Ajoute : Travis — Couleur: Orange (PROD002-1)');
});

test('a plain product card falls back to the product-level prompt', () => {
    const widget = component({ i18n: { addToCartPrompt: 'Ajoute :' } });
    const sent = [];
    widget.sendSuggestion = (text) => sent.push(text);

    widget.addCardToCart({ productTitle: 'Stacy', label: '', ref: null, isVariant: false });

    assert.equal(sent[0], 'Ajoute : Stacy');
});

test('a feature search is captioned by its material, not by a category', () => {
    const widget = component({ i18n: { inCategory: 'Dans la catégorie' } });

    widget.pushToolBlock({
        name: 'search_products',
        result: {
            count: 1,
            products: [{ id: 3, title: 'Stacy' }],
            matched_category: null,
            matched_feature: { id: 1, title: 'Tissu', feature: 'Matière', productCount: 17 },
        },
    });

    assert.equal(widget.productsCaption(widget.messages[0]), 'Matière: Tissu');
});

test('a category fallback keeps its own caption', () => {
    const widget = component({ i18n: { inCategory: 'Dans la catégorie' } });

    widget.pushToolBlock({
        name: 'search_products',
        result: { products: [{ id: 3, title: 'Stacy' }], matched_category: { id: 3, title: 'Chaises' }, matched_feature: null },
    });

    assert.equal(widget.productsCaption(widget.messages[0]), 'Dans la catégorie Chaises');
});

test('a plain search has no caption', () => {
    const widget = component({});

    widget.pushToolBlock({ name: 'search_products', result: { products: [{ id: 3, title: 'Stacy' }] } });

    assert.equal(widget.productsCaption(widget.messages[0]), '');
});

function threadWithWilson(replyText) {
    const widget = component({});
    widget.messages = [
        { kind: 'text', role: 'user', text: 'que mettre avec la Sally orange ?' },
        { kind: 'products', role: 'assistant', category: null, feature: null, data: [
            { id: 8, title: 'Wilson', url: '/wilson.html', imageUrl: '/img/wilson.jpg', price: 586.8, promoPrice: null, currency: 'EUR', inStock: true },
        ] },
        { kind: 'text', role: 'assistant', text: replyText },
    ];

    return widget;
}

test('a product paragraph with its price is dropped, the advice stays', () => {
    const widget = threadWithWilson([
        'La chaise Sally en orange a une touche lumineuse.',
        '',
        '1. Fauteuils en noir (pour un contraste chic)',
        '',
        'Wilson (586,80 €) Un fauteuil en cuir noir ultra-confortable, qui équilibre la vibrance de la Sally.',
        '',
        'Voir Wilson en noir',
        '',
        'Souhaitez-vous que je regarde aussi les tables basses ?',
    ].join('\n'));

    const shown = widget.displayText(widget.messages[2], 2);

    assert.equal(shown.includes('586,80'), false);
    assert.equal(shown.includes('Voir Wilson'), false);
    assert.ok(shown.includes('La chaise Sally en orange a une touche lumineuse.'));
    assert.ok(shown.includes('1. Fauteuils en noir'));
    assert.ok(shown.includes('les tables basses'));
});

test('a markdown link to a displayed product is dropped', () => {
    const widget = threadWithWilson('Un mot sur le style.\n\n[Voir Wilson en noir](/wilson.html)\n\nDites-moi.');

    const shown = widget.displayText(widget.messages[2], 2);

    assert.equal(shown.includes('/wilson.html'), false);
    assert.ok(shown.includes('Dites-moi.'));
});

test('an opinion naming a product is not mistaken for a listing', () => {
    const widget = threadWithWilson('Entre les deux, je prendrais Wilson.');

    assert.equal(widget.displayText(widget.messages[2], 2), 'Entre les deux, je prendrais Wilson.');
});

test('a paragraph naming a product without a price survives', () => {
    const widget = threadWithWilson('Le Wilson ira bien avec un tapis clair.');

    assert.equal(widget.displayText(widget.messages[2], 2), 'Le Wilson ira bien avec un tapis clair.');
});

test('a price paragraph about something else is left alone', () => {
    const widget = threadWithWilson('La livraison coûte 9,90 € au-delà de 50 km.');

    assert.equal(widget.displayText(widget.messages[2], 2), 'La livraison coûte 9,90 € au-delà de 50 km.');
});

test('a section heading that names no product keeps its numbering', () => {
    const widget = threadWithWilson('1. Fauteuils en noir\n2. Tables basses\n\nWilson (586,80 €) Confortable.');

    const shown = widget.displayText(widget.messages[2], 2);

    assert.ok(shown.includes('1. Fauteuils en noir'));
    assert.ok(shown.includes('2. Tables basses'));
    assert.equal(shown.includes('586,80'), false);
});

// ---------- MYO-236 Lot 3: proactive signal instrumentation ----------

function stubFetch(responsePayload, responseOptions) {
    const calls = [];
    global.fetch = (url, options) => {
        calls.push({ url, options });
        return Promise.resolve({
            ok: (responseOptions && responseOptions.ok) !== undefined ? responseOptions.ok : true,
            json: () => Promise.resolve(responsePayload === undefined ? {} : responsePayload),
        });
    };
    return calls;
}

test('signals read back what was written, and default when nothing was stored', () => {
    const widget = component({ locale: 'fr_FR' });

    assert.deepEqual(widget.readSignals(), {
        cartOpens: 0, hesitationSent: false, cartAbandonedSent: false, firstVisitSent: false, dismissed: false, lastCartActivityAt: null,
    });

    widget.writeSignals(Object.assign(widget.readSignals(), { cartOpens: 2 }));

    assert.equal(widget.readSignals().cartOpens, 2);
});

test('the checkout URL comes from the server payload, for cart-open detection', () => {
    const widget = component({ locale: 'fr_FR', checkoutUrl: '/panier' });

    assert.equal(widget.checkoutUrl, '/panier');
});

test('triggerHesitation sends the hesitation signal once, with the real product id', async () => {
    const calls = stubFetch();
    const widget = component({ locale: 'fr_FR' });

    await widget.triggerHesitation(42);

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/agent/chat/proactive-check');
    assert.deepEqual(JSON.parse(calls[0].options.body), { signal_type: 'hesitation', context: { product_id: 42 } });
    assert.equal(widget.readSignals().hesitationSent, true);

    await widget.triggerHesitation(42);
    assert.equal(calls.length, 1, 'a second trigger this session must not call the server again');
});

test('the server response ({scenario, text, card}, not {message}) is what actually shows as a suggestion', async () => {
    stubFetch({ scenario: 'hesitation', text: 'Il reste 5 en stock.', card: null });
    const widget = component({ locale: 'fr_FR' });

    await widget.triggerHesitation(42);

    assert.deepEqual(widget.proactive, { scenario: 'hesitation', text: 'Il reste 5 en stock.', card: null });
});

test('triggerFirstVisit sends the first_visit signal once per session (MYO-470, F1 welcome coupon)', async () => {
    const calls = stubFetch();
    const widget = component({ locale: 'fr_FR' });

    await widget.triggerFirstVisit();

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/agent/chat/proactive-check');
    assert.deepEqual(JSON.parse(calls[0].options.body), { signal_type: 'first_visit', context: {} });
    assert.equal(widget.readSignals().firstVisitSent, true);

    await widget.triggerFirstVisit();
    assert.equal(calls.length, 1, 'a later call within the same session must not call the server again');
});

test('a chat-driven add_to_cart also emits the add_to_cart proactive signal (MYO-470, F2/F3)', () => {
    const calls = stubFetch();
    const widget = component({ cart: { items: [], totalTaxedAmount: 0, currency: 'EUR', itemCount: 0 } });

    widget.pushToolBlock({
        name: 'add_to_cart',
        result: { cart: { items: [{ productId: 3, title: 'Stacy', quantity: 1, totalTaxedPrice: 732 }], totalTaxedAmount: 732, currency: 'EUR', itemCount: 1 } },
    });

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/agent/chat/proactive-check');
    assert.deepEqual(JSON.parse(calls[0].options.body), { signal_type: 'add_to_cart', context: {} });
});

test('triggerHesitation without a product id sends an empty context, never a made-up one', async () => {
    const calls = stubFetch();
    const widget = component({ locale: 'fr_FR' });

    await widget.triggerHesitation(null);

    assert.deepEqual(JSON.parse(calls[0].options.body).context, {});
});

test('armHesitationTimerForProductPage only arms on a real product page context', () => {
    const scheduled = [];
    const originalSetTimeout = global.setTimeout;
    global.setTimeout = (fn, ms) => { scheduled.push({ fn, ms }); return 1; };

    try {
        const noContext = component({ locale: 'fr_FR' });
        noContext.armHesitationTimerForProductPage();
        assert.equal(scheduled.length, 0);

        const onProduct = component({ locale: 'fr_FR' }, undefined, { CommerceAgentsPageContext: { type: 'product', productId: 42 } });
        onProduct.armHesitationTimerForProductPage();
        assert.equal(scheduled.length, 1);
    } finally {
        global.setTimeout = originalSetTimeout;
    }
});

test('cart-open counting only reacts to the real checkout path, and fires hesitation at the threshold', async () => {
    const calls = stubFetch();
    const widget = component(
        { locale: 'fr_FR', checkoutUrl: '/checkout/cart' },
        undefined,
        { location: { pathname: '/checkout/cart', origin: 'https://shop.example' } },
    );

    widget.trackCartOpen();
    assert.equal(widget.readSignals().cartOpens, 1);
    assert.equal(calls.length, 0, 'one visit is not hesitation yet');

    widget.trackCartOpen();
    await Promise.resolve().then(() => Promise.resolve());
    assert.equal(widget.readSignals().cartOpens, 2);
    assert.equal(calls.length, 1, 'a second cart visit without checkout reads as hesitation');
    assert.deepEqual(JSON.parse(calls[0].options.body), { signal_type: 'hesitation', context: {} });
});

test('visiting a page that is not the cart page does not count as a cart open', () => {
    const widget = component(
        { locale: 'fr_FR', checkoutUrl: '/checkout/cart' },
        undefined,
        { location: { pathname: '/product/wilson', origin: 'https://shop.example' } },
    );

    widget.trackCartOpen();

    assert.equal(widget.readSignals().cartOpens, 0);
});

test('checkCartAbandoned sends the signal once while the cart is non-empty', async () => {
    const calls = stubFetch();
    const widget = component({ locale: 'fr_FR', cart: { items: [], totalTaxedAmount: 10, currency: 'EUR', itemCount: 1 } });

    await widget.checkCartAbandoned();
    assert.equal(calls.length, 1);
    assert.equal(JSON.parse(calls[0].options.body).signal_type, 'cart_abandoned_session');
    assert.equal(widget.readSignals().cartAbandonedSent, true);

    await widget.checkCartAbandoned();
    assert.equal(calls.length, 1, 'only one relaunch per cart/session');
});

test('checkCartAbandoned does nothing once the cart is empty again', async () => {
    const calls = stubFetch();
    const widget = component({ locale: 'fr_FR', cart: { items: [], totalTaxedAmount: 0, currency: 'EUR', itemCount: 0 } });

    await widget.checkCartAbandoned();

    assert.equal(calls.length, 0);
});

test('a native add-to-cart bumps the local item count so an idle check right after does not see a falsely-empty cart', () => {
    const widget = component({ locale: 'fr_FR', cart: { items: [], totalTaxedAmount: 0, currency: 'EUR', itemCount: 0 } });
    widget.recordCartActivity = () => {};
    stubFetch();
    const toast = { classList: { contains: () => false } };
    global.document.querySelector = () => toast;

    // The stub MutationObserver invokes its callback synchronously, once the
    // toast's hidden class has (supposedly) just been removed by the theme's
    // Live Component re-render.
    const originalMutationObserver = global.MutationObserver;
    global.MutationObserver = class {
        constructor(callback) { this.callback = callback; }
        observe() { toast.classList.contains = () => false; this.callback(); }
    };
    try {
        widget.observeNativeAddToCart();
    } finally {
        global.MutationObserver = originalMutationObserver;
    }

    assert.equal(widget.cart.itemCount, 1);
});

test('a native add-to-cart (real shop button) emits the add_to_cart proactive signal (MYO-470)', () => {
    const calls = stubFetch();
    const widget = component({ locale: 'fr_FR', cart: { items: [], totalTaxedAmount: 0, currency: 'EUR', itemCount: 0 } });
    widget.recordCartActivity = () => {};
    const toast = { classList: { contains: () => false } };
    global.document.querySelector = () => toast;

    const originalMutationObserver = global.MutationObserver;
    global.MutationObserver = class {
        constructor(callback) { this.callback = callback; }
        observe() { toast.classList.contains = () => false; this.callback(); }
    };
    try {
        widget.observeNativeAddToCart();
    } finally {
        global.MutationObserver = originalMutationObserver;
    }

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/agent/chat/proactive-check');
    assert.deepEqual(JSON.parse(calls[0].options.body), { signal_type: 'add_to_cart', context: {} });
});

test('dismissProactive notifies the server once and blocks further proactive signals', async () => {
    const calls = stubFetch();
    const widget = component({ locale: 'fr_FR' });
    widget.proactive = { scenario: 'hesitation', text: 'Still there?' };

    await widget.dismissProactive();

    assert.equal(widget.proactive, null);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/agent/chat/proactive-dismiss');
    assert.equal(widget.readSignals().dismissed, true);

    const sent = await widget.sendProactiveSignal('hesitation', {});
    assert.equal(calls.length, 1, 'a refused session must not call proactive-check again');
    assert.equal(sent, undefined);
});

test('a proactive message shows as a floating card when the widget is closed', () => {
    const widget = component({ locale: 'fr_FR' });

    widget.receiveProactiveMessage('hesitation', 'Il reste 3 en stock.');

    assert.deepEqual(widget.proactive, { scenario: 'hesitation', text: 'Il reste 3 en stock.', card: null });
    assert.equal(widget.messages.length, 0);
});

test('a proactive message with a card (scenario 4 relaunch, once a coupon exists) carries it through', () => {
    const widget = component({ locale: 'fr_FR' });
    const card = { type: 'coupon', data: { code: 'PANIER10' } };

    widget.receiveProactiveMessage('cart_abandoned_session', 'Votre panier vous attend.', card);

    // The coupon card gets its local widget state (applying/applied/error)
    // seeded on arrival (MYO-263), on top of whatever the server sent.
    assert.deepEqual(widget.proactive.card, {
        type: 'coupon',
        data: { code: 'PANIER10', applying: false, applied: false, appliedDiscount: null, error: null },
    });
});

test('prepareCard leaves a non-coupon card untouched', () => {
    const widget = component({ locale: 'fr_FR' });
    const card = { type: 'product', data: { id: 42 } };

    assert.deepEqual(widget.prepareCard(card), card);
    assert.equal(widget.prepareCard(null), null);
});

test('applyCoupon posts the code and marks the card applied on success', async () => {
    const calls = stubFetch({ applied: true, discount: 5 });
    const widget = component({ locale: 'fr_FR' });
    const cardData = { code: 'PANIER10', applying: false, applied: false, appliedDiscount: null, error: null };

    const pending = widget.applyCoupon(cardData);
    assert.equal(cardData.applying, true, 'the button must disable itself immediately');

    await pending;

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/agent/chat/proactive-apply-coupon');
    assert.deepEqual(JSON.parse(calls[0].options.body), { code: 'PANIER10' });
    assert.equal(cardData.applying, false);
    assert.equal(cardData.applied, true);
    assert.equal(cardData.appliedDiscount, 5);
    assert.equal(cardData.error, null);
});

test('applyCoupon surfaces the server error message and stays reusable', async () => {
    stubFetch({ applied: false, error: "Ce code n'est plus valide" }, { ok: false });
    const widget = component({ locale: 'fr_FR' });
    const cardData = { code: 'EXPIRED', applying: false, applied: false, appliedDiscount: null, error: null };

    await widget.applyCoupon(cardData);

    assert.equal(cardData.applying, false);
    assert.equal(cardData.applied, false);
    assert.equal(cardData.error, "Ce code n'est plus valide");
});

test('applyCoupon does nothing without a code, and never fires twice while pending or applied', async () => {
    const calls = stubFetch({ applied: true, discount: 1 });
    const widget = component({ locale: 'fr_FR' });

    await widget.applyCoupon({ code: null });
    assert.equal(calls.length, 0);

    const cardData = { code: 'PANIER10', applying: true, applied: false, appliedDiscount: null, error: null };
    await widget.applyCoupon(cardData);
    assert.equal(calls.length, 0, 'already applying: no duplicate call');

    cardData.applying = false;
    cardData.applied = true;
    await widget.applyCoupon(cardData);
    assert.equal(calls.length, 0, 'already applied: no duplicate call');
});

test('a proactive message joins the thread directly when the overlay is already open', () => {
    const widget = component({ locale: 'fr_FR' });
    widget.state = 'open';

    widget.receiveProactiveMessage('cart_abandoned_session', 'Votre panier vous attend.');

    assert.equal(widget.proactive, null);
    assert.equal(widget.messages[0].kind, 'proactive');
    assert.equal(widget.messages[0].text, 'Votre panier vous attend.');
});

test('acceptProactive moves the floating suggestion into the thread and opens the widget', () => {
    const widget = component({ locale: 'fr_FR' });
    widget.proactive = { scenario: 'hesitation', text: 'Still there?' };

    widget.acceptProactive();

    assert.equal(widget.proactive, null);
    assert.equal(widget.state, 'open');
    assert.equal(widget.messages[0].kind, 'proactive');
    assert.equal(widget.messages[0].text, 'Still there?');
});
