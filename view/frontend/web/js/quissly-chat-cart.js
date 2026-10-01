/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 *
 * QChat "Add to Cart" -> the Magento cart (port of the CS-Cart / WooCommerce
 * plugins' bridge). Framework-free vanilla, so Luma and Hyvä both work; config
 * from [data-quissly-chat-cart].
 *
 * The chat widget's generic cart only counts inside the widget and announces
 * each change as `quissly:generic-cart-sync` (detail = { quisslyProductId: qty }),
 * where the ids are Quissly's own: uuid5(qsearch quissly_service_link, product id).
 * This applies the difference from the previous event to the real cart:
 *  - ids are translated with /quissly/cart/resolve; an id the store cannot
 *    translate is left alone (never guessed); a plain number is a Magento id;
 *  - increases post Magento's own /checkout/cart/add (what the product page
 *    sends). When Magento answers with a backUrl (a configurable product whose
 *    options are not chosen), the shopper is taken there;
 *  - decreases and removals use the mini-cart's own endpoints,
 *    /checkout/sidebar/updateItemQty and /checkout/sidebar/removeItem, on the
 *    cart lines read from Magento's `cart` customer section;
 *  - then the mini-cart is refreshed: Luma's customer-data when it is on the
 *    page, and Hyvä's `reload-customer-section-data` event.
 */
(function () {
    'use strict';

    var host = document.querySelector('[data-quissly-chat-cart]');
    if (!host || !window.fetch) {
        return;
    }
    var CFG = {
        resolveUrl: host.getAttribute('data-resolve-url') || '',
        addUrl: host.getAttribute('data-add-url') || '',
        updateUrl: host.getAttribute('data-update-url') || '',
        removeUrl: host.getAttribute('data-remove-url') || '',
        sectionUrl: host.getAttribute('data-section-url') || ''
    };
    var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    var last = {};
    var resolved = {};
    var queue = Promise.resolve();

    function formKey() {
        var m = document.cookie.match(/(?:^|;\s*)form_key=([^;]+)/);
        if (m) {
            return decodeURIComponent(m[1]);
        }
        var input = document.querySelector('input[name="form_key"]');
        return input ? input.value : '';
    }

    function post(url, fields) {
        var body = new URLSearchParams();
        body.set('form_key', formKey());
        Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        }).then(function (r) { return r.json(); }).catch(function () { return null; });
    }

    /** Tell the theme's mini-cart the cart changed. */
    function refreshMiniCart() {
        if (typeof window.require === 'function') {
            window.require(['Magento_Customer/js/customer-data'], function (customerData) {
                customerData.invalidate(['cart']);
                customerData.reload(['cart', 'messages'], true);
            });
        }
        window.dispatchEvent(new CustomEvent('reload-customer-section-data'));
    }

    function add(productId, qty) {
        return post(CFG.addUrl, { product: productId, qty: qty }).then(function (response) {
            if (response && response.backUrl) {
                window.location.href = response.backUrl; // options to choose first
                return;
            }
            refreshMiniCart();
        });
    }

    /** The cart lines for a product, fresh from Magento's own cart section. */
    function cartLines(productId) {
        var url = CFG.sectionUrl + (CFG.sectionUrl.indexOf('?') === -1 ? '?' : '&')
            + 'sections=cart&force_new_section_timestamp=true&_=' + Date.now();
        return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var items = (data && data.cart && data.cart.items) || [];
                return items.filter(function (item) { return String(item.product_id) === String(productId); });
            })
            .catch(function () { return []; });
    }

    function reduce(productId, qty) {
        return cartLines(productId).then(function (lines) {
            var chain = Promise.resolve();
            lines.reverse().forEach(function (item) {
                if (qty <= 0) {
                    return;
                }
                var have = parseInt(item.qty, 10) || 0;
                var take = Math.min(have, qty);
                qty -= take;
                chain = chain.then(function () {
                    return have - take > 0
                        ? post(CFG.updateUrl, { item_id: item.item_id, item_qty: have - take })
                        : post(CFG.removeUrl, { item_id: item.item_id });
                });
            });
            return chain.then(refreshMiniCart);
        });
    }

    function resolve(ids) {
        var unknown = ids.filter(function (id) { return UUID.test(id) && !(id.toLowerCase() in resolved); });
        if (!unknown.length) {
            return Promise.resolve();
        }
        var url = CFG.resolveUrl + (CFG.resolveUrl.indexOf('?') === -1 ? '?' : '&') + 'ids=' + encodeURIComponent(unknown.join(','));
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (map) {
                unknown.forEach(function (id) { resolved[id.toLowerCase()] = (map && map[id.toLowerCase()]) || null; });
            }, function () {});
    }

    function productId(id) {
        if (/^[0-9]+$/.test(id)) {
            return id;
        }
        var found = resolved[String(id).toLowerCase()];
        return found ? String(found) : null;
    }

    window.addEventListener('quissly:generic-cart-sync', function (event) {
        var now = (event && event.detail) || {};
        var deltas = {};
        Object.keys(last).concat(Object.keys(now)).forEach(function (id) {
            var delta = (parseInt(now[id], 10) || 0) - (parseInt(last[id], 10) || 0);
            if (delta !== 0) {
                deltas[id] = delta;
            }
        });
        last = {};
        Object.keys(now).forEach(function (id) { last[id] = parseInt(now[id], 10) || 0; });

        // One change at a time, in order: the quote lives in the session.
        queue = queue.then(function () { return resolve(Object.keys(deltas)); }).then(function () {
            return Object.keys(deltas).reduce(function (chain, id) {
                var pid = productId(id);
                if (!pid) {
                    if (window.console) {
                        console.warn('[quissly] chat product ' + id + ' is not known to this store');
                    }
                    return chain;
                }
                return chain.then(function () {
                    return deltas[id] > 0 ? add(pid, deltas[id]) : reduce(pid, -deltas[id]);
                });
            }, Promise.resolve());
        });
    });
}());
