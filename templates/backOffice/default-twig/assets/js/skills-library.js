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

    /**
     * Guided settings modal (MYO-519, AC2 of MYO-508): same fetch-then-reload
     * pattern as the toggle above, full form body this time (thresholds,
     * category multi-select, etc.) instead of just the CSRF token.
     */
    document.querySelectorAll('.guided-settings-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
            }
            fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams(new FormData(form)),
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('guided settings save failed: ' + response.status);
                }

                window.location.reload();
            }).catch(function () {
                form.submit();
            });
        });
    });

    /**
     * Guided settings modal: AC4 drift banner, one "Replace with %tone%" button
     * per available variant. Same confirm()-then-apply pattern as the "Reset to
     * preset" button (agents-memory.js), except it unlocks a whole radio group
     * instead of resetting one textarea: confirm, enable the disabled inputs,
     * check the chosen variant, then drop the banner and all unlock buttons so
     * the form reads as a normal (now editable) guided settings form.
     */
    document.querySelectorAll('.js-guided-unlock').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!window.confirm(button.dataset.confirm || 'Replace this text?')) {
                return;
            }

            var group = button.closest('.settings-locked');
            if (!group) {
                return;
            }
            group.classList.remove('settings-locked');
            group.querySelectorAll('input[type="radio"]').forEach(function (input) {
                input.disabled = false;
            });
            var segmented = group.querySelector('.segmented');
            if (segmented) {
                segmented.classList.remove('segmented--disabled');
            }
            var toCheck = group.querySelector('input[type="radio"][value="' + button.dataset.unlockValue + '"]');
            if (toCheck) {
                toCheck.checked = true;
            }
            group.querySelectorAll('.js-guided-unlock').forEach(function (unlockButton) {
                unlockButton.remove();
            });

            var modalContent = group.closest('.modal-content');
            if (!modalContent) {
                return;
            }
            var driftBanner = modalContent.querySelector('.drift-banner');
            if (driftBanner) {
                driftBanner.remove();
            }
            var saveButton = modalContent.querySelector('button[type="submit"]');
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.removeAttribute('title');
            }
        });
    });
})();
