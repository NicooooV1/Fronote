/**
 * Fronote UI Components — JS interactions
 * Handles: tabs, dropdown, modal, toast, alert dismiss, card collapse
 */
(function() {
    'use strict';

    // ─── Tabs ──────────────────────────────────────────────────────
    document.addEventListener('click', function(e) {
        var tab = e.target.closest('.ui-tabs__tab');
        if (!tab) return;
        var tabs = tab.closest('.ui-tabs');
        var key = tab.dataset.tab;

        tabs.querySelectorAll('.ui-tabs__tab').forEach(function(t) { t.classList.remove('ui-tabs__tab--active'); });
        tabs.querySelectorAll('.ui-tabs__panel').forEach(function(p) { p.classList.remove('ui-tabs__panel--active'); });
        tab.classList.add('ui-tabs__tab--active');
        var panel = tabs.querySelector('[data-tab-panel="' + key + '"]');
        if (panel) panel.classList.add('ui-tabs__panel--active');
    });

    // ─── Dropdown ──────────────────────────────────────────────────
    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('[data-dropdown]');
        if (trigger) {
            e.stopPropagation();
            var dd = document.getElementById(trigger.dataset.dropdown);
            if (dd) {
                var wasOpen = dd.classList.contains('is-open');
                closeAllDropdowns();
                if (!wasOpen) dd.classList.add('is-open');
            }
            return;
        }
        if (!e.target.closest('.ui-dropdown__menu')) {
            closeAllDropdowns();
        }
    });

    function closeAllDropdowns() {
        document.querySelectorAll('.ui-dropdown.is-open').forEach(function(d) { d.classList.remove('is-open'); });
    }

    // ─── Modal ─────────────────────────────────────────────────────
    var FOCUSABLE_SELECTOR = 'a[href], area[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    var lastModalTrigger = null;

    function getFocusable(modal) {
        return Array.prototype.filter.call(
            modal.querySelectorAll(FOCUSABLE_SELECTOR),
            function(el) {
                return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
            }
        );
    }

    function openModal(modal, trigger) {
        if (!modal) return;
        lastModalTrigger = trigger || null;
        modal.classList.add('is-visible');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        var focusable = getFocusable(modal);
        if (focusable.length) {
            focusable[0].focus();
        } else if (typeof modal.focus === 'function') {
            if (!modal.hasAttribute('tabindex')) modal.setAttribute('tabindex', '-1');
            modal.focus();
        }
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('is-visible');
        modal.removeAttribute('aria-modal');
        var trigger = lastModalTrigger;
        lastModalTrigger = null;
        if (trigger && typeof trigger.focus === 'function') {
            trigger.focus();
        }
    }

    document.addEventListener('click', function(e) {
        // Open modal
        var opener = e.target.closest('[data-modal]');
        if (opener) {
            e.preventDefault();
            openModal(document.getElementById(opener.dataset.modal), opener);
            return;
        }
        // Close modal
        var closer = e.target.closest('[data-dismiss="modal"]');
        if (closer) {
            closeModal(closer.closest('.ui-modal-overlay'));
            return;
        }
        // Close on overlay click
        if (e.target.classList.contains('ui-modal-overlay')) {
            closeModal(e.target);
        }
    });

    document.addEventListener('keydown', function(e) {
        var modal = document.querySelector('.ui-modal-overlay.is-visible');
        if (!modal) return;

        if (e.key === 'Escape') {
            closeModal(modal);
            return;
        }

        // Trap Tab focus within the modal
        if (e.key === 'Tab') {
            var focusable = getFocusable(modal);
            if (!focusable.length) {
                e.preventDefault();
                return;
            }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            var active = document.activeElement;
            if (e.shiftKey) {
                if (active === first || !modal.contains(active)) {
                    e.preventDefault();
                    last.focus();
                }
            } else {
                if (active === last || !modal.contains(active)) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }
    });

    // ─── Alert dismiss ─────────────────────────────────────────────
    document.addEventListener('click', function(e) {
        var close = e.target.closest('.ui-alert__close');
        if (close) {
            var alert = close.closest('.ui-alert');
            if (alert) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-8px)';
                setTimeout(function() { alert.remove(); }, 200);
            }
        }
    });

    // ─── Card collapse ────────────��────────────────────────────────
    document.addEventListener('click', function(e) {
        var toggle = e.target.closest('.ui-card__toggle');
        if (!toggle) return;
        var card = toggle.closest('.ui-card');
        var body = card.querySelector('.ui-card__body');
        if (body) {
            var hidden = body.style.display === 'none';
            body.style.display = hidden ? '' : 'none';
            toggle.querySelector('i').style.transform = hidden ? '' : 'rotate(-90deg)';
        }
    });

    // ─── Toast API ─────────────────────────────────────────────────
    window.FronoteToast = {
        show: function(message, type, duration) {
            type = type || 'info';
            duration = duration || 4000;
            var container = document.getElementById('ui-toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'ui-toast-container';
                container.className = 'ui-toast-container';
                container.setAttribute('aria-live', 'polite');
                document.body.appendChild(container);
            }
            var toast = document.createElement('div');
            toast.className = 'ui-toast ui-toast--' + type;
            toast.textContent = message;
            container.appendChild(toast);
            setTimeout(function() {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(20px)';
                toast.style.transition = 'all 0.2s';
                setTimeout(function() { toast.remove(); }, 200);
            }, duration);
        },
        success: function(msg, dur) { this.show(msg, 'success', dur); },
        error: function(msg, dur) { this.show(msg, 'error', dur); },
        warning: function(msg, dur) { this.show(msg, 'warning', dur); },
        info: function(msg, dur) { this.show(msg, 'info', dur); }
    };
})();
