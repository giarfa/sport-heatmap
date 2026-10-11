/*
 * The inline head script in layouts/app.blade.php applies the persisted theme
 * before first paint; this handler only reacts to the toggle afterwards, so
 * the two never fight over which class wins.
 */
function isDarkActive() {
    var root = document.documentElement;

    if (root.classList.contains('dark')) {
        return true;
    }

    if (root.classList.contains('light')) {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

document.addEventListener('DOMContentLoaded', function () {
    var pressed = isDarkActive() ? 'true' : 'false';

    document.querySelectorAll('[data-theme-toggle]').forEach(function (toggle) {
        toggle.setAttribute('aria-pressed', pressed);
    });
});

document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-theme-toggle]');

    if (!button) {
        return;
    }

    var root = document.documentElement;
    var goingDark = !root.classList.contains('dark');

    root.classList.toggle('dark', goingDark);
    root.classList.toggle('light', !goingDark);
    localStorage.setItem('theme', goingDark ? 'dark' : 'light');

    document.querySelectorAll('[data-theme-toggle]').forEach(function (toggle) {
        toggle.setAttribute('aria-pressed', goingDark ? 'true' : 'false');
    });
});

/*
 * Garmin import review: "Overwrite all" / "Skip all". The shortcuts are
 * rendered hidden and only revealed here, so without JS they never appear and
 * the per-row radios — Skip pre-checked — still submit a complete answer.
 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-resolve-shortcuts]').forEach(function (shortcuts) {
        shortcuts.hidden = false;
    });
});

document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-resolve-all]');

    if (!button || !button.form) {
        return;
    }

    var value = button.getAttribute('data-resolve-all');

    button.form.querySelectorAll('input[type="radio"][name^="resolutions["]').forEach(function (radio) {
        if (radio.value === value) {
            radio.checked = true;
        }
    });
});
