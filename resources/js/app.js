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

/*
 * Day cell detail (FR-011): a ran day's button opens one shared native <dialog>,
 * filled from that day's inert <template> — the runs are already in the page, so
 * nothing is fetched. showModal() gives the focus trap, Esc and the inert page;
 * focus goes back to the cell that opened it, and a click on the backdrop closes.
 * Without JS the cells are buttons that do nothing, the same fall-back as the theme
 * toggle, and each cell's screen-reader text still names the day and its distance.
 */
(function () {
    var dialog = document.getElementById('day-dialog');

    if (!dialog) {
        return;
    }

    var titleSlot = dialog.querySelector('[data-dlg-title]');
    var bodySlot = dialog.querySelector('[data-dlg-body]');
    var opener = null;

    function open(cell) {
        var template = document.getElementById('day-' + cell.getAttribute('data-day'));

        if (!template) {
            return;
        }

        var content = template.content.cloneNode(true);

        titleSlot.replaceChildren(content.querySelector('h2'), content.querySelector('.dlg-sub'));
        bodySlot.replaceChildren(content.querySelector('.runs'));
        bodySlot.scrollTop = 0;

        opener = cell;
        cell.classList.add('is-open');
        dialog.showModal();
    }

    document.addEventListener('click', function (event) {
        var cell = event.target.closest('[data-day]');

        if (cell) {
            open(cell);

            return;
        }

        if (event.target.closest('[data-dlg-close]') || event.target === dialog) {
            dialog.close();
        }
    });

    dialog.addEventListener('close', function () {
        if (!opener) {
            return;
        }

        opener.classList.remove('is-open');
        opener.focus();
        opener = null;
    });
})();
