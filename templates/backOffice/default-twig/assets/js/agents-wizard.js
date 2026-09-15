/**
 * Agents IA — create wizard (4 steps) and edit form interactions
 * (plan MYO-227 §4.3). The stepper only applies in wizard mode; trigger
 * reveal-on-check and preset pre-fill are shared so edit mode still gets the
 * reveal behaviour for free.
 */
(function () {
    'use strict';

    var form = document.getElementById('agent-form');
    if (!form) {
        return;
    }
    var mode = form.dataset.mode;

    // --- AI model picker: build the options list for this form (create + edit) ---
    if (window.CommerceAgentsModelPicker) {
        window.CommerceAgentsModelPicker.init(form);
    }

    // --- Reveal a trigger's detail fields only once it is checked ---
    document.querySelectorAll('.trigger-toggle').forEach(function (checkbox) {
        var detail = document.getElementById(checkbox.dataset.reveals);
        function sync() {
            if (detail) {
                detail.hidden = !checkbox.checked;
            }
        }
        checkbox.addEventListener('change', sync);
        sync();
    });

    if (mode !== 'wizard') {
        return;
    }

    // --- Stepper ---
    var steps = Array.prototype.slice.call(document.querySelectorAll('.wizard-step'));
    var indicators = Array.prototype.slice.call(document.querySelectorAll('[data-step-indicator]'));
    var backButton = document.getElementById('wizard-back');
    var nextButton = document.getElementById('wizard-next');
    var pauseButton = document.getElementById('wizard-create-paused');
    var activateButton = document.getElementById('wizard-activate');
    var current = parseInt(form.dataset.startStep, 10) || 1;

    function showStep(step) {
        current = step;
        steps.forEach(function (el) {
            el.hidden = parseInt(el.dataset.step, 10) !== step;
        });
        indicators.forEach(function (li) {
            var n = parseInt(li.dataset.stepIndicator, 10);
            li.classList.toggle('active', n === step);
            li.classList.toggle('done', n < step);
            if (n === step) {
                li.setAttribute('aria-current', 'step');
            } else {
                li.removeAttribute('aria-current');
            }
        });
        backButton.hidden = step === 1;
        var isLast = step === steps.length;
        nextButton.hidden = isLast;
        pauseButton.hidden = !isLast;
        activateButton.hidden = !isLast;
        form.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    }

    function validateStep(step) {
        if (step === 2) {
            var title = document.getElementById('agent-title');
            if (title && title.value.trim() === '') {
                title.focus();
                return false;
            }
        }
        return true;
    }

    backButton.addEventListener('click', function () {
        showStep(Math.max(1, current - 1));
    });
    nextButton.addEventListener('click', function () {
        if (!validateStep(current)) {
            return;
        }
        showStep(Math.min(steps.length, current + 1));
    });

    // --- Preset selection (step 1): pre-fills steps 2-4 then advances ---
    var triggerCheckboxIds = {
        cart_abandoned: { checkbox: 'trigger-cart-abandoned', hours: 'trigger-cart-abandoned-hours' },
        new_order: { checkbox: 'trigger-new-order' },
        order_status_change: { checkbox: 'trigger-order-status' },
        new_customer: { checkbox: 'trigger-new-customer' },
        low_stock: { checkbox: 'trigger-low-stock', threshold: 'trigger-low-stock-threshold' },
        schedule: { checkbox: 'trigger-schedule', time: 'trigger-schedule-time' },
    };

    function check(id, checked) {
        var el = document.getElementById(id);
        if (el) {
            el.checked = checked;
            el.dispatchEvent(new Event('change'));
        }
    }

    document.querySelectorAll('[data-preset-select]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('agent-title').value = button.dataset.presetTitle || '';
            document.getElementById('agent-description').value = button.dataset.presetSubtitle || '';
            document.getElementById('agent-role-prompt').value = button.dataset.presetRolePrompt || '';
            document.getElementById('agent-role-prompt').dispatchEvent(new Event('input'));
            var presetCodeField = document.getElementById('agent-preset-code');
            if (presetCodeField) {
                presetCodeField.value = button.dataset.presetCode || '';
            }

            if (button.dataset.presetTier && window.CommerceAgentsModelPicker) {
                window.CommerceAgentsModelPicker.selectTier(form, button.dataset.presetTier);
            }

            Object.keys(triggerCheckboxIds).forEach(function (type) {
                check(triggerCheckboxIds[type].checkbox, false);
            });
            var triggers = JSON.parse(button.dataset.presetTriggers || '[]');
            triggers.forEach(function (trigger) {
                var ids = triggerCheckboxIds[trigger.type];
                if (!ids) {
                    return;
                }
                check(ids.checkbox, true);
                if (ids.hours && trigger.hours) {
                    document.getElementById(ids.hours).value = trigger.hours;
                }
                if (ids.time && trigger.time) {
                    document.getElementById(ids.time).value = trigger.time;
                }
            });

            var channel = button.dataset.presetChannel;
            ['mail', 'mattermost', 'slack'].forEach(function (code) {
                check('channel-' + code, code === channel);
            });

            var capabilities = JSON.parse(button.dataset.presetCapabilities || '[]');
            document.querySelectorAll('input[name="capabilities[]"]').forEach(function (input) {
                input.checked = capabilities.indexOf(input.value) !== -1;
            });

            showStep(2);
        });
    });

    showStep(current);
})();
