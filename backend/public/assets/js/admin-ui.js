/**
 * Kleine Hilfen der Verwaltungsoberfläche ohne Inline-Handler (Content-Security-Policy).
 *
 *   <form data-confirm="Wirklich löschen?">        Bestätigung vor dem Absenden
 *   <button data-confirm="Wirklich?">              Bestätigung vor dem Klick (Formular-Button, Link)
 *   <select data-autosubmit>                       Formular beim Ändern absenden
 *   <button data-reload>                           Seite neu laden
 *   <button data-print>                            Seite drucken
 *   <form data-unsaved-warning>                    Warnung beim Verlassen der Seite mit ungespeicherten Änderungen;
 *                                                  mit data-unsaved="1" gilt das Formular von Anfang an als geändert
 *                                                  (z. B. nach einem fehlgeschlagenen Speichern)
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
        var el = event.target.closest ? event.target.closest('[data-confirm], [data-reload], [data-print]') : null;
        if (!el) {
            return;
        }
        if (el.hasAttribute('data-print')) {
            window.print();
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

    // Unsaved changes: compare the fields (including those outside with form="…") with their state at page load.
    // Submitting the form itself leaves without a warning, unless it opens in another window (formtarget="_blank").
    var watched = Array.prototype.slice.call(document.querySelectorAll('form[data-unsaved-warning]'));
    var snapshot = function (form) {
        var parts = [];
        new FormData(form).forEach(function (value, name) {
            parts.push(name + '=' + (typeof value === 'string' ? value : value.name));
        });
        return parts.join('&');
    };
    var initial = watched.map(function (form) { return form.getAttribute('data-unsaved') === '1' ? null : snapshot(form); });
    var leaving = false;
    document.addEventListener('submit', function (event) {
        if (event.defaultPrevented || watched.indexOf(event.target) < 0) {
            return;
        }
        var target = (event.submitter && event.submitter.getAttribute('formtarget')) || event.target.getAttribute('target') || '';
        if (target === '' || target === '_self') {
            leaving = true;
        }
    });
    window.addEventListener('beforeunload', function (event) {
        if (leaving) {
            return;
        }
        for (var i = 0; i < watched.length; i++) {
            if (snapshot(watched[i]) !== initial[i]) {
                event.preventDefault();
                event.returnValue = '';
                return;
            }
        }
    });

    document.addEventListener('change', function (event) {
        var el = event.target;
        if (el.matches && el.matches('[data-autosubmit]') && el.form) {
            el.form.submit();
        }
    });
}());
