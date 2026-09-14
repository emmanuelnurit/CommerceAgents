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

test('a Markdown image is dropped, not turned into a link to a JPEG', () => {
    const blocks = renderBlocks('![Horatio](https://shop.test/cache/prod001.jpg)');

    // Without the strip the link syntax inside would yield an anchor labelled
    // "Horatio" pointing at the raw image, preceded by a stray "!".
    const anchors = blocks.flatMap((block) => (block.childNodes || []).filter((node) => node.nodeName === 'A'));
    assert.equal(anchors.length, 0);
    assert.equal(blocks.map(text).join('').trim(), '');
});

test('an image sitting inside a sentence leaves the sentence readable', () => {
    const blocks = renderBlocks('Le ![photo](/img/a.png) fauteuil Stacy est en promo.');

    const rendered = blocks.map(text).join('');
    assert.equal(rendered.includes('photo'), false);
    assert.ok(rendered.includes('fauteuil Stacy est en promo.'));
});

test('a real link is still a link', () => {
    // The DOM stub drops attributes, so assert on the anchor and its label.
    const blocks = renderBlocks('Voir la [page livraison](https://shop.test/delivery.html).');
    const anchors = blocks[0].childNodes.filter((node) => node.nodeName === 'A');

    assert.equal(anchors.length, 1);
    assert.equal(text(anchors[0]), 'page livraison');
});

function shape(node) {
    if (node.nodeName === '#text') {
        return JSON.stringify(node.textContent);
    }
    return node.nodeName + (node.childNodes.length ? '[' + node.childNodes.map(shape).join(',') + ']' : '');
}

test('a thematic break becomes a rule, not three dashes of text', () => {
    const blocks = renderBlocks('Ajouté au panier.\n\n---\n\nTotal : 732 €');

    assert.deepEqual(blocks.map((block) => block.nodeName), ['P', 'HR', 'P']);
});

test('every rule spelling is recognised', () => {
    for (const rule of ['---', '***', '___', '- - -'.replace(/ /g, ''), '****']) {
        assert.equal(renderBlocks(rule)[0].nodeName, 'HR', rule);
    }
});

test('a bullet list is not mistaken for a rule', () => {
    const blocks = renderBlocks('- Tina\n- Sally');

    assert.equal(blocks[0].nodeName, 'UL');
    assert.equal(blocks[0].childNodes.length, 2);
});

test('a quote is a blockquote, not a line starting with a chevron', () => {
    const blocks = renderBlocks('> Livraison offerte dès 50 €.\n> Retours sous 14 jours.');

    assert.equal(blocks[0].nodeName, 'BLOCKQUOTE');
    assert.ok(text(blocks[0]).includes('Livraison offerte'));
    assert.ok(text(blocks[0]).includes('Retours sous 14 jours'));
    assert.equal(text(blocks[0]).includes('>'), false);
});

test('a fenced block keeps its content verbatim', () => {
    const blocks = renderBlocks('Référence :\n\n```\nPROD001-0\n**not bold**\n```');

    assert.equal(blocks[1].nodeName, 'PRE');
    assert.equal(blocks[1].childNodes[0].nodeName, 'CODE');
    assert.equal(blocks[1].childNodes[0].textContent, 'PROD001-0\n**not bold**');
});

test('an unclosed fence still renders instead of swallowing the reply', () => {
    const blocks = renderBlocks('```\nPROD001-0');

    assert.equal(blocks[0].nodeName, 'PRE');
    assert.equal(blocks[0].childNodes[0].textContent, 'PROD001-0');
});

test('underscores are bold and italic too', () => {
    assert.equal(shape(renderBlocks('Le __Stacy__ est là.')[0]), 'P["Le ",STRONG["Stacy"]," est là."]');
    assert.equal(shape(renderBlocks('Le _Stacy_ est là.')[0]), 'P["Le ",EM["Stacy"]," est là."]');
});

test('an underscore inside a word stays a plain underscore', () => {
    const rendered = text(renderBlocks('Le fichier prod_001_a.jpg est en ligne.')[0]);

    assert.equal(rendered, 'Le fichier prod_001_a.jpg est en ligne.');
});

test('headings go up to six levels', () => {
    for (const hashes of ['#', '##', '###', '####', '#####', '######']) {
        const blocks = renderBlocks(hashes + ' Détail');
        assert.equal(blocks[0].className, 'cam-heading', hashes);
        assert.equal(text(blocks[0]), 'Détail');
    }
});

test('a nested list keeps its level', () => {
    const blocks = renderBlocks('- Chaises\n  - Tina\n  - Sally\n- Fauteuils');

    assert.equal(shape(blocks[0]), 'UL[LI["Chaises",UL[LI["Tina"],LI["Sally"]]],LI["Fauteuils"]]');
});

test('a wrapped list item stays one item', () => {
    const blocks = renderBlocks('- Tina, une chaise\n  légère et empilable\n- Sally');

    assert.equal(blocks[0].childNodes.length, 2);
    assert.equal(text(blocks[0].childNodes[0]), 'Tina, une chaise légère et empilable');
});

test('an ordered list does not swallow the bullets that follow', () => {
    const blocks = renderBlocks('1. Tina\n2. Sally\n- Autre');

    assert.equal(blocks[0].nodeName, 'OL');
    assert.equal(blocks[1].nodeName, 'UL');
});

test('a backslash protects the character that follows', () => {
    assert.equal(text(renderBlocks('Prix 5 \\* 3')[0]), 'Prix 5 * 3');
    assert.equal(text(renderBlocks('Un \\_vrai\\_ souligné')[0]), 'Un _vrai_ souligné');
});
