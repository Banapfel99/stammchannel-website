(function () {
    'use strict';

    var STORAGE_KEY = 'site-theme';
    var root = document.documentElement;

    function applyTheme(theme) {
        if (theme && theme !== 'aurora') {
            root.setAttribute('data-theme', theme);
        } else {
            root.removeAttribute('data-theme');
        }

        document.querySelectorAll('.theme-switcher').forEach(function (select) {
            select.value = theme || 'aurora';
        });
    }

    var stored = window.localStorage.getItem(STORAGE_KEY) || 'aurora';
    applyTheme(stored);

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.theme-switcher').forEach(function (select) {
            select.value = stored;

            select.addEventListener('change', function () {
                window.localStorage.setItem(STORAGE_KEY, select.value);
                applyTheme(select.value);
            });
        });
    });
})();
