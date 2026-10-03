(function () {
    'use strict';

    var toggle = document.querySelector('.gx-menu-toggle');
    var nav = document.querySelector('.gx-nav');

    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            var open = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!open));
            toggle.setAttribute('aria-label', open ? 'Abrir menú' : 'Cerrar menú');
            nav.classList.toggle('is-open', !open);
        });

        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                toggle.setAttribute('aria-expanded', 'false');
                nav.classList.remove('is-open');
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && nav.classList.contains('is-open')) {
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('aria-label', 'Abrir menú');
                nav.classList.remove('is-open');
                toggle.focus();
            }
        });

        document.addEventListener('click', function (event) {
            if (nav.classList.contains('is-open') && !nav.contains(event.target) && !toggle.contains(event.target)) {
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('aria-label', 'Abrir menú');
                nav.classList.remove('is-open');
            }
        });
    }

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });

        document.querySelectorAll('.gx-reveal').forEach(function (element) {
            observer.observe(element);
        });
    } else {
        document.querySelectorAll('.gx-reveal').forEach(function (element) {
            element.classList.add('is-visible');
        });
    }

    document.querySelectorAll('[data-gx-category-video]').forEach(function (video) {
        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var saveData = navigator.connection && navigator.connection.saveData;
        if (reducedMotion || saveData) {
            video.pause();
            return;
        }

        if ('IntersectionObserver' in window) {
            var videoObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        var playback = video.play();
                        if (playback && typeof playback.catch === 'function') { playback.catch(function () {}); }
                    } else {
                        video.pause();
                    }
                });
            }, { threshold: 0.35 });
            videoObserver.observe(video);
        } else {
            var playback = video.play();
            if (playback && typeof playback.catch === 'function') { playback.catch(function () {}); }
        }
    });
}());
