document.addEventListener('DOMContentLoaded', function () {
    var workspace = document.querySelector('.ge-workspace');
    if (!workspace) { return; }
    var tabs = Array.from(workspace.querySelectorAll('.ge-workspace-tabs a'));
    var panels = Array.from(workspace.querySelectorAll('.ge-workspace-tab'));
    function selectTab(id) {
        if (!panels.some(function (panel) { return panel.id === id; })) { id = 'ge-workspace-quotes'; }
        panels.forEach(function (panel) { panel.hidden = panel.id !== id; });
        tabs.forEach(function (tab) {
            var active = tab.getAttribute('href') === '#' + id;
            tab.classList.toggle('is-active', active);
            if (active) { tab.setAttribute('aria-current', 'page'); }
            else { tab.removeAttribute('aria-current'); }
        });
    }
    tabs.forEach(function (tab) { tab.addEventListener('click', function () { selectTab(tab.getAttribute('href').slice(1)); }); });
    selectTab(window.location.hash.slice(1));
    window.addEventListener('hashchange', function () { selectTab(window.location.hash.slice(1)); });

    workspace.querySelectorAll('[data-ge-workspace-form]').forEach(function (form) {
        var state = form.querySelector('[data-ge-save-state]');
        form.addEventListener('input', function () { if (state) { state.textContent = 'Cambios sin guardar'; state.classList.add('is-dirty'); } });
        form.addEventListener('change', function () { if (state) { state.textContent = 'Cambios sin guardar'; state.classList.add('is-dirty'); } });
        form.addEventListener('submit', function (event) {
            var submitter = event.submitter;
            if (submitter && submitter.dataset.geConfirm && !window.confirm(submitter.dataset.geConfirm)) { event.preventDefault(); return; }
            if (state) { state.textContent = 'Guardando…'; }
        });
    });
    var changed = new URLSearchParams(window.location.search).get('workspace_section');
    if (changed && changed !== 'identity') {
        var section = document.getElementById('ge-workspace-' + changed);
        if (section && section.tagName === 'DETAILS') { section.open = true; }
    }
});
