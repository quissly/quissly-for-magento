/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Shopping activity for Quissly's analytics: sends this page's one event (a product page
 * view or a search, rendered by events.phtml) to the store's own /quissly/events/collect.
 * Who the shopper is, and whether they accepted the cookie notice, is the store's to decide
 * there - this only says what was viewed or searched.
 *
 * A search is sent once per tab: reloading the results page, or coming back to it, is not
 * another search.
 */
(function () {
    'use strict';

    var host = document.querySelector('[data-quissly-events]');
    if (!host) {
        return;
    }
    var endpoint = host.getAttribute('data-quissly-events');
    var event;
    try {
        event = JSON.parse(host.getAttribute('data-event') || 'null');
    } catch (e) {
        return;
    }
    if (!endpoint || !event || !event.type) {
        return;
    }

    if (event.type === 'search') {
        var key = 'quissly_search_sent';
        var id = event.modality + ':' + (event.modality === 'text' ? event.query : location.search);
        try {
            if (sessionStorage.getItem(key) === id) {
                return;
            }
            sessionStorage.setItem(key, id);
        } catch (e) {
            // Storage blocked: send it anyway.
        }
    }

    var body = JSON.stringify(event);
    try {
        if (navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([body], {type: 'application/json'}))) {
            return;
        }
    } catch (e) {
        // Fall through to fetch.
    }
    try {
        fetch(endpoint, {
            method: 'POST',
            body: body,
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            keepalive: true
        });
    } catch (e) {
        // Analytics never gets in the shopper's way.
    }
}());
