/* WebHookARM settings screen: in-page tabs and copy buttons. */
(function () {
    'use strict';

    function init() {
        const tabs = document.querySelectorAll('.webhookarm-tab');
        const panels = document.querySelectorAll('.webhookarm-panel');

        function activateTab(targetPanel, updateHash) {
            let hasMatch = false;

            tabs.forEach(function (item) {
                const isTarget = item.getAttribute('data-panel') === targetPanel;
                item.classList.toggle('nav-tab-active', isTarget);
                item.setAttribute('aria-selected', isTarget ? 'true' : 'false');
                hasMatch = hasMatch || isTarget;
            });

            panels.forEach(function (panel) {
                const isTarget = panel.getAttribute('data-panel') === targetPanel;
                panel.classList.toggle('is-active', isTarget);
                panel.hidden = !isTarget;
            });

            if (hasMatch && updateHash) {
                window.location.hash = targetPanel;
            }
        }

        function panelFromHash() {
            return window.location.hash ? window.location.hash.replace('#', '') : 'webhook';
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                activateTab(tab.getAttribute('data-panel'), true);
            });
        });

        document.addEventListener('click', function (event) {
            const button = event.target.closest('[data-copy-target]');

            if (!button || !navigator.clipboard) {
                return;
            }

            const source = document.getElementById(button.getAttribute('data-copy-target'));

            if (source) {
                navigator.clipboard.writeText(source.textContent);
            }
        });

        activateTab(panelFromHash(), false);

        window.addEventListener('hashchange', function () {
            activateTab(panelFromHash(), false);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
