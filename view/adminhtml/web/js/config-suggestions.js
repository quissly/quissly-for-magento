/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Configuration > Quissly > Search bar suggestions: one list per store language, edited as pills
 * (the Shopify app's Settings: a Language select, the list, "Add a suggestion", "N of 20",
 * "Reset to generated"). Every list travels in ONE hidden field as JSON {language: [queries]};
 * Model/Config/Backend/SearchSuggestions writes the lists that changed to Quissly.
 *
 * Config (JSON in data-config on [data-q-suggestions], built by SearchSuggestionsField):
 * {main, max, maxLength, languages: [{code, label, queries, generated, note}], text: {...}}.
 * Vanilla, no framework.
 */
(function () {
    'use strict';

    function init(root) {
        var config;
        try {
            config = JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (e) {
            return;
        }
        var text = config.text || {};
        var lists = {};
        var byCode = {};
        (config.languages || []).forEach(function (language) {
            lists[language.code] = (language.queries || []).slice();
            byCode[language.code] = language;
        });
        var current = config.main;

        var field = root.querySelector('[data-q-value]');
        var select = root.querySelector('[data-q-language]');
        var pills = root.querySelector('[data-q-pills]');
        var input = root.querySelector('[data-q-new]');
        var add = root.querySelector('[data-q-add]');
        var count = root.querySelector('[data-q-count]');
        var reset = root.querySelector('[data-q-reset]');
        var note = root.querySelector('[data-q-note]');
        var error = root.querySelector('[data-q-error]');

        function key(query) {
            return query.trim().replace(/\s+/g, ' ').toLowerCase();
        }

        function store() {
            field.value = JSON.stringify(lists);
        }

        function render() {
            var list = lists[current] || [];
            pills.textContent = '';
            list.forEach(function (query, index) {
                var pill = document.createElement('span');
                pill.className = 'q-pill';
                var label = document.createElement('span');
                label.className = 'q-pill__text';
                label.textContent = query;
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'q-pill__remove';
                remove.setAttribute('aria-label', (text.remove || 'Remove') + ': ' + query);
                remove.textContent = '×';
                remove.addEventListener('click', function () {
                    list.splice(index, 1);
                    render();
                    input.focus();
                });
                pill.appendChild(label);
                pill.appendChild(remove);
                pills.appendChild(pill);
            });
            if (!list.length) {
                var empty = document.createElement('span');
                empty.className = 'q-pills__empty';
                empty.textContent = current === config.main ? (text.emptyMain || '') : (text.emptyOther || '');
                pills.appendChild(empty);
            }
            count.textContent = (text.count || '%1 of %2').split('%1').join(list.length).split('%2').join(config.max);
            count.classList.toggle('is-over', list.length > config.max);
            add.disabled = list.length >= config.max;
            var language = byCode[current] || {};
            var generated = language.generated || [];
            reset.hidden = !generated.length || JSON.stringify(generated) === JSON.stringify(list);
            note.textContent = !list.length && language.note ? language.note : '';
            note.hidden = note.textContent === '';
            error.hidden = true;
            store();
        }

        function addQuery() {
            var query = input.value.trim().replace(/\s+/g, ' ');
            var list = lists[current] || (lists[current] = []);
            if (query === '') {
                return;
            }
            if (query.length > config.maxLength) {
                error.textContent = (text.tooLong || '').split('%1').join(config.maxLength);
                error.hidden = false;
                return;
            }
            if (list.some(function (q) { return key(q) === key(query); })) {
                error.textContent = text.duplicate || '';
                error.hidden = false;
                return;
            }
            if (list.length >= config.max) {
                return;
            }
            list.push(query);
            input.value = '';
            render();
            input.focus();
        }

        add.addEventListener('click', addQuery);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                // Enter adds the suggestion; it must not submit the whole Configuration form.
                event.preventDefault();
                addQuery();
            }
        });
        reset.addEventListener('click', function () {
            lists[current] = (byCode[current].generated || []).slice();
            render();
        });
        if (select) {
            select.addEventListener('change', function () {
                current = select.value;
                render();
            });
        }
        render();
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-q-suggestions]'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
