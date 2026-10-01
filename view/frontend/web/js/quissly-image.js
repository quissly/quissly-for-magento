/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Image search widget: framework-free vanilla JS (Luma + Hyvä).
 * Click the camera → pick/snap a photo (accept="image/*" opens the camera on
 * mobile) → downscale to longest-edge 1024 on a canvas → JPEG 0.85 → base64 →
 * POST to the module's anonymous proxy → follow the redirect (results render
 * from a single-use token). Every failure degrades to a brief inline notice -
     * never a broken search box. No credentials touch the browser; the proxy signs.
 */
(function () {
    'use strict';

    var MAX_EDGE = 1024;
    var JPEG_QUALITY = 0.85;

    var host = document.querySelector('[data-quissly-image-endpoint]');
    if (!host || !window.FileReader || !window.HTMLCanvasElement) {
        return; // No config, or a browser without canvas/FileReader: render nothing.
    }
    var endpoint = host.getAttribute('data-quissly-image-endpoint');
    // The overlay owns every input mode when it is on; only fall back to the
    // header when there is no overlay to live in (never render both).
    var overlaySlot = document.querySelector('[data-quissly-overlay-actions]');
    var searchForm = document.getElementById('search_mini_form') ||
        document.querySelector('form[action*="catalogsearch"]');
    var searchInput = document.getElementById('search') ||
        (searchForm ? searchForm.querySelector('input[type="text"], input[type="search"]') : null);
    // With the overlay on, the bar IS the mount point - a theme with no search
    // box of its own must not stop voice/image from appearing inside it.
    if (!overlaySlot && (!searchForm || !searchInput)) {
        return;
    }

    // An image search carries no words, but Magento will not route a search
    // without a term, and the interception guard rejects anything under two
    // characters before the token hand-off is even reached - so the results URL
    // has to carry a placeholder q. Showing that placeholder in the search box
    // is wrong: the shopper searched with a picture and never typed it.
    // Blank the box on arrival, leaving the URL (and therefore the routing and
    // the hand-off) exactly as it was.
    if (searchInput && window.location.search.indexOf('quissly_img=') !== -1) {
        searchInput.value = '';
    }


    /**
     * Build a stroked icon matching the overlay's magnifier, so every control
     * on the bar reads as one set instead of mixed emoji.
     *
     * @param {string[]} paths SVG path "d" values
     * @param {string[]} circles "cx,cy,r" triples
     * @returns {SVGElement}
     */
    function buildIcon(paths, circles) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.9');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        (circles || []).forEach(function (c) {
            var parts = c.split(',');
            var el = document.createElementNS(ns, 'circle');
            el.setAttribute('cx', parts[0]);
            el.setAttribute('cy', parts[1]);
            el.setAttribute('r', parts[2]);
            svg.appendChild(el);
        });
        (paths || []).forEach(function (d) {
            var el = document.createElementNS(ns, 'path');
            el.setAttribute('d', d);
            svg.appendChild(el);
        });
        return svg;
    }

    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.className = 'quissly-image-input';
    input.style.display = 'none';

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'quissly-image-button';
    button.setAttribute('aria-label', host.getAttribute('data-label-start') || 'Search by photo');
    button.appendChild(buildIcon(
        ['M3.5 8.8h3.2l1.5-2.3h7.6l1.5 2.3h3.2v9.7H3.5z'],
        ['12,13.4,3.1']
    ));

    var notice = document.createElement('span');
    notice.className = 'quissly-image-notice';
    notice.setAttribute('role', 'status');

    if (overlaySlot) {
        overlaySlot.appendChild(button);
        overlaySlot.appendChild(notice);
        overlaySlot.appendChild(input);
    } else {
        searchInput.insertAdjacentElement('afterend', button);
        button.insertAdjacentElement('afterend', notice);
        searchForm.appendChild(input);
    }

    function say(text) {
        notice.textContent = text || '';
    }

    function label(name, fallback) {
        return host.getAttribute(name) || fallback;
    }

    button.addEventListener('click', function () {
        say('');
        input.click();
    });

    /**
     * Take one image file from any source and run the search.
     *
     * Shared by the file picker and the paste handler so both go through the
     * same downscale-and-submit path; a second copy would drift.
     */
    function useFile(file) {
        if (!file) {
            return;
        }
        if (file.type && file.type.indexOf('image/') !== 0) {
            say(label('data-label-notimage', 'That file is not an image.'));
            return;
        }
        say(label('data-label-reading', 'Reading photo…'));
        shrink(file, function (base64) {
            if (!base64) {
                say(label('data-label-unreadable', 'Could not read that photo.'));
                return;
            }
            say(label('data-label-searching', 'Searching…'));
            submit(base64);
        });
    }

    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        input.value = ''; // allow re-picking the same file
        useFile(file);
    });

    /**
     * Whether a paste landed somewhere we own.
     *
     * Bound on the document rather than one input because the overlay replaces
     * the header box with its own field, so binding to the theme's input alone
     * would miss the very surface the shopper is typing into. Scoped so that
     * pasting into anything else on the page - a coupon box, a review, an
     * address - is never hijacked.
     */
    function ownsPasteTarget(target) {
        if (!target || target === document || target === document.body) {
            return true; // nothing focused: the shopper is just on the page
        }
        if (target === searchInput) {
            return true;
        }
        return !!(target.classList && target.classList.contains('quissly-overlay__input'));
    }

    // Paste an image straight in (a screenshot, or "copy image" from another
    // page). Only acts when the clipboard actually carries an image: pasting
    // ordinary text must still type into the search box, so a clipboard without
    // an image is left entirely alone - no preventDefault, no notice, nothing.
    document.addEventListener('paste', function (event) {
        if (!ownsPasteTarget(event.target)) {
            return;
        }
        var items = (event.clipboardData || window.clipboardData || {}).items;
        if (!items) {
            return;
        }
        var file = null;
        for (var i = 0; i < items.length && !file; i++) {
            if (items[i].kind === 'file' && String(items[i].type).indexOf('image/') === 0) {
                file = items[i].getAsFile();
            }
        }
        if (!file) {
            return; // plain text paste - leave the search box to do its job
        }
        event.preventDefault();
        say('');
        useFile(file);
    });

    /**
     * Downscale to longest-edge MAX_EDGE and return bare base64 JPEG.
     */
    function shrink(file, done) {
        var reader = new FileReader();
        reader.onerror = function () { done(null); };
        reader.onload = function () {
            var img = new Image();
            img.onerror = function () { done(null); };
            img.onload = function () {
                var w = img.naturalWidth || img.width;
                var h = img.naturalHeight || img.height;
                if (!w || !h) {
                    done(null);
                    return;
                }
                var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(w * scale);
                canvas.height = Math.round(h * scale);
                try {
                    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                    var url = canvas.toDataURL('image/jpeg', JPEG_QUALITY);
                    done(url.substring(url.indexOf(',') + 1)); // strip the data: prefix
                } catch (e) {
                    done(null);
                }
            };
            img.src = reader.result;
        };
        reader.readAsDataURL(file);
    }

    function submit(base64) {
        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ media: base64 })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.status === 'ok' && data.redirect) {
                window.location.assign(data.redirect);
                return;
            }
            say(data && data.status === 'empty'
                ? label('data-label-empty', 'No similar products found.')
                : label('data-label-unavailable', 'Image search is unavailable right now.'));
        }).catch(function () {
            say(label('data-label-unavailable', 'Image search is unavailable right now.'));
        });
    }
})();
