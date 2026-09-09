'use strict';

/**
 * Minimal safe Markdown renderer for agent replies.
 *
 * Builds DOM exclusively through createElement/textContent — LLM output is
 * never parsed as HTML. Supported: paragraphs, **bold**, *italic*,
 * ~~strikethrough~~, `code`, [links](url) (http(s) or site-relative only),
 * unordered/ordered lists, tables, #### headings. A paragraph made of a single
 * link is styled as a call-to-action button.
 *
 * Twin file notice: this file is shipped twice (frontOffice and backOffice
 * asset directories) because module_asset() resolves per template type.
 * Keep both copies identical.
 */
(function (global) {
    var INLINE = /(\*\*([^*]+)\*\*)|(\*([^*\s][^*]*)\*)|(~~([^~]+)~~)|(`([^`]+)`)|(\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]*)\))/g;

    function appendInline(parent, text) {
        var last = 0;
        var match;
        INLINE.lastIndex = 0;
        while ((match = INLINE.exec(text)) !== null) {
            if (match.index > last) {
                parent.appendChild(document.createTextNode(text.slice(last, match.index)));
            }
            var el;
            if (match[2] !== undefined) {
                el = document.createElement('strong');
                el.textContent = match[2];
            } else if (match[4] !== undefined) {
                el = document.createElement('em');
                el.textContent = match[4];
            } else if (match[6] !== undefined) {
                el = document.createElement('s');
                el.textContent = match[6];
            } else if (match[8] !== undefined) {
                el = document.createElement('code');
                el.textContent = match[8];
            } else {
                el = document.createElement('a');
                el.textContent = match[10];
                el.setAttribute('href', match[11]);
                el.className = 'cam-link';
                if (match[11].indexOf('http') === 0 && match[11].indexOf(global.location.origin) !== 0) {
                    el.setAttribute('rel', 'noopener');
                    el.setAttribute('target', '_blank');
                }
            }
            parent.appendChild(el);
            last = match.index + match[0].length;
        }
        if (last < text.length) {
            parent.appendChild(document.createTextNode(text.slice(last)));
        }
    }

    function isTableRow(line) {
        return /^\s*\|.*\|\s*$/.test(line);
    }

    function isTableSeparator(line) {
        return /^\s*\|(\s*:?-+:?\s*\|)+\s*$/.test(line);
    }

    function splitRow(line) {
        return line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (cell) {
            return cell.trim();
        });
    }

    function render(container, markdown) {
        container.textContent = '';
        var lines = String(markdown || '').split('\n');
        var i = 0;

        while (i < lines.length) {
            var line = lines[i];

            if (line.trim() === '') {
                i += 1;
                continue;
            }

            if (isTableRow(line) && i + 1 < lines.length && isTableSeparator(lines[i + 1])) {
                var table = document.createElement('table');
                table.className = 'cam-table';
                var thead = document.createElement('thead');
                var headRow = document.createElement('tr');
                splitRow(line).forEach(function (cell) {
                    var th = document.createElement('th');
                    appendInline(th, cell);
                    headRow.appendChild(th);
                });
                thead.appendChild(headRow);
                table.appendChild(thead);

                var tbody = document.createElement('tbody');
                i += 2;
                while (i < lines.length && isTableRow(lines[i])) {
                    var tr = document.createElement('tr');
                    splitRow(lines[i]).forEach(function (cell) {
                        var td = document.createElement('td');
                        appendInline(td, cell);
                        tr.appendChild(td);
                    });
                    tbody.appendChild(tr);
                    i += 1;
                }
                table.appendChild(tbody);
                container.appendChild(table);
                continue;
            }

            if (/^\s*[-*]\s+/.test(line)) {
                var ul = document.createElement('ul');
                ul.className = 'cam-list';
                while (i < lines.length && /^\s*[-*]\s+/.test(lines[i])) {
                    var li = document.createElement('li');
                    appendInline(li, lines[i].replace(/^\s*[-*]\s+/, ''));
                    ul.appendChild(li);
                    i += 1;
                }
                container.appendChild(ul);
                continue;
            }

            if (/^\s*\d+[.)]\s+/.test(line)) {
                var ol = document.createElement('ol');
                ol.className = 'cam-list';
                while (i < lines.length && /^\s*\d+[.)]\s+/.test(lines[i])) {
                    var oli = document.createElement('li');
                    appendInline(oli, lines[i].replace(/^\s*\d+[.)]\s+/, ''));
                    ol.appendChild(oli);
                    i += 1;
                }
                container.appendChild(ol);
                continue;
            }

            if (/^#{1,4}\s+/.test(line)) {
                var heading = document.createElement('p');
                heading.className = 'cam-heading';
                appendInline(heading, line.replace(/^#{1,4}\s+/, ''));
                container.appendChild(heading);
                i += 1;
                continue;
            }

            var paragraph = document.createElement('p');
            paragraph.className = 'cam-p';
            var first = true;
            while (i < lines.length && lines[i].trim() !== ''
                && !/^\s*([-*]|\d+[.)]|#{1,4})\s+/.test(lines[i]) && !isTableRow(lines[i])) {
                if (!first) {
                    paragraph.appendChild(document.createElement('br'));
                }
                appendInline(paragraph, lines[i]);
                first = false;
                i += 1;
            }
            if (paragraph.childNodes.length === 1 && paragraph.firstChild.nodeName === 'A') {
                paragraph.firstChild.classList.add('cam-cta');
            }
            container.appendChild(paragraph);
        }
    }

    global.CommerceAgentsMarkdown = { render: render };
})(window);
