/* global cachePartyLoader */
(function () {
    'use strict';

    var loaded = false;

    window.addEventListener('load', function () {

        var range = document.createRange();
        range.selectNode(document.getElementsByTagName('head').item(0));

        /**
         * Execute delayed scripts sequentially.
         * External scripts wait for onload before proceeding.
         */
        var doDelayed = async function (id) {
            var noscript = document.getElementById(id);
            if (!noscript) return;

            var scripts = range.createContextualFragment(noscript.textContent);

            while (scripts.firstElementChild) {
                try {
                    if (scripts.firstElementChild.src) {
                        await new Promise(function (resolve, reject) {
                            scripts.firstElementChild.onload = resolve;
                            scripts.firstElementChild.onerror = reject;
                            document.body.appendChild(scripts.firstElementChild);
                        });
                    } else {
                        document.body.appendChild(scripts.firstElementChild);
                    }
                } catch (e) {
                    // Silently continue on script errors.
                }
            }
        };

        /**
         * Load all deferred assets: styles, scripts, lazy iframes.
         */
        var loadDeferred = function () {
            if (loaded) return;
            loaded = true;

            // Scroll state tracking.
            requestAnimationFrame(function checkScroll() {
                if (window.pageYOffset > 30) {
                    document.body.classList.remove('cp-not-scrolled');
                    document.body.classList.add('cp-scrolled');
                    // Legacy class support.
                    document.body.classList.remove('aoc-not_scrolled');
                    document.body.classList.add('aoc-scrolled');
                } else {
                    requestAnimationFrame(checkScroll);
                }
            });

            // Inject deferred styles.
            var deferredStyles = document.getElementById('deferred-styles');
            if (deferredStyles) {
                document.body.appendChild(range.createContextualFragment(deferredStyles.textContent));
            }

            // Execute delayed scripts.
            doDelayed('delayed-scripts');

            // Swap lazy-loaded script sources.
            document.querySelectorAll('script[data-lazy-src]').forEach(function (el) {
                el.setAttribute('src', el.getAttribute('data-lazy-src'));
                el.setAttribute('data-lazyloaded', '1');
                el.removeAttribute('data-lazy-src');
            });

            // Swap lazy-loaded iframe sources.
            document.querySelectorAll('iframe[data-lazy-src]').forEach(function (el) {
                el.setAttribute('src', el.getAttribute('data-lazy-src'));
                el.setAttribute('data-lazyloaded', '1');
                el.removeAttribute('data-lazy-src');
            });
        };

        // Trigger on first interaction.
        document.addEventListener('mousemove', loadDeferred, { once: true });

        document.addEventListener('scroll', function () {
            if (window.pageYOffset > 30) {
                loadDeferred();
            }
        }, { once: true, passive: true });

        document.addEventListener('touchstart', loadDeferred, { once: true, passive: true });

        document.addEventListener('keydown', loadDeferred, { once: true });

        // requestIdleCallback fallback: if no interaction after N seconds, load anyway.
        // Read config from data attribute on our script tag, or fall back to global/default.
        var selfScript = document.querySelector('script[data-idle-timeout]');
        var idleTimeout = selfScript
            ? parseInt(selfScript.getAttribute('data-idle-timeout'), 10) * 1000
            : (typeof cachePartyLoader !== 'undefined' && cachePartyLoader.idleTimeout)
                ? parseInt(cachePartyLoader.idleTimeout, 10) * 1000
                : 5000;

        if (idleTimeout > 0) {
            if (typeof requestIdleCallback === 'function') {
                requestIdleCallback(function () {
                    setTimeout(loadDeferred, idleTimeout);
                });
            } else {
                setTimeout(loadDeferred, idleTimeout);
            }
        }

    }, { passive: true });

})();
