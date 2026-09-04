(function () {
    'use strict';

    var toggle = document.querySelector('.gx-menu-toggle');
    var nav = document.querySelector('.gx-nav');

    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            var open = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!open));
            nav.classList.toggle('is-open', !open);
        });

        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                toggle.setAttribute('aria-expanded', 'false');
                nav.classList.remove('is-open');
            });
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
}());
