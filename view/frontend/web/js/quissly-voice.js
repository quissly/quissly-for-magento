/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Voice search widget: framework-free vanilla JS (Luma + Hyvä).
 * Click the mic → record up to 5 s (click again to stop early) → downsample to
 * 16 kHz mono RIFF WAV → base64 → POST to the module's anonymous proxy → follow
 * the redirect (results render via the single-use token; the transcription is
 * the visible query). All failures degrade to a brief inline notice - never a
 * broken search box. No credentials touch the browser; the proxy signs.
 */
(function () {
    'use strict';

    var MAX_SECONDS = 5;
    var TARGET_RATE = 16000;

    var host = document.querySelector('[data-quissly-voice-endpoint]');
    if (!host || !navigator.mediaDevices || !window.AudioContext) {
        return; // No endpoint config or no mic support: render nothing.
    }
    var endpoint = host.getAttribute('data-quissly-voice-endpoint');
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

    var button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('aria-label', host.getAttribute('data-label-start') || 'Search by voice');
    button.className = 'quissly-voice-button';
    button.appendChild(buildIcon(
        ['M12 3.5a2.6 2.6 0 0 1 2.6 2.6v5a2.6 2.6 0 0 1-5.2 0v-5A2.6 2.6 0 0 1 12 3.5z',
            'M5.5 11.2a6.5 6.5 0 0 0 13 0', 'M12 17.7V20.5', 'M9 20.5h6'],
        []
    ));

    var notice = document.createElement('span');
    notice.className = 'quissly-voice-notice';
    notice.setAttribute('role', 'status');

    if (overlaySlot) {
        overlaySlot.appendChild(button);
        overlaySlot.appendChild(notice);
    } else {
        searchInput.insertAdjacentElement('afterend', button);
        button.insertAdjacentElement('afterend', notice);
    }

    var state = { recording: false, stream: null, context: null, source: null, processor: null, chunks: [], rate: 44100, timer: null };

    function say(text) {
        notice.textContent = text || '';
    }

    function cleanup() {
        if (state.timer) {
            clearTimeout(state.timer);
        }
        if (state.processor) {
            state.processor.disconnect();
        }
        if (state.source) {
            state.source.disconnect();
        }
        if (state.stream) {
            state.stream.getTracks().forEach(function (t) { t.stop(); });
        }
        if (state.context) {
            state.context.close();
        }
        state = { recording: false, stream: null, context: null, source: null, processor: null, chunks: [], rate: 44100, timer: null };
        button.classList.remove('recording');
    }

    function start() {
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            state.stream = stream;
            state.context = new window.AudioContext();
            state.rate = state.context.sampleRate;
            state.source = state.context.createMediaStreamSource(stream);
            // ScriptProcessor is deprecated but universally supported without
            // extra worklet files - right tradeoff for a tiny CSP-safe widget.
            state.processor = state.context.createScriptProcessor(4096, 1, 1);
            state.processor.onaudioprocess = function (e) {
                state.chunks.push(new Float32Array(e.inputBuffer.getChannelData(0)));
            };
            state.source.connect(state.processor);
            state.processor.connect(state.context.destination);
            state.recording = true;
            button.classList.add('recording');
            say(host.getAttribute('data-label-listening') || 'Listening…');
            state.timer = setTimeout(stop, MAX_SECONDS * 1000);
        }).catch(function () {
            say(host.getAttribute('data-label-denied') || 'Microphone unavailable.');
        });
    }

    function stop() {
        if (!state.recording) {
            return;
        }
        var chunks = state.chunks, rate = state.rate;
        cleanup();
        say(host.getAttribute('data-label-searching') || 'Searching…');
        var wav = encodeWav(downsample(merge(chunks), rate, TARGET_RATE), TARGET_RATE);
        submit(btoa(binary(wav)));
    }

    function merge(chunks) {
        var length = chunks.reduce(function (n, c) { return n + c.length; }, 0);
        var out = new Float32Array(length);
        var offset = 0;
        chunks.forEach(function (c) {
            out.set(c, offset);
            offset += c.length;
        });
        return out;
    }

    function downsample(samples, fromRate, toRate) {
        if (toRate >= fromRate) {
            return samples;
        }
        var ratio = fromRate / toRate;
        var out = new Float32Array(Math.floor(samples.length / ratio));
        for (var i = 0; i < out.length; i++) {
            out[i] = samples[Math.floor(i * ratio)];
        }
        return out;
    }

    function encodeWav(samples, rate) {
        var buffer = new ArrayBuffer(44 + samples.length * 2);
        var v = new DataView(buffer);
        var writeString = function (offset, s) {
            for (var i = 0; i < s.length; i++) {
                v.setUint8(offset + i, s.charCodeAt(i));
            }
        };
        writeString(0, 'RIFF');
        v.setUint32(4, 36 + samples.length * 2, true);
        writeString(8, 'WAVE');
        writeString(12, 'fmt ');
        v.setUint32(16, 16, true);
        v.setUint16(20, 1, true);   // PCM
        v.setUint16(22, 1, true);   // mono
        v.setUint32(24, rate, true);
        v.setUint32(28, rate * 2, true);
        v.setUint16(32, 2, true);
        v.setUint16(34, 16, true);
        writeString(36, 'data');
        v.setUint32(40, samples.length * 2, true);
        for (var i = 0; i < samples.length; i++) {
            var s = Math.max(-1, Math.min(1, samples[i]));
            v.setInt16(44 + i * 2, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
        }
        return buffer;
    }

    function binary(buffer) {
        var bytes = new Uint8Array(buffer);
        var out = '';
        for (var i = 0; i < bytes.length; i += 8192) {
            out += String.fromCharCode.apply(null, bytes.subarray(i, i + 8192));
        }
        return out;
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
                ? (host.getAttribute('data-label-empty') || 'Nothing found - try again.')
                : (host.getAttribute('data-label-unavailable') || 'Voice search is unavailable right now.'));
        }).catch(function () {
            say(host.getAttribute('data-label-unavailable') || 'Voice search is unavailable right now.');
        });
    }

    button.addEventListener('click', function () {
        say('');
        if (state.recording) {
            stop();
        } else {
            start();
        }
    });
})();
