/**
 * Cache Party — Delayed Load Event Dispatcher
 *
 * Dispatches a "delayedLoad" custom event on first user interaction
 * (mouseover, touchstart, keydown, wheel, scroll past 30px) or after
 * an idle timeout. All deferred features (JS delay, iframe lazy, etc.)
 * listen for this single event.
 *
 * Also handles scroll state body classes for CSS targeting.
 */
(function () {
    'use strict';

    var fired = false;

    function fireDelayedLoad() {
        if (fired) return;
        fired = true;

        // Remove interaction listeners.
        document.removeEventListener('mouseover', fireDelayedLoad);
        document.removeEventListener('touchstart', fireDelayedLoad);
        document.removeEventListener('keydown', fireDelayedLoad);
        document.removeEventListener('wheel', fireDelayedLoad);

        // Dispatch the event that all deferred features listen for.
        document.dispatchEvent(new CustomEvent('delayedLoad'));

        // Swap lazy-loaded iframe sources.
        document.querySelectorAll('iframe[data-lazy-src]').forEach(function (el) {
            el.setAttribute('src', el.getAttribute('data-lazy-src'));
            el.removeAttribute('data-lazy-src');
        });
    }

    // Scroll state tracking.
    function checkScroll() {
        if (window.pageYOffset > 30) {
            document.body.classList.remove('cp-not-scrolled');
            document.body.classList.add('cp-scrolled');
            fireDelayedLoad();
        } else {
            requestAnimationFrame(checkScroll);
        }
    }

    window.addEventListener('load', function () {

        // Interaction triggers.
        document.addEventListener('mouseover', fireDelayedLoad, { once: true, passive: true });
        document.addEventListener('touchstart', fireDelayedLoad, { once: true, passive: true });
        document.addEventListener('keydown', fireDelayedLoad, { once: true });
        document.addEventListener('wheel', fireDelayedLoad, { once: true, passive: true });

        // Scroll check (fires delayedLoad if already scrolled past threshold).
        requestAnimationFrame(checkScroll);

        // Idle timeout fallback.
        var selfScript = document.querySelector('script[data-idle-timeout]');
        var idleTimeout = selfScript
            ? parseInt(selfScript.getAttribute('data-idle-timeout'), 10) * 1000
            : 5000;

        if (idleTimeout > 0) {
            if (typeof requestIdleCallback === 'function') {
                requestIdleCallback(function () {
                    setTimeout(fireDelayedLoad, idleTimeout);
                });
            } else {
                setTimeout(fireDelayedLoad, idleTimeout);
            }
        }

    }, { passive: true });

})();
