/*!
 * Virtual tour embed loader.
 *
 * Usage:
 *   <div data-vt-tour="TOUR_ID" data-height="600"></div>
 *   <script async src="https://YOUR-HOST/embed.js"></script>
 *
 * The script finds every [data-vt-tour] element on the page and fills it with
 * the tour. It works on any site, adds no dependencies, and can be served from
 * a CDN: it only reads its own URL to learn where the tours live.
 *
 * Options (data attributes on the element):
 *   data-height   Height in pixels (default 600).
 *   data-ratio    Use an aspect ratio instead of a fixed height, e.g. "16/9".
 *   data-title    Accessible title for the frame.
 *   data-radius   Corner radius in pixels (default 0).
 */
(function (window, document) {
    'use strict';

    if (window.VirtualTour && window.VirtualTour.__loaded) {
        window.VirtualTour.init();
        return;
    }

    var script = document.currentScript;

    if (!script) {
        var scripts = document.getElementsByTagName('script');

        for (var i = scripts.length - 1; i >= 0; i--) {
            if (/\/embed\.js(\?|$)/.test(scripts[i].src)) {
                script = scripts[i];
                break;
            }
        }
    }

    // Tours live on the same origin that serves this file.
    var origin = script && script.src ? new URL(script.src).origin : '';

    function build(el) {
        if (el.getAttribute('data-vt-ready')) {
            return;
        }

        var id = (el.getAttribute('data-vt-tour') || '').trim();

        if (!/^[a-z0-9]{12}$/.test(id) || !origin) {
            return;
        }

        el.setAttribute('data-vt-ready', '1');

        var url = origin + '/t/' + id + '?embed=1';
        var frame = document.createElement('iframe');
        frame.src = url;
        frame.title = el.getAttribute('data-title') || 'Virtual tour';
        frame.loading = 'lazy';
        frame.setAttribute(
            'allow',
            'fullscreen; gyroscope; accelerometer; xr-spatial-tracking',
        );
        frame.setAttribute('allowfullscreen', '');
        frame.style.cssText =
            'display:block;width:100%;border:0;max-width:100%;';
        frame.style.borderRadius =
            (parseInt(el.getAttribute('data-radius') || '0', 10) || 0) + 'px';

        var ratio = el.getAttribute('data-ratio');

        if (ratio && /^\d+(\.\d+)?\s*\/\s*\d+(\.\d+)?$/.test(ratio)) {
            frame.style.aspectRatio = ratio.replace(/\s+/g, '');
            frame.style.height = 'auto';
        } else {
            frame.style.height =
                (parseInt(el.getAttribute('data-height') || '600', 10) || 600) +
                'px';
        }

        el.innerHTML = '';
        el.appendChild(frame);
    }

    function init() {
        var nodes = document.querySelectorAll('[data-vt-tour]');

        for (var i = 0; i < nodes.length; i++) {
            build(nodes[i]);
        }
    }

    window.VirtualTour = { __loaded: true, init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Pick up embeds added later (single page apps, page builders).
    if (window.MutationObserver) {
        new MutationObserver(init).observe(document.documentElement, {
            childList: true,
            subtree: true,
        });
    }
})(window, document);
