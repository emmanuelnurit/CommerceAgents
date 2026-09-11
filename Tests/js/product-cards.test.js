'use strict';

// Node built-in test runner (node --test local/modules/CommerceAgents/Tests/js).
// Exercises the back-office product-card renderer and its tool-result mapper
// against a minimal DOM stub.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const SOURCE = path.join(__dirname, '../../templates/backOffice/default-twig/assets/js/product-cards.js');

function element(tag) {
    return {
        nodeName: tag.toUpperCase(),
        childNodes: [],
        className: '',
        textContent: '',
        attributes: {},
        classList: {
            _owner: null,
            add(name) { this._names.push(name); },
            _names: [],
        },
        setAttribute(name, value) { this.attributes[name] = value; },
        get firstChild() { return this.childNodes[0]; },
        appendChild(child) { this.childNodes.push(child); return child; },
    };
}

function loadRenderer() {
    global.document = { createElement: element, createTextNode: (text) => ({ nodeName: '#text', textContent: text }) };
    const fakeWindow = {};
    new Function('window', fs.readFileSync(SOURCE, 'utf8'))(fakeWindow);
    return fakeWindow.CommerceAgentsProductCards;
}

const LABELS = { hidden: 'Masqué', stock: 'Stock' };

test('get_listings maps to cards with public url and ref, hidden badge when not visible', () => {
    const { fromToolResult } = loadRenderer();
    const items = fromToolResult('get_listings', {
        listings: [
            { id: 1, ref: 'PROD001', title: 'Chaise', visible: true, publicUrl: '/chaise.html', imageUrl: '/cache/a.jpg' },
            { id: 2, ref: 'PROD002', title: 'Table', visible: false, publicUrl: '/table.html', imageUrl: null },
        ],
    }, LABELS);

    assert.equal(items.length, 2);
    assert.deepEqual(items[0], { title: 'Chaise', imageUrl: '/cache/a.jpg', url: '/chaise.html', meta: 'PROD001', metaClass: '' });
    assert.equal(items[1].meta, 'Masqué');
    assert.equal(items[1].metaClass, 'cam-card-meta-warn');
});

test('get_inventory maps to cards with stock metric, warn when depleted', () => {
    const { fromToolResult } = loadRenderer();
    const items = fromToolResult('get_inventory', {
        inventory: [
            { productId: 1, title: 'Chaise', quantity: 12, imageUrl: '/cache/a.jpg' },
            { productId: 2, title: 'Table', quantity: 0, imageUrl: null },
        ],
    }, LABELS);

    assert.equal(items[0].meta, 'Stock: 12');
    assert.equal(items[0].metaClass, '');
    assert.equal(items[0].url, null);
    assert.equal(items[1].metaClass, 'cam-card-meta-warn');
});

test('a tool with no products yields null', () => {
    const { fromToolResult } = loadRenderer();
    assert.equal(fromToolResult('get_pricing', { pricing: {} }, LABELS), null);
    assert.equal(fromToolResult('get_listings', { listings: [] }, LABELS), null);
    assert.equal(fromToolResult('get_inventory', {}, LABELS), null);
});

test('render builds a card strip: link with img when url+image, div with placeholder otherwise', () => {
    const { render } = loadRenderer();
    const container = element('div');
    const strip = render(container, [
        { title: 'Chaise', imageUrl: '/cache/a.jpg', url: '/chaise.html', meta: 'PROD001', metaClass: '' },
        { title: 'Table', imageUrl: null, url: null, meta: 'Masqué', metaClass: 'cam-card-meta-warn' },
    ]);

    assert.equal(container.childNodes.length, 1);
    assert.equal(strip.className, 'cam-cards');
    assert.equal(strip.childNodes.length, 2);

    const linked = strip.childNodes[0];
    assert.equal(linked.nodeName, 'A');
    assert.equal(linked.attributes.href, '/chaise.html');
    const media = linked.childNodes[0];
    assert.equal(media.childNodes[0].nodeName, 'IMG');
    assert.equal(media.childNodes[0].attributes.src, '/cache/a.jpg');

    const plain = strip.childNodes[1];
    assert.equal(plain.nodeName, 'DIV');
    assert.equal(plain.attributes.href, undefined);
    assert.equal(plain.childNodes[0].classList._names.includes('cam-card-media-empty'), true);
});
