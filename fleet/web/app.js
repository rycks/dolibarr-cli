// dolifleet dashboard: actions are POST requests with the token of the page in a header.
// No inline script (strict CSP); data shown by the page is escaped on the server, and the
// texts (questions, errors) come from the server in the language of the dashboard.
// Confirmation as in the console: a protected instance asks for its name, another one for a
// simple confirmation; the server and then the pilot check the answer again.
'use strict';

document.addEventListener('DOMContentLoaded', function () {
    var meta = document.querySelector('meta[name="dolifleet-token"]');
    var token = meta ? meta.getAttribute('content') : '';
    var failed = document.body.getAttribute('data-failed') || '%s';

    document.querySelectorAll('button.action').forEach(function (button) {
        button.addEventListener('click', function () {
            var body = new URLSearchParams();
            body.set('action', button.getAttribute('data-action'));
            body.set('target', button.getAttribute('data-target'));
            var mode = button.getAttribute('data-mode');
            var question = button.getAttribute('data-question') || '';
            if (mode === 'type') {
                var typed = window.prompt(question);
                if (typed === null) {
                    return;
                }
                body.set('confirm', typed.trim());
            } else if (mode === 'confirm') {
                if (!window.confirm(question)) {
                    return;
                }
                body.set('confirm', 'yes');
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
                        console.error('dolifleet action refused', response.status, text);
                        window.alert(text);
                    }
                });
            }).catch(function (error) {
                button.disabled = false;
                console.error('dolifleet action failed', error);
                window.alert(failed.replace('%s', String(error)));
            });
        });
    });
});
