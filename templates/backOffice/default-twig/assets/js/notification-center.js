'use strict';

/**
 * Agent notification center (topbar BO, MYO-485). Polls the MYO-484 summary
 * endpoint, renders the dropdown panel entirely client-side (the endpoint
 * returns JSON, not an HTML fragment), and acknowledges items through the
 * sister ack endpoint.
 *
 * Group split: the backend (MYO-484) only exposes two arrays --
 * `items.stagedChange` and `items.outboundMessage` -- there is no separate
 * "Brief" list. Per its own note, a stagedChange item belongs to the Brief
 * group when its own `urgency` is `"now"`; everything else stays in
 * "Pending validation". Each item is placed in exactly one group, so
 * clicking either never double-acks the same underlying row.
 */
function commerceAgentsBootNotificationCenter() {
    var root = document.getElementById('bo-agent-notif-root');
    if (!root) {
        return;
    }

    var POLL_MS = 45000;

    var summaryUrl = root.dataset.summaryUrl;
    var ackUrl = root.dataset.ackUrl;
    var briefUrl = root.dataset.briefUrl;

    var i18n = {
        title: root.dataset.i18nTitle,
        mobile: root.dataset.i18nMobile,
        ariaZero: root.dataset.i18nAriaZero,
        ariaOne: root.dataset.i18nAriaOne,
        ariaMany: root.dataset.i18nAriaMany,
        staleSuffix: root.dataset.i18nStaleSuffix,
        liveOne: root.dataset.i18nLiveOne,
        liveMany: root.dataset.i18nLiveMany,
        markAll: root.dataset.i18nMarkAll,
        groupValidation: root.dataset.i18nGroupValidation,
        groupBrief: root.dataset.i18nGroupBrief,
        groupMessage: root.dataset.i18nGroupMessage,
        emptyZero: root.dataset.i18nEmptyZero,
        emptyAck: root.dataset.i18nEmptyAck,
        loading: root.dataset.i18nLoading,
        error: root.dataset.i18nError,
        retry: root.dataset.i18nRetry,
        seeAll: root.dataset.i18nSeeAll,
        more: root.dataset.i18nMore
    };

    var toggle = document.getElementById('bo-agent-notif-toggle');
    var badge = document.getElementById('bo-agent-notif-badge');
    var stale = document.getElementById('bo-agent-notif-stale');
    var body = document.getElementById('bo-agent-notif-body');
    var footer = document.getElementById('bo-agent-notif-footer');
    var markAllBtn = document.getElementById('bo-agent-notif-mark-all');
    var live = document.getElementById('bo-agent-notif-live');

    var csrfToken = null;
    var lastTotal = 0;
    var lastSeverity = null;
    var lastData = null;
    var pollTimer = null;
    var justAcked = false;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function formatTime(iso) {
        if (!iso) {
            return '';
        }
        try {
            return new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
                dateStyle: 'short',
                timeStyle: 'short'
            }).format(new Date(iso));
        } catch (error) {
            return '';
        }
    }

    function ariaLabel(total, extraSuffix) {
        var base = total <= 0
            ? i18n.ariaZero
            : (total === 1 ? i18n.ariaOne : i18n.ariaMany).replace('%count%', String(total));

        return extraSuffix ? base + ' (' + i18n.staleSuffix + ')' : base;
    }

    function setBadge(total, severity, extraSuffix) {
        if (total <= 0) {
            badge.classList.add('d-none');
        } else {
            badge.classList.remove('d-none');
            badge.className = 'badge rounded-pill bg-' + (severity || 'secondary');
            badge.textContent = total > 99 ? '99+' : (total > 9 ? '9+' : String(total));
        }
        toggle.setAttribute('aria-label', ariaLabel(total, extraSuffix));
        stale.classList.toggle('d-none', !extraSuffix);
    }

    function announceIfChanged(total) {
        if (total > 0 && total !== lastTotal) {
            live.textContent = total === 1 ? i18n.liveOne : i18n.liveMany.replace('%count%', String(total));
        } else if (total === 0) {
            live.textContent = '';
        }
    }

    function itemIcon(kind, item) {
        if (kind === 'message') {
            return item.channel === 'mail' ? 'bi-envelope' : 'bi-chat-left-text';
        }
        return kind === 'brief' ? 'bi-lightbulb' : 'bi-clipboard-check';
    }

    function groupClasses(kind) {
        if (kind === 'message') {
            return { iconBg: 'bg-primary-subtle', iconColor: 'text-primary', badgeClass: 'bg-primary' };
        }
        if (kind === 'brief') {
            return { iconBg: 'bg-secondary-subtle', iconColor: 'text-secondary-emphasis', badgeClass: 'bg-secondary' };
        }
        return { iconBg: 'bg-danger-subtle', iconColor: 'text-danger', badgeClass: 'bg-danger' };
    }

    function renderGroup(kind, label, items) {
        if (!items.length) {
            return '';
        }
        var classes = groupClasses(kind);
        var shown = items.slice(0, 5);
        var overflow = items.length - shown.length;

        var html = '<div class="bo-agent-notif-group bo-agent-notif-group--' + kind + '">';
        html += '<div class="d-flex align-items-center gap-2 px-3 pt-2 pb-1">';
        html += '<span class="bo-agent-notif-group__label">' + esc(label) + '</span>';
        html += '<span class="badge rounded-pill ' + classes.badgeClass + '">' + items.length + '</span>';
        html += '</div>';

        shown.forEach(function (item) {
            html += '<a href="' + esc(item.url) + '" class="bo-agent-notif-item" data-source-type="' + esc(item.sourceType) + '" data-source-id="' + esc(item.sourceId) + '">';
            html += '<span class="bo-agent-notif-item__icon ' + classes.iconBg + ' ' + classes.iconColor + '"><i class="bi ' + itemIcon(kind, item) + '" aria-hidden="true"></i></span>';
            html += '<span class="flex-grow-1 min-width-0">';
            html += '<span class="d-block bo-agent-notif-item__title">' + esc(item.label) + '</span>';
            html += '<span class="d-block bo-agent-notif-item__time">' + esc(formatTime(item.createdAt)) + '</span>';
            html += '</span>';
            html += '</a>';
        });

        if (overflow > 0) {
            html += '<div class="px-3 pb-2"><a href="' + esc(briefUrl) + '" class="small">' + esc(i18n.more.replace('%count%', String(overflow))) + '</a></div>';
        }

        html += '</div>';
        return html;
    }

    function emptyState(message) {
        return '<div class="bo-agent-notif-empty"><i class="bi bi-check2-circle d-block mb-2 text-success" aria-hidden="true"></i>' + esc(message) + '</div>';
    }

    function splitGroups(data) {
        var stagedChange = (data.items && data.items.stagedChange) || [];
        var outboundMessage = (data.items && data.items.outboundMessage) || [];

        return {
            validation: stagedChange.filter(function (i) { return i.urgency !== 'now'; }),
            brief: stagedChange.filter(function (i) { return i.urgency === 'now'; }),
            message: outboundMessage
        };
    }

    function renderPanel(data) {
        var groups = splitGroups(data);
        var total = data.counts.total;

        if (total <= 0) {
            body.innerHTML = emptyState(justAcked ? i18n.emptyAck : i18n.emptyZero);
            markAllBtn.classList.add('d-none');
            footer.classList.add('d-none');
            return;
        }

        justAcked = false;

        var html = '';
        html += renderGroup('validation', i18n.groupValidation, groups.validation);
        html += renderGroup('message', i18n.groupMessage, groups.message);
        html += renderGroup('brief', i18n.groupBrief, groups.brief);
        body.innerHTML = html;

        markAllBtn.classList.remove('d-none');
        var anyOverflow = groups.validation.length > 5 || groups.message.length > 5 || groups.brief.length > 5;
        footer.classList.toggle('d-none', !anyOverflow);
    }

    function severityOf(data) {
        var stagedChange = (data.items && data.items.stagedChange) || [];
        var outboundMessage = (data.items && data.items.outboundMessage) || [];
        if (stagedChange.length > 0) {
            return 'danger';
        }
        if (outboundMessage.length > 0) {
            return 'primary';
        }
        return null;
    }

    function applyData(data) {
        lastData = data;
        var total = data.counts.total;
        var severity = severityOf(data);

        if (total !== lastTotal) {
            renderPanel(data);
        }

        setBadge(total, severity, false);
        announceIfChanged(total);
        lastTotal = total;
        lastSeverity = severity;
    }

    function renderError() {
        body.innerHTML = '<div class="px-3 py-3 text-center">'
            + '<p class="text-muted mb-2">' + esc(i18n.error) + '</p>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" id="bo-agent-notif-retry">' + esc(i18n.retry) + '</button>'
            + '</div>';
        markAllBtn.classList.add('d-none');
        footer.classList.add('d-none');
        setBadge(lastTotal, lastSeverity, true);

        var retryBtn = document.getElementById('bo-agent-notif-retry');
        if (retryBtn) {
            retryBtn.addEventListener('click', function () { fetchSummary(); });
        }
    }

    function fetchSummary() {
        return fetch(summaryUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('summary http ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                csrfToken = data.csrfToken || csrfToken;
                applyData(data);
            })
            .catch(function () {
                renderError();
            });
    }

    function ack(sourceType, sourceId) {
        return fetch(ackUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken || '' },
            body: JSON.stringify({ sourceType: sourceType, sourceId: Number(sourceId) })
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('ack http ' + response.status);
            }
            return response.json();
        });
    }

    function removeAcked(sourceType, sourceId) {
        if (!lastData || !lastData.items) {
            return;
        }
        ['stagedChange', 'outboundMessage'].forEach(function (key) {
            lastData.items[key] = (lastData.items[key] || []).filter(function (item) {
                return !(item.sourceType === sourceType && String(item.sourceId) === String(sourceId));
            });
        });
        lastData.counts = {
            total: lastData.items.stagedChange.length + lastData.items.outboundMessage.length,
            stagedChange: lastData.items.stagedChange.length,
            outboundMessage: lastData.items.outboundMessage.length,
            brief: lastData.items.stagedChange.filter(function (i) { return i.urgency === 'now'; }).length
        };
        if (lastData.counts.total === 0) {
            justAcked = true;
        }
        lastTotal = -1; // force renderPanel() on the next applyData() even if the new total matches an old one.
        applyData(lastData);
    }

    body.addEventListener('click', function (event) {
        var link = event.target.closest('.bo-agent-notif-item');
        if (!link) {
            return;
        }
        event.preventDefault();
        var sourceType = link.dataset.sourceType;
        var sourceId = link.dataset.sourceId;
        var destination = link.getAttribute('href');

        ack(sourceType, sourceId)
            .then(function () { removeAcked(sourceType, sourceId); })
            .catch(function (error) { window.console && console.error('commerceagents: ack failed', error); })
            .then(function () { window.location.href = destination; });
    });

    markAllBtn.addEventListener('click', function () {
        if (!lastData || !lastData.items) {
            return;
        }
        var pending = lastData.items.stagedChange.concat(lastData.items.outboundMessage);
        markAllBtn.classList.add('d-none');

        Promise.all(pending.map(function (item) {
            return ack(item.sourceType, item.sourceId).catch(function (error) {
                window.console && console.error('commerceagents: ack failed', error);
            });
        })).then(function () {
            justAcked = true;
            lastData.items = { stagedChange: [], outboundMessage: [] };
            lastData.counts = { total: 0, stagedChange: 0, outboundMessage: 0, brief: 0 };
            lastTotal = -1;
            applyData(lastData);
        });
    });

    toggle.addEventListener('shown.bs.dropdown', function () {
        var focusable = markAllBtn.classList.contains('d-none')
            ? body.querySelector('.bo-agent-notif-item, #bo-agent-notif-retry')
            : markAllBtn;
        if (focusable) {
            focusable.focus();
        }
    });

    function startPolling() {
        if (pollTimer) {
            return;
        }
        pollTimer = window.setInterval(fetchSummary, POLL_MS);
    }

    function stopPolling() {
        if (pollTimer) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopPolling();
        } else {
            fetchSummary();
            startPolling();
        }
    });

    fetchSummary();
    startPolling();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', commerceAgentsBootNotificationCenter);
} else {
    commerceAgentsBootNotificationCenter();
}
