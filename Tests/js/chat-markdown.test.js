'use strict';

// Node built-in test runner (node --test local/modules/CommerceAgents/Tests/js).
// Exercises the browser Markdown renderer against a minimal DOM stub, with a
// node budget so a non-progressing render loop fails instead of hanging.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const MAX_NODES = 5000;
const SOURCE = path.join(__dirname, '../../templates/backOffice/default-twig/assets/js/chat-markdown.js');
const TWIN = path.join(__dirname, '../../templates/frontOffice/default/assets/js/chat-markdown.js');

function element(tag) {
    return {
        nodeName: tag.toUpperCase(),
        childNodes: [],
        className: '',
        textContent: '',
        classList: { add() {} },
        setAttribute() {},
        get firstChild() { return this.childNodes[0]; },
        appendChild(child) { this.childNodes.push(child); return child; },
    };
}

function loadRenderer() {
    global.document = { createElement: element, createTextNode: (text) => ({ nodeName: '#text', textContent: text }) };
    const fakeWindow = { location: { origin: 'https://shop.test' } };
    new Function('window', fs.readFileSync(SOURCE, 'utf8'))(fakeWindow);
    return fakeWindow.CommerceAgentsMarkdown;
}

function renderBlocks(markdown) {
    const { render } = loadRenderer();
    const container = element('div');
    let appended = 0;
    const append = container.appendChild.bind(container);
    container.appendChild = (node) => {
        appended += 1;
        assert.ok(appended <= MAX_NODES, `renderer did not progress on ${JSON.stringify(markdown)}`);
        return append(node);
    };
    render(container, markdown);
    return container.childNodes;
}

function text(node) {
    return node.nodeName === '#text' ? node.textContent : node.childNodes.map(text).join('');
}

test('twin copies stay identical', () => {
    assert.equal(fs.readFileSync(SOURCE, 'utf8'), fs.readFileSync(TWIN, 'utf8'));
});

test('a complete table renders as a table', () => {
    const blocks = renderBlocks('| a | b |\n|---|---|\n| 1 | 2 |');
    assert.equal(blocks.length, 1);
    assert.equal(blocks[0].nodeName, 'TABLE');
});

test('a table header row without its separator yet (streaming) renders as text and terminates', () => {
    for (const markdown of ['| a | b |', '| a | b |\n|---', 'text\n| a | b |', '| a | b |\n\nafter']) {
        const blocks = renderBlocks(markdown);
        assert.ok(blocks.length >= 1 && blocks.length <= 3, `unexpected block count for ${JSON.stringify(markdown)}`);
        assert.ok(blocks.every((block) => block.nodeName === 'P'));
        assert.ok(text(blocks[0]).includes('| a | b |') || text(blocks[1] || blocks[0]).includes('| a | b |'));
    }
});

test('lists, headings and paragraphs still render', () => {
    const blocks = renderBlocks('#### Title\n\n- one\n- two\n\n1. first\n2) second\n\nplain **bold** text');
    assert.deepEqual(blocks.map((block) => block.nodeName), ['P', 'UL', 'OL', 'P']);
    assert.equal(blocks[1].childNodes.length, 2);
    assert.equal(blocks[2].childNodes.length, 2);
    assert.equal(text(blocks[3]), 'plain bold text');
});

function serialize(node) {
    if (node.nodeName === '#text') {
        return JSON.stringify(node.textContent);
    }
    return node.nodeName + '.' + node.className + '[' + node.childNodes.map(serialize).join(',') + ']';
}

test('append on both sides of a blank line builds the same DOM as one render', () => {
    const { render, append } = loadRenderer();
    const sample = '#### Title\n\npara **b** [l](/x)\nsecond line\n\n| a | b |\n|---|---|\n| 1 | 2 |\n| 3 | 4 |\n\n- x\n- y\n\n\n1. one\n2. two\n\n| c |\n\ntail *i*';
    const whole = element('div');
    render(whole, sample);
    let boundary = sample.indexOf('\n\n');
    let checked = 0;
    while (boundary !== -1) {
        const split = element('div');
        append(split, sample.slice(0, boundary));
        append(split, sample.slice(boundary + 2));
        assert.equal(serialize(split), serialize(whole), 'split at ' + boundary);
        checked += 1;
        boundary = sample.indexOf('\n\n', boundary + 1);
    }
    assert.ok(checked >= 6);
});
