(function () {
    'use strict';

    var videos = Array.prototype.slice.call(document.querySelectorAll('[data-gx-hero-video]'));
    var toggle = document.querySelector('.gx-video-toggle');
    if (!videos.length || !toggle) { return; }

    var controls = toggle.closest('.gx-media-controls');
    var credits = Array.prototype.slice.call(document.querySelectorAll('[data-gx-video-credit]'));
    var motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
    var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    var reducedMotion = motionPreference.matches;
    var savingData = !!(connection && connection.saveData);
    var manualPermission = false;
    var userPaused = false;
    var timeoutMs = 30000;
    var states = videos.map(function (video) {
        return {
            video: video,
            figure: video.closest('.gx-hero-image'),
            sources: Array.prototype.slice.call(video.querySelectorAll('source[data-src]')),
            visible: false,
            loaded: false,
            failed: false,
            blocked: false,
            pending: false,
            timer: null,
            attempt: 0
        };
    });

    function playbackPermitted() {
        return !userPaused && (manualPermission || (!motionPreference.matches && !(connection && connection.saveData)));
    }

    function shouldPlay(state) {
        return !state.failed && !state.blocked && state.visible && !document.hidden && playbackPermitted();
    }

    function isActive(state) {
        return !state.failed && (state.pending || (!state.video.paused && !state.video.ended));
    }

    function updateControls() {
        var usable = states.some(function (state) { return !state.failed; });
        var active = states.some(isActive);
        toggle.hidden = !usable;
        if (controls) { controls.hidden = !usable; }
        toggle.textContent = active ? 'Pausar videos' : 'Reproducir videos';
        toggle.setAttribute('aria-label', toggle.textContent);
        toggle.setAttribute('aria-pressed', active ? 'true' : 'false');
    }

    function clearTimer(state) {
        if (state.timer !== null) {
            window.clearTimeout(state.timer);
            state.timer = null;
        }
    }

    function pause(state) {
        state.attempt += 1;
        state.pending = false;
        clearTimer(state);
        state.video.pause();
    }

    function fail(state, reason) {
        if (state.failed) { return; }
        state.failed = true;
        pause(state);
        state.video.hidden = true;
        state.video.setAttribute('data-gx-video-failed', 'true');
        state.video.setAttribute('data-gx-video-error', reason);
        if (state.figure) { state.figure.classList.remove('is-video-ready'); }
        credits.forEach(function (credit) {
            if (credit.getAttribute('data-gx-video-credit') === state.video.id) { credit.hidden = true; }
        });
        // Release a failed/stalled request; the local fallback stays untouched.
        state.sources.forEach(function (source) { source.removeAttribute('src'); });
        state.video.removeAttribute('src');
        state.video.removeAttribute('poster');
        state.video.load();
        updateControls();
    }

    function startTimer(state) {
        if (state.timer !== null) { return; }
        state.timer = window.setTimeout(function () {
            state.timer = null;
            if (shouldPlay(state)) { fail(state, 'timeout'); }
        }, timeoutMs);
    }

    function rejectPlayback(state, error, attempt) {
        if (state.failed || attempt !== state.attempt) { return; }
        state.pending = false;
        clearTimer(state);
        if (error && (error.name === 'NotAllowedError' || error.name === 'AbortError')) {
            // Browser policy or interrupted playback is retryable, not a missing asset.
            state.blocked = true;
            if (state.figure) { state.figure.classList.remove('is-video-ready'); }
            pause(state);
        } else {
            fail(state, error && error.name ? error.name : 'playback');
        }
        updateControls();
    }

    function play(state) {
        if (!shouldPlay(state) || isActive(state)) { return; }
        state.pending = true;
        state.attempt += 1;
        var attempt = state.attempt;
        try {
            if (!state.loaded) {
                state.loaded = true;
                var poster = state.video.getAttribute('data-poster');
                if (poster) { state.video.setAttribute('poster', poster); }
                state.sources.forEach(function (source) {
                    source.setAttribute('src', source.getAttribute('data-src'));
                });
                state.video.load();
            }
            if (state.failed || attempt !== state.attempt) { return; }
            startTimer(state);
            var playback = state.video.play();
            if (playback && typeof playback.then === 'function') {
                playback.then(function () {
                    if (attempt !== state.attempt || state.failed) { return; }
                    if (!shouldPlay(state)) { pause(state); }
                    updateControls();
                }, function (error) { rejectPlayback(state, error, attempt); });
            }
        } catch (error) {
            rejectPlayback(state, error, attempt);
        }
        updateControls();
    }

    function reconcile(state) {
        if (shouldPlay(state)) { play(state); }
        else { pause(state); }
    }

    function measureVisibility(state) {
        if (!state.figure) { return false; }
        var rect = state.figure.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.right > 0 &&
            rect.top < window.innerHeight && rect.left < window.innerWidth;
    }

    function refreshVisibility() {
        states.forEach(function (state) {
            state.visible = measureVisibility(state);
            reconcile(state);
        });
        updateControls();
    }

    states.forEach(function (state) {
        var video = state.video;
        video.controls = false;
        video.autoplay = false;
        video.muted = true;
        video.defaultMuted = true;
        video.loop = true;
        video.playsInline = true;
        video.preload = 'none';

        video.addEventListener('playing', function () {
            if (!shouldPlay(state)) { pause(state); updateControls(); return; }
            state.pending = false;
            clearTimer(state);
            video.hidden = false;
            state.figure.classList.add('is-video-ready');
            updateControls();
        });
        video.addEventListener('play', updateControls);
        video.addEventListener('pause', function () {
            if (video.paused) {
                state.pending = false;
                clearTimer(state);
            }
            updateControls();
        });
        // `stalled` can occur while buffered frames keep playing normally.
        video.addEventListener('waiting', function () {
            if (shouldPlay(state) && (state.pending || !video.paused)) { startTimer(state); }
        });
        video.addEventListener('error', function () {
            if (!state.loaded || state.failed) { return; }
            if (video.error && video.error.code === 1) {
                rejectPlayback(state, { name: 'AbortError' }, state.attempt);
            } else {
                fail(state, video.error ? 'media-' + video.error.code : 'media');
            }
        });
        state.sources.forEach(function (source) {
            source.addEventListener('error', function () {
                if (!state.loaded || state.failed || !source.hasAttribute('src')) { return; }
                source.setAttribute('data-gx-source-failed', 'true');
                if (state.sources.every(function (item) { return item.getAttribute('data-gx-source-failed') === 'true'; })) {
                    fail(state, 'source');
                }
            });
        });
        if (!state.figure || !state.sources.length || state.sources.some(function (source) {
            return !(source.getAttribute('data-src') || '').trim();
        })) { fail(state, 'configuration'); }
    });

    toggle.addEventListener('click', function () {
        if (states.some(isActive)) {
            userPaused = true;
            states.forEach(pause);
        } else {
            userPaused = false;
            manualPermission = true;
            states.forEach(function (state) { if (!state.failed) { state.blocked = false; } });
            // Keep play() inside the user gesture for browsers that block autoplay.
            refreshVisibility();
        }
        updateControls();
    });

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                states.forEach(function (state) {
                    if (state.figure !== entry.target) { return; }
                    state.visible = entry.isIntersecting && entry.intersectionRatio > 0;
                    reconcile(state);
                });
            });
            updateControls();
        }, { threshold: 0.01 });
        states.forEach(function (state) { if (state.figure && !state.failed) { observer.observe(state.figure); } });
    } else {
        var visibilityScheduled = false;
        function scheduleVisibility() {
            if (visibilityScheduled) { return; }
            visibilityScheduled = true;
            window.requestAnimationFrame(function () {
                visibilityScheduled = false;
                refreshVisibility();
            });
        }
        window.addEventListener('scroll', scheduleVisibility, { passive: true });
        window.addEventListener('resize', scheduleVisibility);
        refreshVisibility();
    }

    document.addEventListener('visibilitychange', refreshVisibility);
    window.addEventListener('pagehide', function () { states.forEach(pause); updateControls(); });
    window.addEventListener('pageshow', refreshVisibility);

    function updatePreferences() {
        var nextReducedMotion = motionPreference.matches;
        var nextSavingData = !!(connection && connection.saveData);
        if ((nextReducedMotion && !reducedMotion) || (nextSavingData && !savingData)) { manualPermission = false; }
        reducedMotion = nextReducedMotion;
        savingData = nextSavingData;
        refreshVisibility();
    }
    if (motionPreference.addEventListener) { motionPreference.addEventListener('change', updatePreferences); }
    else if (motionPreference.addListener) { motionPreference.addListener(updatePreferences); }
    if (connection && connection.addEventListener) { connection.addEventListener('change', updatePreferences); }
    updateControls();
}());
