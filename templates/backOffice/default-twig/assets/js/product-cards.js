'use strict';

/**
 * Renders a horizontal strip of product cards (thumbnail + title + a metric)
 * for the merchant chat, from the structured results of get_listings and
 * get_inventory. DOM is built through createElement/textContent only — tool
 * data is never parsed as HTML. A card with a url is a link; without, a div.
 *
 * Back-office only: the shopping front renders its own cards through Alpine.
 */
(function (global) {
    function card(item) {
        var el = document.createElement(item.url ? 'a' : 'div');
        el.className = 'cam-card';
        if (item.url) {
            el.setAttribute('href', item.url);
        }

        var media = document.createElement('span');
        media.className = 'cam-card-media';
        if (item.imageUrl) {
            var img = document.createElement('img');
            img.setAttribute('src', item.imageUrl);
            img.setAttribute('alt', item.title || '');
            img.setAttribute('loading', 'lazy');
            media.appendChild(img);
        } else {
            media.classList.add('cam-card-media-empty');
            media.setAttribute('aria-hidden', 'true');
        }
        el.appendChild(media);

        var title = document.createElement('span');
        title.className = 'cam-card-title';
        title.textContent = item.title || '';
        el.appendChild(title);

        if (item.meta !== undefined && item.meta !== null && item.meta !== '') {
            var meta = document.createElement('span');
            meta.className = 'cam-card-meta' + (item.metaClass ? ' ' + item.metaClass : '');
            meta.textContent = item.meta;
            el.appendChild(meta);
        }

        return el;
    }

    // items: [{ title, imageUrl, url, meta, metaClass }]
    function render(container, items) {
        var strip = document.createElement('div');
        strip.className = 'cam-cards';
        (items || []).forEach(function (item) {
            strip.appendChild(card(item));
        });
        container.appendChild(strip);
        return strip;
    }

    // Maps a get_listings / get_inventory tool result to card items, or null
    // when the tool has no products to show visually.
    // labels: { hidden, stock }
    function fromToolResult(name, result, labels) {
        labels = labels || {};
        if (name === 'get_listings' && Array.isArray(result.listings) && result.listings.length > 0) {
            return result.listings.map(function (listing) {
                return {
                    title: listing.title,
                    imageUrl: listing.imageUrl,
                    url: listing.publicUrl,
                    meta: listing.visible ? listing.ref : (labels.hidden || 'Hidden'),
                    metaClass: listing.visible ? '' : 'cam-card-meta-warn',
                };
            });
        }
        if (name === 'get_inventory' && Array.isArray(result.inventory) && result.inventory.length > 0) {
            return result.inventory.map(function (variant) {
                return {
                    title: variant.title,
                    imageUrl: variant.imageUrl,
                    url: null,
                    meta: (labels.stock || 'Stock') + ': ' + variant.quantity,
                    metaClass: variant.quantity <= 0 ? 'cam-card-meta-warn' : '',
                };
            });
        }
        return null;
    }

    global.CommerceAgentsProductCards = { render: render, fromToolResult: fromToolResult };
})(window);
