/**
 * Model picker — ARIA listbox widget driven by the ModelChoice contract
 * (plan MYO-226 §3.8, spec MYO-227 §3.6). Grouped by reasoning tier
 * (fast/balanced/deep), price shown on every option, optional leading
 * "shop default" option for the agent form variant.
 */
(function (global) {
    'use strict';

    var TIER_ORDER = ['fast', 'balanced', 'deep'];
    var TIER_BADGE = { fast: 'text-bg-success', balanced: 'text-bg-info', deep: 'text-bg-warning' };

    function priceLine(choice, currency) {
        return choice.priceInput + ' ' + currency + ' / ' + choice.priceOutput + ' ' + currency;
    }

    function build(panel) {
        var root = panel.closest('.model-picker');
        var toggle = root.querySelector('[data-model-picker-toggle]');
        var toggleText = root.querySelector('[data-model-picker-toggle-text]');
        var valueInput = root.querySelector('[data-model-picker-value]');
        var optionsHost = panel.querySelector('[data-model-picker-options]');

        var choices = JSON.parse(panel.dataset.choices || '[]');
        var allowInherit = panel.dataset.allowInherit === '1';
        var inheritChoice = allowInherit ? JSON.parse(panel.dataset.inheritChoice || 'null') : null;
        var tierHints = JSON.parse(panel.dataset.tierHints || '{}');
        var currency = choices.length > 0 ? choices[0].currency : 'EUR';
        var selectedId = panel.dataset.selected || '';
        var inheriting = allowInherit && selectedId === '';

        function findChoice(modelId) {
            for (var i = 0; i < choices.length; i++) {
                if (choices[i].modelId === modelId) {
                    return choices[i];
                }
            }
            return null;
        }

        function currentChoice() {
            if (inheriting) {
                return inheritChoice;
            }
            return findChoice(selectedId) || inheritChoice || choices[0] || null;
        }

        function renderToggle() {
            var current = currentChoice();
            if (!current) {
                toggleText.textContent = panel.dataset.unpricedLabel || '';
                return;
            }
            var frag = document.createElement('span');
            frag.className = 'd-flex justify-content-between align-items-center gap-2 w-100';

            var left = document.createElement('span');
            if (inheriting) {
                var strongInherit = document.createElement('strong');
                strongInherit.textContent = panel.dataset.inheritLabel || 'Shop default';
                left.appendChild(strongInherit);
                left.appendChild(document.createTextNode(' (' + current.name + ')'));
            } else {
                var strong = document.createElement('strong');
                strong.textContent = current.name;
                left.appendChild(strong);
                var badge = document.createElement('span');
                badge.className = 'badge ' + (TIER_BADGE[current.tier] || 'text-bg-secondary') + ' ms-1';
                badge.textContent = current.tierLabel;
                left.appendChild(badge);
            }

            var right = document.createElement('span');
            right.className = 'model-price';
            right.textContent = priceLine(current, currency);
            var chevron = document.createElement('i');
            chevron.className = 'bi bi-chevron-down ms-2';
            chevron.setAttribute('aria-hidden', 'true');
            right.appendChild(chevron);

            frag.appendChild(left);
            frag.appendChild(right);
            toggleText.innerHTML = '';
            toggleText.appendChild(frag);
        }

        function optionButton(choice, isInheritOption, extraLabel) {
            var opt = document.createElement('button');
            opt.type = 'button';
            opt.className = 'model-option';
            opt.setAttribute('role', 'option');
            var isSelected = isInheritOption ? inheriting : (!inheriting && selectedId === choice.modelId);
            opt.setAttribute('aria-selected', String(isSelected));

            var row = document.createElement('span');
            row.className = 'd-flex justify-content-between align-items-center gap-2';
            var left = document.createElement('span');
            var strong = document.createElement('strong');
            strong.textContent = choice.name;
            left.appendChild(strong);
            if (extraLabel) {
                var extra = document.createElement('span');
                extra.className = 'text-muted small ms-1';
                extra.textContent = extraLabel;
                left.appendChild(extra);
            }
            if (choice.isDefault && !isInheritOption) {
                var defBadge = document.createElement('span');
                defBadge.className = 'badge text-bg-light border ms-1';
                defBadge.textContent = panel.dataset.defaultBadge || 'shop default';
                left.appendChild(defBadge);
            }
            var price = document.createElement('span');
            price.className = 'model-price';
            price.textContent = priceLine(choice, currency);
            row.appendChild(left);
            row.appendChild(price);

            var contextRow = document.createElement('span');
            contextRow.className = 'model-price d-block text-end';
            if (choice.contextWindow) {
                contextRow.textContent = choice.contextWindow;
            }

            opt.appendChild(row);
            if (choice.contextWindow) {
                opt.appendChild(contextRow);
            }
            opt.addEventListener('click', function () {
                select(isInheritOption, choice.modelId);
            });
            return opt;
        }

        function renderOptions() {
            optionsHost.innerHTML = '';
            if (allowInherit && inheritChoice) {
                optionsHost.appendChild(optionButton(inheritChoice, true, panel.dataset.inheritLabel));
            }
            TIER_ORDER.forEach(function (tier) {
                var tierChoices = choices.filter(function (c) { return c.tier === tier; });
                if (tierChoices.length === 0) {
                    return;
                }
                var head = document.createElement('div');
                head.className = 'model-tier';
                head.textContent = tierChoices[0].tierLabel;
                var hint = document.createElement('span');
                hint.className = 'tier-hint';
                hint.textContent = tierHints[tier] || '';
                head.appendChild(hint);
                optionsHost.appendChild(head);
                tierChoices.forEach(function (choice) {
                    optionsHost.appendChild(optionButton(choice, false, null));
                });
            });
        }

        function select(isInherit, modelId) {
            inheriting = isInherit;
            selectedId = isInherit ? '' : modelId;
            valueInput.value = selectedId;
            valueInput.dispatchEvent(new Event('change', { bubbles: true }));
            renderToggle();
            renderOptions();
            close();
            toggle.focus();
        }

        function open() {
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
        }
        function close() {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function () {
            if (panel.hidden) {
                open();
            } else {
                close();
            }
        });
        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                close();
            }
        });
        root.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
                toggle.focus();
            }
        });

        renderToggle();
        renderOptions();

        panel.commerceAgentsPicker = {
            selectByTier: function (tier) {
                var match = choices.filter(function (c) { return c.tier === tier; })[0];
                if (match) {
                    select(false, match.modelId);
                }
            },
        };
    }

    function init(root) {
        (root || document).querySelectorAll('[data-model-picker-panel]').forEach(function (panel) {
            if (panel.dataset.modelPickerReady) {
                return;
            }
            panel.dataset.modelPickerReady = '1';
            build(panel);
        });
    }

    /** Used by the agent wizard when a preset suggests a reasoning tier. */
    function selectTier(root, tier) {
        var panel = (root || document).querySelector('[data-model-picker-panel]');
        if (panel && panel.commerceAgentsPicker) {
            panel.commerceAgentsPicker.selectByTier(tier);
        }
    }

    global.CommerceAgentsModelPicker = { init: init, selectTier: selectTier };
})(window);
