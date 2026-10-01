/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Quick type-ahead: Quissly product suggestions as the shopper types.
 *
 * Two layouts, chosen by the merchant, over the same data and the same order:
 *   rows - a scrollable list, thumbnail + name + price per row
 *   carousel - large cards the shopper pages through
 *
 * Framework-free so Luma and Hyvä both work without
 * RequireJS or jQuery.
 *
 * Everything here is catalog data that arrived over the wire, so it is built
 * with createElement/textContent and never innerHTML. The Woo Quick XSS - a
 * product title containing markup, concatenated into a string and assigned -
     * is the named counterexample this file exists not to repeat.
 */
(function () {
    'use strict';

    var config = document.getElementById('quissly-quick-config');
    if (!config) {
        return;
    }

    var ENDPOINT = config.getAttribute('data-endpoint');
    var RESULTS_URL = config.getAttribute('data-results-url') || '';
    var CURRENCY = config.getAttribute('data-currency') || '';
    var STYLE = config.getAttribute('data-style') === 'carousel' ? 'carousel' : 'rows';
    var LABEL_ALL = config.getAttribute('data-label-all') || 'See all';
    var LABEL_DETAILS = config.getAttribute('data-label-details') || 'Details';
    var MIN_CHARS = 2;
    var DEBOUNCE_MS = 300;

    /**
     * Every search field Quick should serve.
     *
     * The immersive overlay builds its OWN input in a centred panel,
     * outside the header form. Binding once at load to the header input meant
     * that with the overlay on, a shopper typed into a field Quick had never
     * heard of and no suggestions ever appeared - two features that each work
     * alone, silently cancelling each other.
     */
    var INPUT_SELECTOR = [
        'form[action*="catalogsearch"] input[type="text"]',
        'form[action*="catalogsearch"] input[type="search"]',
        '.quissly-overlay__input',
        '#search'
    ].join(',');

    /**
     * @param {EventTarget} node
     * @returns {boolean}
     */
    function isSearchInput(node) {
        return !!(node && node.matches && node.matches(INPUT_SELECTOR));
    }

    var headerInput = document.querySelector(
        'form[action*="catalogsearch"] input[type="text"],'
        + 'form[action*="catalogsearch"] input[type="search"],#search'
    );

    // The overlay's input is created on demand, so there may be nothing to
    // bind to yet - the document-level listeners below cover it either way.
    var activeInput = headerInput;
    var panel = null;
    var track = null;
    var timer = null;
    var sequence = 0;
    var allSuggestions = [];
    var term = '';

    /**
     * Magento's own suggest is a spell-corrector, not product suggestions, and
     * two dropdowns fighting for one space is worse than either alone.
     */
    function suppressNativeSuggest() {
        if (headerInput) {
            headerInput.setAttribute('autocomplete', 'off');
        }
        var native = document.getElementById('search_autocomplete');
        if (native) {
            native.setAttribute('hidden', 'hidden');
            native.style.display = 'none';
        }
    }

    function ensurePanel() {
        if (panel) {
            return panel;
        }
        panel = document.createElement('div');
        panel.className = 'quissly-quick quissly-quick--' + STYLE;
        panel.setAttribute('role', 'dialog');
        panel.hidden = true;
        // Appended to body, not the form: themes clip and overflow-hide their
        // header, which would cut the panel off.
        document.body.appendChild(panel);
        return panel;
    }

    /**
     * Anchor the panel under whichever field is being typed in.
     *
     * Fixed rather than absolute: the overlay is position:fixed, so document
     * coordinates would put the panel in the wrong place there. Fixed is
     * correct in both contexts as long as we follow scrolling, which the
     * listener at the bottom does.
     */
    function position() {
        if (!activeInput || panel.classList.contains('quissly-quick--in-overlay')) {
            return;
        }
        // Anchor to the visible field, not the bare input. The overlay's
        // input sits inset inside a pill that also holds the voice and image
        // buttons, so aligning to the input alone leaves the panel visibly
        // off-centre under the bar.
        var anchor = activeInput.closest('.quissly-overlay__field') || activeInput;
        var box = anchor.getBoundingClientRect();

        var width = STYLE === 'carousel'
            ? Math.min(window.innerWidth - 32, 900)
            : Math.min(Math.max(box.width, 420), window.innerWidth - 32);

        // Centre on the field rather than aligning to its left edge. The
        // carousel is deliberately wider than the search bar, so left-aligning
        // pushed it visibly off to one side; centring keeps it under the bar
        // whatever the two widths are. Clamped so it never leaves the viewport.
        var left = box.left + (box.width / 2) - (width / 2);
        left = Math.min(left, window.innerWidth - width - 16);

        panel.style.position = 'fixed';
        panel.style.top = (box.bottom + 10) + 'px';
        panel.style.left = Math.max(16, left) + 'px';
        panel.style.width = width + 'px';
    }

    /**
     * Put the panel where the shopper is typing.
     *
     * In the search overlay's bar, the panel moves into the overlay's results
     * slot and becomes a full-width card under the bar, laid out by the
     * stylesheet rather than measured. Anywhere else it is the floating
     * dropdown under the field.
     */
    function place() {
        var slot = activeInput && activeInput.classList.contains('quissly-overlay__input')
            ? document.querySelector('[data-quissly-overlay-results]')
            : null;
        if (slot) {
            if (panel.parentNode !== slot) {
                slot.appendChild(panel);
            }
            panel.classList.add('quissly-quick--in-overlay');
            panel.style.position = '';
            panel.style.top = '';
            panel.style.left = '';
            panel.style.width = '';
            return;
        }
        if (panel.parentNode !== document.body) {
            document.body.appendChild(panel);
        }
        panel.classList.remove('quissly-quick--in-overlay');
        position();
    }

    function close() {
        if (panel) {
            panel.hidden = true;
        }
        allSuggestions = [];
    }

    /**
     * Only http(s) survives. The server strips other schemes too; this is the
     * second gate, because one of them being wrong should not be enough.
     *
     * @param {string} url
     * @returns {string}
     */
    function safeUrl(url) {
        if (typeof url !== 'string' || url === '') {
            return '';
        }
        if (url.indexOf(':') === -1) {
            return url;
        }
        var scheme = (url.split(':')[0] || '').toLowerCase();
        return (scheme === 'http' || scheme === 'https') ? url : '';
    }

    /**
     * @param {number} value
     * @returns {string}
     */
    function money(value) {
        var n = Number(value);
        if (!isFinite(n)) {
            return '';
        }
        var amount = n % 1 === 0 ? String(n) : n.toFixed(2);
        return CURRENCY ? amount + ' ' + CURRENCY : amount;
    }

    /**
     * @param {string} tag
     * @param {string} className
     * @param {string} [text]
     * @returns {HTMLElement}
     */
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function chevron() {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'quissly-quick__chevron');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        var path = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
        path.setAttribute('points', '9 5 16 12 9 19');
        svg.appendChild(path);
        return svg;
    }

    /**
     * The header: a single "see all" link.
     *
     * @returns {HTMLElement}
     */
    function header() {
        var bar = el('div', 'quissly-quick__header');

        var all = document.createElement('a');
        all.className = 'quissly-quick__all';
        all.textContent = LABEL_ALL;
        all.href = RESULTS_URL
            + (RESULTS_URL.indexOf('?') === -1 ? '?' : '&')
            + 'q=' + encodeURIComponent(term);
        bar.appendChild(all);

        return bar;
    }

    /**
     * A neutral stand-in for a product with no usable image.
     *
     * Quick's image field is often empty, and Quissly's stored URL can be
     * stale enough to 404. An <img> with no src, or a src that fails, renders
     * as a broken-image box - worse than showing nothing.
     *
     * @param {string} className
     * @returns {HTMLElement}
     */
    function placeholder(className) {
        var box = el('div', className + ' quissly-quick__noimage');
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.5');
        var rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        rect.setAttribute('x', '3');
        rect.setAttribute('y', '5');
        rect.setAttribute('width', '18');
        rect.setAttribute('height', '14');
        rect.setAttribute('rx', '2');
        var hill = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
        hill.setAttribute('points', '3 16 8 11 13 16 17 13 21 16');
        svg.appendChild(rect);
        svg.appendChild(hill);
        box.appendChild(svg);
        return box;
    }

    /**
     * An image element, or a placeholder when there is nothing to show.
     *
     * @param {Object} suggestion
     * @param {string} className
     * @returns {HTMLElement}
     */
    function thumbnail(suggestion, className) {
        var src = safeUrl(suggestion.image);
        if (!src) {
            return placeholder(className);
        }
        var img = document.createElement('img');
        img.className = className;
        img.alt = '';
        img.loading = 'lazy';
        img.src = src;
        // A stale URL should degrade to the placeholder, not a broken icon.
        img.addEventListener('error', function () {
            var fallback = placeholder(className);
            if (img.parentNode) {
                img.parentNode.replaceChild(fallback, img);
            }
        });
        return img;
    }

    /**
     * One row: thumbnail, name, price, chevron.
     *
     * @param {Object} suggestion
     * @returns {HTMLElement}
     */
    function rowItem(suggestion) {
        var url = safeUrl(suggestion.url);
        var item = document.createElement(url ? 'a' : 'div');
        item.className = 'quissly-quick__row';
        if (url) {
            item.href = url;
        }

        item.appendChild(thumbnail(suggestion, 'quissly-quick__thumb'));

        var body = el('span', 'quissly-quick__row-body');
        body.appendChild(el('span', 'quissly-quick__row-title', suggestion.title));
        if (suggestion.price !== null && suggestion.price !== undefined) {
            var price = el('span', 'quissly-quick__row-price', money(suggestion.price));
            if (suggestion.was !== null && suggestion.was !== undefined
                && Number(suggestion.was) > Number(suggestion.price)) {
                price.appendChild(el('span', 'quissly-quick__was', money(suggestion.was)));
            }
            body.appendChild(price);
        }
        item.appendChild(body);
        item.appendChild(chevron());

        return item;
    }

    /**
     * One card: large image, name, price, call to action.
     *
     * @param {Object} suggestion
     * @returns {HTMLElement}
     */
    function cardItem(suggestion) {
        var url = safeUrl(suggestion.url);
        var card = el('div', 'quissly-quick__card');

        var media = el('div', 'quissly-quick__card-media');
        media.appendChild(thumbnail(suggestion, 'quissly-quick__card-image'));
        card.appendChild(media);

        card.appendChild(el('span', 'quissly-quick__card-title', suggestion.title));

        if (suggestion.price !== null && suggestion.price !== undefined) {
            var price = el('span', 'quissly-quick__card-price', money(suggestion.price));
            if (suggestion.was !== null && suggestion.was !== undefined
                && Number(suggestion.was) > Number(suggestion.price)) {
                price.appendChild(el('span', 'quissly-quick__was', money(suggestion.was)));
            }
            card.appendChild(price);
        }

        var cta = document.createElement(url ? 'a' : 'button');
        cta.className = 'quissly-quick__cta';
        cta.textContent = LABEL_DETAILS;
        if (url) {
            cta.href = url;
        } else {
            cta.type = 'button';
        }
        card.appendChild(cta);

        // The whole card is clickable, not just the button.
        if (url) {
            card.addEventListener('click', function (event) {
                if (event.target !== cta) {
                    window.location.href = url;
                }
            });
        }

        return card;
    }

    function arrow(direction) {
        var button = el('button', 'quissly-quick__arrow quissly-quick__arrow--' + direction);
        button.type = 'button';
        button.setAttribute('aria-label', direction);
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        var line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
        line.setAttribute('points', direction === 'next' ? '9 5 16 12 9 19' : '15 5 8 12 15 19');
        svg.appendChild(line);
        button.appendChild(svg);
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            if (!track) {
                return;
            }
            var step = track.clientWidth * 0.8;
            track.scrollBy({left: direction === 'next' ? step : -step, behavior: 'smooth'});
        });
        return button;
    }

    /**
     * Rebuild the panel from the current suggestions and active filter.
     */
    function paint() {
        ensurePanel();
        while (panel.firstChild) {
            panel.removeChild(panel.firstChild);
        }

        var visible = allSuggestions;

        if (!visible.length) {
            close();
            return;
        }

        panel.appendChild(header());

        if (STYLE === 'carousel') {
            var frame = el('div', 'quissly-quick__frame');
            track = el('div', 'quissly-quick__track');
            visible.forEach(function (suggestion) {
                track.appendChild(cardItem(suggestion));
            });
            frame.appendChild(arrow('prev'));
            frame.appendChild(track);
            frame.appendChild(arrow('next'));
            panel.appendChild(frame);
        } else {
            var list = el('div', 'quissly-quick__list');
            visible.forEach(function (suggestion) {
                list.appendChild(rowItem(suggestion));
            });
            panel.appendChild(list);
        }

        place();
        panel.hidden = false;
    }

    function fetchSuggestions(value) {
        var mine = ++sequence;
        var url = ENDPOINT + (ENDPOINT.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(value);

        fetch(url, {credentials: 'same-origin'})
            .then(function (response) {
                return response.ok ? response.json() : {suggestions: []};
            })
            .then(function (data) {
                // A slower earlier request must never overwrite a newer one.
                if (mine !== sequence) {
                    return;
                }
                allSuggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
                paint();
            })
            .catch(function () {
                // Nobody is owed a dropdown. Failing silently is correct here.
                close();
            });
    }

    suppressNativeSuggest();

    // Listening on the document rather than on one element: the overlay's
    // input does not exist at load, and the shopper may move between fields.
    document.addEventListener('input', function (event) {
        if (!isSearchInput(event.target)) {
            return;
        }
        activeInput = event.target;
        term = (activeInput.value || '').trim();
        window.clearTimeout(timer);
        if (term.length < MIN_CHARS) {
            close();
            return;
        }
        timer = window.setTimeout(function () {
            fetchSuggestions(term);
        }, DEBOUNCE_MS);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            close();
        }
    });

    // Focus moving to the other field must not leave a panel anchored to the
    // one the shopper left.
    document.addEventListener('focusin', function (event) {
        if (isSearchInput(event.target) && event.target !== activeInput) {
            activeInput = event.target;
            close();
        }
    });

    document.addEventListener('click', function (event) {
        if (panel && !panel.hidden && !isSearchInput(event.target) && !panel.contains(event.target)) {
            close();
        }
    });

    window.addEventListener('resize', function () {
        if (panel && !panel.hidden) {
            position();
        }
    });

    // The panel is position:fixed, so it does not travel with the page.
    window.addEventListener('scroll', function () {
        if (panel && !panel.hidden) {
            position();
        }
    }, true);
})();
