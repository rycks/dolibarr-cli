// dolifleet dashboard: actions are POST requests with the token of the page in a header.
// No inline script (strict CSP); data shown by the page is escaped on the server.
'use strict';

document.addEventListener('DOMContentLoaded', function () {
    var meta = document.querySelector('meta[name="dolifleet-token"]');
    var token = meta ? meta.getAttribute('content') : '';

    document.querySelectorAll('button.action').forEach(function (button) {
        button.addEventListener('click', function () {
            var action = button.getAttribute('data-action');
            var target = button.getAttribute('data-target');
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('target', target);
            if (action !== 'collect') {
                if (button.getAttribute('data-protected') === '1') {
                    var typed = window.prompt(action + ' on the protected instance ' + target + '. Type its name to confirm:');
                    if (typed === null) {
                        return;
                    }
                    body.set('confirm', typed);
                } else if (!window.confirm(action + ' on ' + target + '?')) {
                    return;
                }
            }
            button.disabled = true;
            fetch('/action', {
                method: 'POST',
                headers: {'X-Dolifleet-Token': token},
                body: body,
                credentials: 'same-origin'
            }).then(function (response) {
                return response.text().then(function (text) {
                    button.disabled = false;
                    if (response.status === 202) {
                        window.location.href = '/tasks';
                    } else {
                        window.alert(text);
                    }
                });
            }).catch(function (error) {
                button.disabled = false;
                console.error('dolifleet action failed', error);
                window.alert('Action failed: ' + error);
            });
        });
    });
});
