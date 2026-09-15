/**
 * Agents IA — list page interactions (plan MYO-227 §4.1): active/inactive
 * switch (immediate, no confirmation — reversible), "Run now", and the
 * delete confirmation. Every action degrades to a normal form POST + full
 * page reload if fetch fails.
 */
(function () {
    'use strict';

    function post(url, token) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ _token: token }),
        }).then(function (response) { return response.json(); });
    }

    document.querySelectorAll('.agent-toggle-input').forEach(function (input) {
        input.addEventListener('change', function () {
            var form = input.closest('form');
            var token = form.querySelector('input[name="_token"]').value;
            var card = input.closest('.agent-card');
            input.disabled = true;
            post(form.getAttribute('action'), token).then(function (payload) {
                if (payload && payload.success && card) {
                    card.classList.toggle('is-inactive', !payload.enabled);
                }
            }).catch(function () {
                form.submit();
            }).finally(function () {
                input.disabled = false;
            });
        });
    });

    document.querySelectorAll('.agent-run-now-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var token = form.querySelector('input[name="_token"]').value;
            var button = form.querySelector('button');
            var original = button.textContent;
            button.disabled = true;
            post(form.getAttribute('action'), token).then(function () {
                button.textContent = button.dataset.runningLabel || original;
            }).catch(function () {
                form.submit();
            }).finally(function () {
                setTimeout(function () {
                    button.disabled = false;
                    button.textContent = original;
                }, 1500);
            });
        });
    });

    document.querySelectorAll('.agent-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.confirm || 'Delete this agent?')) {
                event.preventDefault();
            }
        });
    });
})();
