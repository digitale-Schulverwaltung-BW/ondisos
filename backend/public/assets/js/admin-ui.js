/**
 * Kleine Hilfen der Verwaltungsoberfläche ohne Inline-Handler (Content-Security-Policy).
 *
 *   <form data-confirm="Wirklich löschen?">        Bestätigung vor dem Absenden
 *   <button data-confirm="Wirklich?">              Bestätigung vor dem Klick (Formular-Button, Link)
 *   <select data-autosubmit>                       Formular beim Ändern absenden
 *   <button data-reload>                           Seite neu laden
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.matches && form.matches('form[data-confirm]') && !window.confirm(form.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', function (event) {
        var el = event.target.closest ? event.target.closest('[data-confirm], [data-reload]') : null;
        if (!el) {
            return;
        }
        if (el.hasAttribute('data-reload')) {
            window.location.reload();
            return;
        }
        if (el.tagName !== 'FORM' && !window.confirm(el.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    document.addEventListener('change', function (event) {
        var el = event.target;
        if (el.matches && el.matches('[data-autosubmit]') && el.form) {
            el.form.submit();
        }
    });
}());
