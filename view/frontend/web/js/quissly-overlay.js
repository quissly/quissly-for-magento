/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Immersive search overlay (default ON since 2026-09-06, rendered only where
 * Quissly is live - see ViewModel/OverlayConfig). Clicking the theme's search
 * box opens a full-width search bar at the top of a blurred page, with a close
 * button beside it and, below it, the store's search bar suggestions as buttons
 * while the bar is empty (the merchant's switch, on by default), then the slot
 * Quick fills with its suggestions ([data-quissly-overlay-results]; empty - and
 * invisible - when Quick is off).
 * Typing and pressing Enter goes to the normal results URL. Framework-free
 * vanilla JS (Luma + Hyvä), CSP-safe.
 *
 * PRESENTATION ONLY - it never changes which products are returned, and it never
 * touches the results page. Escape or a backdrop click closes it and returns
 * focus to the original input, so the native experience is always one key away.
 */
(function () {
    'use strict';

    var host = document.querySelector('[data-quissly-overlay]');
    if (!host) {
        return;
    }
    var resultsUrl = host.getAttribute('data-results-url') || '/catalogsearch/result/';
    var nativeInput = document.getElementById('search') ||
        document.querySelector('form[action*="catalogsearch"] input[type="text"], form[action*="catalogsearch"] input[type="search"]');

    var backdrop, panel, input, lastFocus, suggestPanel, clearBtn, recentGroup, suggestLists = null;
    var hasStaticSuggest = false;

    // Search bar suggestions (the Shopify app's typing animation): the merchant's
    // list, kept in Quissly (Model/Search/SearchSuggestions), typed letter by letter
    // into the EMPTY bar's placeholder - one query, deleted, the next, in random order
    // and never the same one twice in a row - while the overlay is open. Typing stops
    // the moment the shopper types; reduced motion shows one, still.
    var suggestions = [];
    try {
        suggestions = JSON.parse(host.getAttribute('data-suggestions') || '[]');
    } catch (e) {
        suggestions = [];
    }
    suggestions = (Array.isArray(suggestions) ? suggestions : []).filter(function (q) {
        return typeof q === 'string' && q.trim() !== '';
    });

    // The suggestion buttons under the empty bar: a list of their own (OverlayConfig::
    // chipsJson) - what Quissly generated (Automatic) or the merchant's (Manual), as the
    // Shopify app's "Search suggestions" setting; never the typing list.
    var chipQueries = [];
    try {
        chipQueries = JSON.parse(host.getAttribute('data-chips') || '[]');
    } catch (e) {
        chipQueries = [];
    }
    chipQueries = (Array.isArray(chipQueries) ? chipQueries : []).filter(function (q) {
        return typeof q === 'string' && q.trim() !== '';
    });
    var typingTimer = null;
    var typingRun = 0;
    var typingIndex = -1;

    // A random suggestion, never the one shown last - so clearing the bar does not
    // replay the same one (the Shopify app's _nextTypingIndex).
    function nextTypingIndex() {
        if (suggestions.length < 2) {
            return 0;
        }
        var next = Math.floor(Math.random() * (suggestions.length - 1));
        return next >= typingIndex ? next + 1 : next;
    }

    function stopTyping() {
        typingRun++;
        clearTimeout(typingTimer);
        if (input) {
            input.placeholder = host.getAttribute('data-label-placeholder') || 'Search products…';
        }
    }

    function startTyping() {
        stopTyping();
        if (!suggestions.length || !input || input.value) {
            return;
        }
        typingIndex = nextTypingIndex();
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            input.placeholder = suggestions[typingIndex];
            return;
        }
        var run = typingRun;
        var length = 0;
        var deleting = false;
        (function tick() {
            if (run !== typingRun) {
                return;
            }
            var text = suggestions[typingIndex];
            length += deleting ? -1 : 1;
            input.placeholder = text.slice(0, length);
            var delay = deleting ? 35 : 80;
            if (!deleting && length >= text.length) {
                deleting = true;
                delay = 1600;
            } else if (deleting && length <= 0) {
                deleting = false;
                typingIndex = nextTypingIndex();
                delay = 400;
            }
            typingTimer = setTimeout(tick, delay);
        })();
    }

    function build() {
        backdrop = document.createElement('div');
        backdrop.className = 'quissly-overlay';
        // The Shopify app's layout: wide (the default) spans the screen, center is a 760px panel.
        if (host.getAttribute('data-layout') !== 'center') {
            backdrop.classList.add('quissly-overlay--wide');
        }
        backdrop.setAttribute('role', 'dialog');
        backdrop.setAttribute('aria-modal', 'true');
        backdrop.setAttribute('aria-label', host.getAttribute('data-label-title') || 'Search');

        panel = document.createElement('div');
        panel.className = 'quissly-overlay__panel';

        var bar = document.createElement('div');
        bar.className = 'quissly-overlay__bar';

        var field = document.createElement('div');
        field.className = 'quissly-overlay__field';

        // Decorative magnifier; aria-hidden so screen readers announce only the input.
        var icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        icon.setAttribute('class', 'quissly-overlay__icon');
        icon.setAttribute('viewBox', '0 0 20 20');
        icon.setAttribute('aria-hidden', 'true');
        var ring = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        ring.setAttribute('cx', '9');
        ring.setAttribute('cy', '9');
        ring.setAttribute('r', '4.75');
        var handle = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        handle.setAttribute('d', 'm12.5 12.5 3.25 3.25');
        icon.appendChild(ring);
        icon.appendChild(handle);

        input = document.createElement('input');
        input.type = 'search';
        input.className = 'quissly-overlay__input';
        input.setAttribute('autocomplete', 'off');
        input.placeholder = host.getAttribute('data-label-placeholder') || 'Search products…';

        // Mount point the voice/image widgets attach their buttons to, so all
        // input modes live on one surface instead of floating in the header.
        var actions = document.createElement('div');
        actions.className = 'quissly-overlay__actions';
        actions.setAttribute('data-quissly-overlay-actions', '');

        field.appendChild(icon);
        field.appendChild(input);
        field.appendChild(clearButton());
        field.appendChild(actions);
        bar.appendChild(field);

        // Quick renders its suggestions here while the overlay's input is the
        // one being typed in (quissly-quick.js), under the bar instead of in a
        // floating dropdown.
        var results = document.createElement('div');
        results.className = 'quissly-overlay__results';
        results.setAttribute('data-quissly-overlay-results', '');

        panel.appendChild(bar);
        suggestPanel = buildSuggestPanel();
        if (suggestPanel) {
            panel.appendChild(suggestPanel);
        }
        panel.appendChild(results);
        backdrop.appendChild(panel);
        document.body.appendChild(backdrop);

        backdrop.addEventListener('click', function (e) {
            // The panel spans the page width, so its empty stretches are
            // backdrop too as far as the shopper is concerned.
            if (e.target === backdrop) {
                close();
            }
        });
        input.addEventListener('input', function () {
            if (input.value) {
                stopTyping();
            } else if (backdrop.classList.contains('is-open')) {
                startTyping();
            }
            syncSuggestPanel();
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submit();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                close();
            }
        });
    }

    // "Your recent searches": what this shopper searched from the overlay, newest first, at
    // most five - kept in their own browser (localStorage) and never sent anywhere; the
    // merchant's switch, on by default. Each one can be removed; a browser that blocks storage
    // simply has none.
    var RECENT_KEY = 'quissly_recent_searches';
    var RECENT_MAX = 5;
    var recentOn = host.getAttribute('data-recent-searches') === '1';
    if (!recentOn) {
        try {
            window.localStorage.removeItem(RECENT_KEY); // switched off: none are kept
        } catch (e) {
            // storage blocked: there is nothing kept to clear
        }
    }

    function readRecent() {
        if (!recentOn) {
            return [];
        }
        try {
            var list = JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]');
            return Array.isArray(list) ? list.filter(function (q) {
                return typeof q === 'string' && q.trim() !== '';
            }).slice(0, RECENT_MAX) : [];
        } catch (e) {
            return [];
        }
    }

    function writeRecent(list) {
        try {
            window.localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, RECENT_MAX)));
        } catch (e) {
            // Storage full or blocked: the search goes ahead, it is just not remembered.
        }
    }

    /** Puts q first, once (case aside). */
    function rememberSearch(q) {
        if (!recentOn) {
            return;
        }
        q = q.slice(0, 100);
        var key = q.toLowerCase();
        writeRecent([q].concat(readRecent().filter(function (old) {
            return old.toLowerCase() !== key;
        })));
    }

    function forgetSearch(q) {
        writeRecent(readRecent().filter(function (old) {
            return old !== q;
        }));
    }

    /**
     * The panel under the empty bar: "Your recent searches" and "<Store> suggestions" side by
     * side (stacked on a phone), then "<Store> Most loved". The suggestions are the merchant's
     * switch (on by default; which ones, chipQueries); the recent searches are filled in on
     * every open (renderRecent). The panel hides while it has nothing to show.
     *
     * @returns {HTMLElement}
     */
    function buildSuggestPanel() {
        var showChips = host.getAttribute('data-suggestions-panel') === '1' && chipQueries.length > 0;
        var loved = [];
        try {
            loved = JSON.parse(host.getAttribute('data-most-loved') || '[]');
        } catch (e) {
            loved = [];
        }
        loved = Array.isArray(loved) ? loved : [];
        hasStaticSuggest = showChips || loved.length > 0;
        var section = document.createElement('section');
        section.className = 'quissly-overlay__suggest';
        var sectionLabel = host.getAttribute('data-label-recent') || 'Your recent searches';
        if (showChips) {
            sectionLabel = host.getAttribute('data-label-suggestions') || 'Suggestions';
        } else if (loved.length) {
            sectionLabel = host.getAttribute('data-label-most-loved') || 'Most loved';
        }
        section.setAttribute('aria-label', sectionLabel);

        suggestLists = document.createElement('div');
        suggestLists.className = 'quissly-overlay__lists';
        recentGroup = document.createElement('div');
        recentGroup.className = 'quissly-overlay__group quissly-overlay__group--recent';
        recentGroup.setAttribute('role', 'group');
        recentGroup.setAttribute('aria-label', host.getAttribute('data-label-recent') || 'Your recent searches');
        recentGroup.hidden = true;
        suggestLists.appendChild(recentGroup);

        if (showChips) {
            var group = document.createElement('div');
            group.className = 'quissly-overlay__group';
            // A lightbulb, as the reference design.
            group.appendChild(groupTitle('M9 18h6M10 21h4M12 3a6 6 0 0 0-3.6 10.8c.7.6 1.1 1.3 1.1 2.2h5c0-.9.4-1.6 1.1-2.2A6 6 0 0 0 12 3z', host.getAttribute('data-label-suggestions') || 'Suggestions'));
            var chips = document.createElement('div');
            chips.className = 'quissly-overlay__chips';
            chipQueries.slice(0, 10).forEach(function (query) {
                var chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'quissly-overlay__chip';
                var label = document.createElement('span');
                label.textContent = query;
                chip.appendChild(label);
                chip.appendChild(svgIcon('M17 17L7 7M7 15V7h8')); // up-left: "use this search".
                chip.addEventListener('click', function () {
                    input.value = query;
                    syncClear();
                    submit();
                });
                chips.appendChild(chip);
            });
            group.appendChild(chips);
            suggestLists.appendChild(group);
        }
        section.appendChild(suggestLists);
        if (loved.length) {
            section.appendChild(buildLoved(loved));
        }
        return section;
    }

    /** Fills "Your recent searches" from this browser's list; hides it while empty. */
    function renderRecent() {
        if (!recentGroup) {
            return;
        }
        var recent = readRecent();
        recentGroup.textContent = '';
        recentGroup.hidden = !recent.length;
        suggestLists.classList.toggle('is-split', recent.length > 0 && suggestLists.children.length > 1);
        suggestLists.hidden = !recent.length && suggestLists.children.length < 2;
        if (recent.length) {
            // A clock.
            recentGroup.appendChild(groupTitle('M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18zM12 7.5V12l3 2', host.getAttribute('data-label-recent') || 'Your recent searches'));
            var chips = document.createElement('div');
            chips.className = 'quissly-overlay__chips';
            recent.forEach(function (query) {
                var chip = document.createElement('span');
                chip.className = 'quissly-overlay__chip quissly-overlay__chip--recent';
                var use = document.createElement('button');
                use.type = 'button';
                use.className = 'quissly-overlay__recent-use';
                var label = document.createElement('span');
                label.textContent = query;
                use.appendChild(label);
                use.appendChild(svgIcon('M17 17L7 7M7 15V7h8'));
                use.addEventListener('click', function () {
                    input.value = query;
                    syncClear();
                    submit();
                });
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'quissly-overlay__recent-remove';
                remove.setAttribute('aria-label', String(host.getAttribute('data-label-remove-recent') || 'Remove %s from recent searches').replace('%s', query));
                // A bin.
                remove.appendChild(svgIcon('M4.5 7h15M9.5 7V4.5h5V7M6.5 7l1 12.5h9l1-12.5M10.5 11v5M13.5 11v5'));
                remove.addEventListener('click', function () {
                    forgetSearch(query);
                    renderRecent();
                    input.focus();
                });
                chip.appendChild(use);
                chip.appendChild(remove);
                chips.appendChild(chip);
            });
            recentGroup.appendChild(chips);
        }
        syncSuggestPanel();
    }

    /**
     * A list's heading: a line icon and the text, over a hairline.
     *
     * @param {string} d the icon's path
     * @param {string} text
     * @returns {HTMLElement}
     */
    function groupTitle(d, text) {
        var title = document.createElement('p');
        title.className = 'quissly-overlay__suggest-title';
        title.appendChild(svgIcon(d));
        var heading = document.createElement('span');
        heading.textContent = text;
        title.appendChild(heading);
        return title;
    }

    /** A decorative 24x24 line icon. */
    function svgIcon(d) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        var path = document.createElementNS(ns, 'path');
        path.setAttribute('d', d);
        svg.appendChild(path);
        return svg;
    }

    /**
     * "<Store> Most loved": the merchant's picked products as cards (the Shopify app's) - image,
     * name, price, star rating and how many options. The server already left out what cannot
     * be bought and kept 8.
     *
     * @param {Array} items cards: {title, url, image, price, rating, ratingCount, variants}
     * @returns {HTMLElement}
     */
    function buildLoved(items) {
        var block = document.createElement('div');
        block.className = 'quissly-overlay__loved';
        var title = document.createElement('p');
        title.className = 'quissly-overlay__loved-title';
        title.textContent = host.getAttribute('data-label-most-loved') || 'Most loved';
        block.appendChild(title);
        var grid = document.createElement('div');
        grid.className = 'quissly-overlay__loved-grid';
        items.forEach(function (item) {
            var card = document.createElement('a');
            card.className = 'quissly-overlay__loved-card';
            card.href = /^https?:\/\//i.test(String(item.url || '')) ? item.url : '#';
            var media = document.createElement('span');
            media.className = 'quissly-overlay__loved-media';
            if (item.image && /^https?:\/\//i.test(String(item.image))) {
                var img = document.createElement('img');
                img.src = item.image;
                img.alt = '';
                img.loading = 'lazy';
                media.appendChild(img);
            }
            card.appendChild(media);
            var info = document.createElement('span');
            info.className = 'quissly-overlay__loved-info';
            var name = document.createElement('span');
            name.className = 'quissly-overlay__loved-name';
            name.textContent = item.title || '';
            info.appendChild(name);
            var meta = document.createElement('span');
            meta.className = 'quissly-overlay__loved-meta';
            var price = document.createElement('span');
            price.className = 'quissly-overlay__loved-price';
            price.textContent = item.price || '';
            meta.appendChild(price);
            var rating = Number(item.rating);
            if (rating > 0) {
                var rate = document.createElement('span');
                rate.className = 'quissly-overlay__loved-rating';
                rate.setAttribute('aria-label', String(host.getAttribute('data-label-rating') || '%1 out of 5').replace('%1', rating.toFixed(1)));
                var stars = document.createElement('span');
                stars.className = 'quissly-overlay__loved-stars';
                stars.setAttribute('aria-hidden', 'true');
                stars.textContent = '★★★★★';
                var filled = document.createElement('span');
                filled.style.width = Math.min(100, (rating / 5) * 100) + '%';
                filled.textContent = '★★★★★';
                stars.appendChild(filled);
                rate.appendChild(stars);
                if (Number(item.ratingCount) > 0) {
                    rate.appendChild(document.createTextNode('(' + Number(item.ratingCount) + ')'));
                }
                meta.appendChild(rate);
            }
            info.appendChild(meta);
            if (item.variants) {
                var variants = document.createElement('span');
                variants.className = 'quissly-overlay__loved-variants';
                variants.textContent = item.variants;
                info.appendChild(variants);
            }
            card.appendChild(info);
            grid.appendChild(card);
        });
        block.appendChild(grid);
        return block;
    }

    /** The panel shows while the bar is empty and it has something; typing hands the space to Quick. */
    function syncSuggestPanel() {
        if (suggestPanel) {
            suggestPanel.hidden = !!(input && input.value.trim()) || (!hasStaticSuggest && recentGroup.hidden);
        }
    }

    /**
     * The x inside the bar (the Quissly Shopify app's): clears the text and keeps the
     * overlay open - Escape or the backdrop closes it. Hidden while the bar is empty.
     *
     * @returns {HTMLElement}
     */
    function clearButton() {
        var ns = 'http://www.w3.org/2000/svg';
        clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'quissly-overlay__clear';
        clearBtn.setAttribute('aria-label', host.getAttribute('data-label-clear') || 'Clear search');
        clearBtn.hidden = true;
        var icon = document.createElementNS(ns, 'svg');
        icon.setAttribute('viewBox', '0 0 16 16');
        icon.setAttribute('aria-hidden', 'true');
        var cross = document.createElementNS(ns, 'path');
        cross.setAttribute('d', 'M4 4l8 8M12 4l-8 8');
        icon.appendChild(cross);
        clearBtn.appendChild(icon);
        clearBtn.addEventListener('click', function () {
            input.value = '';
            input.dispatchEvent(new Event('input'));
            input.focus();
        });
        input.addEventListener('input', syncClear);
        return clearBtn;
    }

    /** Show the x only while there is text to clear. */
    function syncClear() {
        if (clearBtn) {
            clearBtn.hidden = !input.value;
        }
    }

    function open() {
        if (!backdrop) {
            build();
        }
        lastFocus = document.activeElement;
        // Carry over whatever the shopper already typed in the native box.
        input.value = (nativeInput && nativeInput.value) || '';
        syncClear();
        document.documentElement.classList.add('quissly-overlay-open');
        backdrop.classList.add('is-open');
        renderRecent();
        input.focus();
        input.select();
        // Let Quick catch up with the carried-over text: suggestions for it,
        // or none, never the ones left from the last time the overlay was open.
        input.dispatchEvent(new Event('input', {bubbles: true}));
    }

    function close() {
        if (!backdrop) {
            return;
        }
        stopTyping();
        backdrop.classList.remove('is-open');
        document.documentElement.classList.remove('quissly-overlay-open');
        if (lastFocus && lastFocus.blur) {
            lastFocus.blur();   // do not re-trigger the overlay on return
        }
    }

    function submit() {
        var q = (input.value || '').trim();
        if (q === '') {
            return;
        }
        rememberSearch(q);
        var sep = resultsUrl.indexOf('?') === -1 ? '?' : '&';
        window.location.assign(resultsUrl + sep + 'q=' + encodeURIComponent(q));
    }

    // The merchant's toggle governs ONE thing: whether an existing search box
    // gets taken over. A theme with a box of its own and the toggle off is left
    // exactly as it was - that is the merchant declining a presentation change.
    //
    // A theme with NO search box is a different question entirely. There the
    // overlay is not decoration, it is the only way to search the shop, so it
    // is built whatever the toggle says. Switching off the immersive panel is
    // not a request to remove search from the storefront.
    var immersive = host.getAttribute('data-immersive') === '1';
    if (nativeInput && !immersive) {
        return;
    }

    // Built eagerly (hidden) so voice/image can mount into the actions slot
    // during their own initialisation.
    build();

    if (nativeInput) {
        // The theme has a search box: it becomes the trigger and never focuses.
        ['focus', 'click'].forEach(function (evt) {
            nativeInput.addEventListener(evt, function (e) {
                e.preventDefault();
                nativeInput.blur();
                open();
            });
        });
    } else {
        // The theme has NO search box. Rather than doing nothing, offer one:
        // a button beside a header control we can positively identify, with a
        // floating fallback for when that placement is hidden or does not fit.
        buildTrigger();
    }

    /**
     * Search button, used ONLY when the theme exposes no search input.
     */
    function buildTrigger() {
        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'quissly-overlay-trigger';
        trigger.setAttribute('aria-label', host.getAttribute('data-label-title') || 'Search');

        var ns = 'http://www.w3.org/2000/svg';
        var icon = document.createElementNS(ns, 'svg');
        icon.setAttribute('viewBox', '0 0 24 24');
        icon.setAttribute('aria-hidden', 'true');
        var ring = document.createElementNS(ns, 'circle');
        ring.setAttribute('cx', '11');
        ring.setAttribute('cy', '11');
        ring.setAttribute('r', '7');
        var handle = document.createElementNS(ns, 'path');
        handle.setAttribute('d', 'M20 20l-4.2-4.2');
        icon.appendChild(ring);
        icon.appendChild(handle);
        trigger.appendChild(icon);

        trigger.addEventListener('click', open);

        // Where a shopper already looks for search: the header, to the LEFT of
        // the utility controls (cart, account, language). We only ever insert
        // BESIDE an existing control we can positively identify - never into a
        // layout we are guessing about. The merchant can name that control
        // (data-mount-selector); otherwise we try the usual suspects in order.
        var anchor = findAnchor();
        if (anchor) {
            trigger.classList.add('quissly-overlay-trigger--inline');
            placeLeftOf(trigger, anchor);
        }

        // Inline placement can be invisible - most themes collapse the header
        // controls into a drawer on small screens, and a button inside a hidden
        // container is no search at all. So a floating button exists as well,
        // shown only while the inline one has no box. Re-checked on resize.
        var floating = trigger.cloneNode(true);
        floating.classList.remove('quissly-overlay-trigger--inline');
        floating.classList.add('quissly-overlay-trigger--floating');
        floating.addEventListener('click', open);
        document.body.appendChild(floating);

        function syncFallback() {
            // Inline counts as usable only when it has a box AND it fits: a
            // header can be narrow enough that the button, pushed left along
            // the row, runs into whatever sits before the row - the logo, on
            // most themes - or shoves the row itself off the right edge. Either
            // is worse than no inline button; the floating one takes over.
            // Un-hide before measuring: a button this function hid on the last
            // pass has no box, and a no-box reading would leave it hidden (or
            // shown) on stale grounds instead of re-deciding for the new width.
            trigger.hidden = false;
            var box = trigger.getBoundingClientRect();
            var inlineVisible = !!anchor && box.width > 0 && !collides(trigger, box) && !overflows(trigger, box)
                && !covered(trigger, box);
            trigger.hidden = !!anchor && box.width > 0 && !inlineVisible;
            floating.hidden = inlineVisible;
            if (!floating.hidden) {
                clearFixedBars(floating);
            }
        }

        function clearFixedBars(el) {
            // Many mobile themes pin a bar to the bottom of the screen (account /
            // search / cart icons) that sits over this corner. Under it, a tap meant
            // for search opens the theme's control instead; above it (z-index), our
            // button hides the theme's icon. So move it up to just above whatever
            // fixed element covers its spot. Re-measured from the stylesheet
            // position each time.
            el.style.bottom = '';
            var box = el.getBoundingClientRect();
            var hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
            for (var node = hit; node && node !== document.body; node = node.parentElement) {
                if (node === el) {
                    return;
                }
                if (window.getComputedStyle(node).position === 'fixed') {
                    var bar = node.getBoundingClientRect();
                    el.style.bottom = Math.max(0, window.innerHeight - bar.top) + 12 + 'px';
                    return;
                }
            }
        }

        function covered(el, box) {
            // Having a box isn't being seen: a collapsed mobile menu (the cart link
            // we sit beside often lives in one) clips or overlays its contents while
            // they keep their size, so the shopper has no search button at all.
            // Hit-test the centre; only judge a button inside the viewport - off-
            // screen (e.g. the header scrolled away) says nothing about whether it
            // is hidden.
            var x = box.left + box.width / 2;
            var y = box.top + box.height / 2;
            if (x < 0 || y < 0 || x >= window.innerWidth || y >= window.innerHeight) {
                return false;
            }
            var hit = document.elementFromPoint(x, y);
            return !hit || (hit !== el && !el.contains(hit));
        }

        function overflows(el, box) {
            // The other way a tight header breaks: nothing overlaps, the row
            // simply grows past the viewport and the cart is pushed off-screen.
            var edge = document.documentElement.clientWidth + 1;
            var row = el.parentNode.getBoundingClientRect();
            return box.right > edge || row.right > edge;
        }

        function collides(el, box) {
            // The nearest visible element BEFORE the button's row, in DOM order.
            var row = el.parentNode;
            var prev = row && row.previousElementSibling;
            while (prev && prev.getBoundingClientRect().width === 0) {
                prev = prev.previousElementSibling;
            }
            if (!prev) {
                return false;
            }
            var p = prev.getBoundingClientRect();
            var sameLine = box.top < p.bottom && box.bottom > p.top;
            // Real overlap only (1px of sub-pixel tolerance): the inline margin
            // already keeps the gap, so a touching edge is a fit, not a clash.
            return sameLine && box.left < p.right - 1;
        }
        syncFallback();
        // The first pass can run before the page has settled (styles, web fonts,
        // images still moving the header), when a hit-test sees whatever was there
        // a moment ago. Decide again once everything has loaded.
        if (document.readyState !== 'complete') {
            window.addEventListener('load', syncFallback);
        }
        var resizeTimer = null;
        window.addEventListener('resize', function () {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(syncFallback, 150);
        });
    }

    /**
     * The header control the search button should sit beside, or null.
     *
     * Order: the merchant's own selector; then the leftmost visible of the
     * cart / account / language switcher that share a container with the cart
     * (so search leads the utility row rather than splitting it); then the
     * account link or language switcher on their own.
     *
     * @returns {Element|null}
     */
    function findAnchor() {
        var custom = (host.getAttribute('data-mount-selector') || '').trim();
        if (custom) {
            try {
                var el = document.querySelector(custom);
                if (el && el.parentNode) {
                    return el;
                }
            } catch (e) {
                // An invalid selector is a merchant typo, not a reason to draw nothing.
            }
        }

        var CART = '[data-block="minicart"], .minicart-wrapper, .action.showcart, [data-role="minicart"]';
        var ACCOUNT = '.account-link, .customer-welcome, .header .authorization-link, [data-block="customer-menu"], .header-account';
        var LANGUAGE = '.switcher-language, #switcher-language, .language-switcher, [data-ui-id="language-switcher"]';

        // First VISIBLE match, not first in document order: themes routinely
        // ship a hidden mobile minicart ahead of the visible desktop one, and
        // a button placed beside a hidden control is no search at all.
        function firstVisible(selector) {
            var all = document.querySelectorAll(selector);
            for (var i = 0; i < all.length; i++) {
                if (all[i].getBoundingClientRect().width > 0) {
                    return all[i];
                }
            }
            return all[0] || null;
        }

        var cart = firstVisible(CART);
        if (cart && cart.parentNode) {
            // Everything in the cart's row, leftmost first, so the button leads
            // the whole group instead of wedging between two of its icons.
            var row = cart.parentNode;
            var siblings = [].slice.call(row.querySelectorAll(ACCOUNT + ', ' + LANGUAGE + ', ' + CART))
                .filter(function (el) { return el.parentNode === row && el.getBoundingClientRect().width > 0; })
                .sort(function (a, b) { return a.getBoundingClientRect().left - b.getBoundingClientRect().left; });
            return siblings[0] || cart;
        }

        return firstVisible(ACCOUNT) || firstVisible(LANGUAGE);
    }

    /**
     * Insert the button so it renders to the visual LEFT of the anchor.
     *
     * DOM order alone cannot promise that: in a flex or inline header "before"
     * is left, but Luma floats its header controls right, where floated
     * siblings lay out right-to-left and "before" is RIGHT. So insert, measure,
     * and move to the other side if it landed wrong.
     *
     * @param {Element} trigger
     * @param {Element} anchor
     */
    function placeLeftOf(trigger, anchor) {
        // A float-based header (Luma) lays its controls out with float: right.
        // A non-floated button dropped in beside them leaves the float flow
        // entirely and ends up in normal flow at the far left of the container,
        // stranded next to the logo. Adopt the anchor's float so the button
        // stays in the same flow; measuring below then settles which side.
        var anchorFloat = window.getComputedStyle(anchor).cssFloat || window.getComputedStyle(anchor).float;
        if (anchorFloat === 'right' || anchorFloat === 'left') {
            trigger.style.cssFloat = anchorFloat;
        }

        anchor.parentNode.insertBefore(trigger, anchor);
        var a = anchor.getBoundingClientRect();
        var t = trigger.getBoundingClientRect();
        if (t.width > 0 && a.width > 0 && t.left > a.left) {
            anchor.parentNode.insertBefore(trigger, anchor.nextSibling);
        }
    }

    // Keyboard shortcut: "/" opens search, the way search-first sites do.
    document.addEventListener('keydown', function (e) {
        var tag = (e.target && e.target.tagName) || '';
        if (e.key === '/' && tag !== 'INPUT' && tag !== 'TEXTAREA') {
            e.preventDefault();
            open();
        }
    });
})();
