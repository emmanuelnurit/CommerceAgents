/**
 * Skills library (MYO-507) -- activation switch. Mirrors the agent toggle in
 * agents-list.js: fetch POST to the backend activate/deactivate endpoint,
 * then a full page reload so the tile's badge/acceptance-rate/cost/Edit link
 * come back from the server (the template stays a dumb renderer, no client
 * state to keep in sync). Degrades to a normal form POST if fetch fails.
 */
(function () {
    'use strict';

    function post(url, token) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ _token: token }),
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('skill toggle failed: ' + response.status);
            }

            return response.json();
        });
    }

    document.querySelectorAll('.skill-toggle-input').forEach(function (input) {
        input.addEventListener('change', function () {
            var form = input.closest('form');
            var token = form.querySelector('input[name="_token"]').value;
            input.disabled = true;
            post(form.getAttribute('action'), token).then(function () {
                window.location.reload();
            }).catch(function () {
                form.submit();
            });
        });
    });
})();
