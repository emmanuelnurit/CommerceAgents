/**
 * Agent edit form — "Reset to preset" button, live character counters and
 * the Memory tab's inline edit/cancel/delete-confirm interactions (plan
 * MYO-280 §1 and §2). Every write still goes through a normal form POST:
 * this file only makes the surrounding UI pleasant, it is not required for
 * any of the actions to work.
 */
(function () {
    'use strict';

    // --- Live character counters (role prompt field + new memory entry) ---
    document.querySelectorAll('[data-char-counter-for]').forEach(function (counter) {
        var target = document.getElementById(counter.dataset.charCounterFor);
        if (!target) {
            return;
        }
        var max = counter.dataset.max;
        var hiddenLabel = counter.querySelector('.visually-hidden');

        function render() {
            counter.textContent = '';
            if (hiddenLabel) {
                counter.appendChild(hiddenLabel);
            }
            counter.appendChild(document.createTextNode(target.value.length + '/' + max));
        }

        target.addEventListener('input', render);
        render();
    });

    // --- "Reset to preset" on the role/override prompt field ---
    var resetButton = document.getElementById('agent-role-prompt-reset');
    if (resetButton) {
        resetButton.addEventListener('click', function () {
            var target = document.getElementById(resetButton.dataset.resetTarget);
            if (!target) {
                return;
            }
            if (!window.confirm(resetButton.dataset.confirm || 'Reset this text?')) {
                return;
            }
            target.value = resetButton.dataset.resetValue || '';
            target.dispatchEvent(new Event('input'));
            target.focus();
        });
    }

    // --- Memory tab: inline edit toggle ---
    document.querySelectorAll('.agent-memory-edit-toggle').forEach(function (button) {
        var form = button.closest('td').querySelector('.agent-memory-edit-form');
        var textarea = form.querySelector('.agent-memory-content');
        var actions = form.querySelector('.agent-memory-edit-actions');
        var cancelButton = form.querySelector('.agent-memory-cancel');
        var originalValue = textarea.value;

        button.addEventListener('click', function () {
            textarea.readOnly = false;
            actions.classList.remove('d-none');
            actions.classList.add('d-flex');
            button.hidden = true;
            textarea.focus();
        });

        if (cancelButton) {
            cancelButton.addEventListener('click', function () {
                textarea.value = originalValue;
                textarea.readOnly = true;
                actions.classList.add('d-none');
                actions.classList.remove('d-flex');
                button.hidden = false;
            });
        }
    });

    // --- Memory tab: delete confirmation ---
    document.querySelectorAll('.agent-memory-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.confirm || 'Delete this entry?')) {
                event.preventDefault();
            }
        });
    });
})();
