// Survey editor (form_survey.php): small helpers around the textarea. Works without CodeMirror.
(function () {
    'use strict';

    var area = document.getElementById('survey-text');
    var form = document.getElementById('survey-form');
    if (!area || !form) {
        return;
    }

    // "JSON kopieren"
    var copy = document.getElementById('se-copy');
    if (copy) {
        copy.addEventListener('click', function () {
            var done = function () {
                var label = copy.textContent;
                copy.textContent = '✓';
                setTimeout(function () { copy.textContent = label; }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(area.value).then(done, function () { area.select(); document.execCommand('copy'); done(); });
            } else {
                area.select();
                document.execCommand('copy');
                done();
            }
        });
    }

    // "Zeile 12" in the report: select that line in the editor.
    document.querySelectorAll('.se-goto').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            var line = parseInt(link.getAttribute('data-line'), 10);
            if (!line) {
                return;
            }
            var lines = area.value.split('\n');
            var start = 0;
            for (var i = 0; i < line - 1 && i < lines.length; i++) {
                start += lines[i].length + 1;
            }
            var end = start + (lines[line - 1] || '').length;
            area.focus();
            area.setSelectionRange(start, end);
            var lineHeight = parseFloat(getComputedStyle(area).lineHeight) || 16;
            area.scrollTop = Math.max(0, (line - 4) * lineHeight);
            area.scrollIntoView({ block: 'center', behavior: 'smooth' });
        });
    });

    // Warn before leaving with unsaved changes.
    var initial = area.value;
    var submitting = false;
    form.addEventListener('submit', function () { submitting = true; });
    window.addEventListener('beforeunload', function (event) {
        if (!submitting && area.value !== initial) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    // Tab inserts two spaces instead of leaving the field.
    area.addEventListener('keydown', function (event) {
        if (event.key === 'Tab' && !event.shiftKey && !event.ctrlKey && !event.metaKey && !event.altKey) {
            event.preventDefault();
            var s = area.selectionStart;
            area.value = area.value.slice(0, s) + '  ' + area.value.slice(area.selectionEnd);
            area.selectionStart = area.selectionEnd = s + 2;
        }
    });
})();
