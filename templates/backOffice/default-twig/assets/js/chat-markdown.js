'use strict';

/**
 * Minimal safe Markdown renderer for agent replies.
 *
 * Builds DOM exclusively through createElement/textContent — LLM output is
 * never parsed as HTML. Supported: paragraphs, bold and italic in both the
 * asterisk and underscore forms, strikethrough, inline code, fenced code blocks,
 * [links](url) (http(s) or site-relative only), unordered/ordered lists with
 * one level of nesting and wrapped items, blockquotes, horizontal rules,
 * tables, # to ###### headings, and backslash escapes. A paragraph made of a
 * single link is styled as a call-to-action button.
 *
 * Twin file notice: this file is shipped twice (frontOffice and backOffice
 * asset directories) because module_asset() resolves per template type.
 * Keep both copies identical.
 */
(function (global) {
    function isWordChar(character) {
        return character !== undefined && /[A-Za-z0-9]/.test(character);
    }

    function appendInline(parent, text) {
        // A Markdown image never belongs in chat text: products carry their own
        // card, and the link syntax below would otherwise turn it into a bare
        // link to a JPEG.
        text = String(text).replace(/!\[[^\]]*\]\([^\s)]*\)/g, '');

        // Fresh regex per call: appendInline recurses into bold/italic/strike
        // and link labels, and a shared lastIndex would corrupt the outer scan.
        var inline = new RegExp(
            '(\\\\[\\\\`*_~\\[\\]()#+\\-.!>])'          // 1: escaped punctuation
            + '|(\\*\\*([^*]+)\\*\\*)'                   // 2,3: **bold**
            + '|(__([^_]+)__)'                           // 4,5: __bold__
            + '|(\\*([^*\\s][^*]*)\\*)'                  // 6,7: *italic*
            + '|(_([^_\\s][^_]*)_)'                      // 8,9: _italic_
            + '|(~~([^~]+)~~)'                           // 10,11: ~~strike~~
            + '|(`([^`]+)`)'                             // 12,13: `code`
            + '|(\\[([^\\]]+)\\]\\(((?:https?://|/)[^\\s)]*)\\))', // 14,15,16: link
            'g'
        );

        var last = 0;
        var match;
        while ((match = inline.exec(text)) !== null) {
            // snake_case and file_names must not turn into italics.
            if (match[9] !== undefined
                && (isWordChar(text[match.index - 1]) || isWordChar(text[match.index + match[0].length]))) {
                continue;
            }

            if (match.index > last) {
                parent.appendChild(document.createTextNode(text.slice(last, match.index)));
            }

            var el;
            if (match[1] !== undefined) {
                parent.appendChild(document.createTextNode(match[1].slice(1)));
                last = match.index + match[0].length;
                continue;
            } else if (match[3] !== undefined || match[5] !== undefined) {
                el = document.createElement('strong');
                appendInline(el, match[3] !== undefined ? match[3] : match[5]);
            } else if (match[7] !== undefined || match[9] !== undefined) {
                el = document.createElement('em');
                appendInline(el, match[7] !== undefined ? match[7] : match[9]);
            } else if (match[11] !== undefined) {
                el = document.createElement('s');
                appendInline(el, match[11]);
            } else if (match[13] !== undefined) {
                el = document.createElement('code');
                el.textContent = match[13];
            } else {
                el = document.createElement('a');
                appendInline(el, match[15]);
                el.setAttribute('href', match[16]);
                el.className = 'cam-link';
                if (match[16].indexOf('http') === 0 && match[16].indexOf(global.location.origin) !== 0) {
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

    function isHorizontalRule(line) {
        return /^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/.test(line);
    }

    function isFence(line) {
        return /^\s*(?:```|~~~)/.test(line);
    }

    function isQuote(line) {
        return /^\s*>\s?/.test(line);
    }

    function listMarker(line) {
        var match = /^(\s*)(?:[-*+]|\d+[.)])\s+(.*)$/.exec(line);

        return match === null ? null : { indent: match[1].length, ordered: /^\s*\d/.test(line), text: match[2] };
    }

    function render(container, markdown) {
        container.textContent = '';
        append(container, markdown);
    }

    // Renders after the existing children. No block ever spans a blank line,
    // so appending two halves split on "\n\n" builds the same DOM as one
    // render: the chat widgets rely on this to rebuild only the streaming tail.
    // A fenced code block holding a blank line is the one exception; the final
    // full render at end of stream settles it.
    function append(container, markdown) {
        var lines = String(markdown || '').split('\n');
        var i = 0;

        // A table only starts on a row followed by its separator line. While the
        // reply streams in, the header row arrives before the separator: it must
        // fall through to the paragraph branch, otherwise nothing consumes it.
        function isTableStart(index) {
            return isTableRow(lines[index]) && index + 1 < lines.length && isTableSeparator(lines[index + 1]);
        }

        function startsBlock(index) {
            return listMarker(lines[index]) !== null
                || /^\s*#{1,6}\s+/.test(lines[index])
                || isHorizontalRule(lines[index])
                || isQuote(lines[index])
                || isFence(lines[index])
                || isTableStart(index);
        }

        function buildList(baseIndent) {
            var first = listMarker(lines[i]);
            var list = document.createElement(first.ordered ? 'ol' : 'ul');
            list.className = 'cam-list';
            var item = null;

            while (i < lines.length && lines[i].trim() !== '') {
                var marker = listMarker(lines[i]);

                if (marker !== null) {
                    if (marker.indent > baseIndent && item !== null) {
                        item.appendChild(buildList(marker.indent));
                        continue;
                    }
                    if (marker.indent < baseIndent || marker.ordered !== first.ordered) {
                        break;
                    }
                    item = document.createElement('li');
                    appendInline(item, marker.text);
                    list.appendChild(item);
                    i += 1;
                    continue;
                }

                // An item wrapped onto the next line belongs to that item.
                if (item !== null && /^\s+\S/.test(lines[i]) && !startsBlock(i)) {
                    item.appendChild(document.createTextNode(' '));
                    appendInline(item, lines[i].trim());
                    i += 1;
                    continue;
                }

                break;
            }

            return list;
        }

        while (i < lines.length) {
            var line = lines[i];

            if (line.trim() === '') {
                i += 1;
                continue;
            }

            // Fences first: nothing inside a code block is Markdown.
            if (isFence(line)) {
                var fence = /^\s*(```|~~~)/.exec(line)[1];
                var code = [];
                i += 1;
                while (i < lines.length && lines[i].indexOf(fence) === -1) {
                    code.push(lines[i]);
                    i += 1;
                }
                i += 1;
                var pre = document.createElement('pre');
                pre.className = 'cam-code';
                var codeEl = document.createElement('code');
                codeEl.textContent = code.join('\n');
                pre.appendChild(codeEl);
                container.appendChild(pre);
                continue;
            }

            // Before lists: "* * *" is a rule, not three bullets.
            if (isHorizontalRule(line)) {
                var rule = document.createElement('hr');
                rule.className = 'cam-rule';
                container.appendChild(rule);
                i += 1;
                continue;
            }

            if (isTableStart(i)) {
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

            if (isQuote(line)) {
                var quoted = [];
                while (i < lines.length && isQuote(lines[i])) {
                    quoted.push(lines[i].replace(/^\s*>\s?/, ''));
                    i += 1;
                }
                var quote = document.createElement('blockquote');
                quote.className = 'cam-quote';
                append(quote, quoted.join('\n'));
                container.appendChild(quote);
                continue;
            }

            if (listMarker(line) !== null) {
                container.appendChild(buildList(listMarker(line).indent));
                continue;
            }

            if (/^\s*#{1,6}\s+/.test(line)) {
                var heading = document.createElement('p');
                heading.className = 'cam-heading';
                appendInline(heading, line.replace(/^\s*#{1,6}\s+/, ''));
                container.appendChild(heading);
                i += 1;
                continue;
            }

            var paragraph = document.createElement('p');
            paragraph.className = 'cam-p';
            var paragraphStart = i;
            var first = true;
            while (i < lines.length && lines[i].trim() !== '' && !startsBlock(i)) {
                if (!first) {
                    paragraph.appendChild(document.createElement('br'));
                }
                appendInline(paragraph, lines[i]);
                first = false;
                i += 1;
            }
            if (i === paragraphStart) {
                // Never stall: a line no branch claims is shown as plain text.
                appendInline(paragraph, lines[i]);
                i += 1;
            }
            if (paragraph.childNodes.length === 1 && paragraph.firstChild.nodeName === 'A') {
                paragraph.firstChild.classList.add('cam-cta');
            }
            container.appendChild(paragraph);
        }
    }

    global.CommerceAgentsMarkdown = { render: render, append: append };
})(window);
