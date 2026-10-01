// Survey editor: CodeMirror 6 (JSON) in place of the plain textarea of form_survey.php.
//
// window.OndisosSurveyEditor.attach(textarea, { diagnostics: [{line, message, severity}] })
//   - hides the textarea and shows the editor instead; the textarea always holds the current text,
//     so the surrounding form (submit, copy, "unsaved changes" warning) works unchanged
//   - marks JSON syntax errors while typing, and the findings the server reported for the text
//   - returns { goto(line), focus() }
import { EditorState } from '@codemirror/state';
import { EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter, drawSelection } from '@codemirror/view';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { syntaxHighlighting, defaultHighlightStyle, bracketMatching, foldGutter, foldKeymap, indentOnInput } from '@codemirror/language';
import { json } from '@codemirror/lang-json';
import { linter, lintGutter, lintKeymap } from '@codemirror/lint';

// Marks JSON syntax errors while typing, but only where the browser says where they are. Some browsers report no
// position; then nothing is marked (the "Prüfen" button gives the exact line from the server in every case).
function syntaxDiagnostics() {
    return linter(function (view) {
        var text = view.state.doc.toString();
        if (text.trim() === '') {
            return [];
        }
        try {
            JSON.parse(text);
            return [];
        } catch (e) {
            var message = String(e && e.message || e);
            var pos = null;
            var m = /position (\d+)/.exec(message);
            if (m) {
                pos = parseInt(m[1], 10);
            } else if ((m = /line (\d+) column (\d+)/.exec(message))) {
                var line = Math.min(parseInt(m[1], 10), view.state.doc.lines);
                pos = Math.min(view.state.doc.line(line).from + parseInt(m[2], 10) - 1, view.state.doc.length);
            }
            if (pos === null) {
                return [];
            }
            pos = Math.max(0, Math.min(pos, view.state.doc.length));
            return [{ from: pos, to: Math.min(pos + 1, view.state.doc.length), severity: 'error', message: message, source: 'JSON' }];
        }
    });
}

function serverDiagnostics(diagnostics) {
    return linter(function (view) {
        var doc = view.state.doc;
        var out = [];
        (diagnostics || []).forEach(function (d) {
            if (!d.line || d.line < 1 || d.line > doc.lines) {
                return;
            }
            var line = doc.line(d.line);
            out.push({ from: line.from, to: line.to, severity: d.severity === 'warning' ? 'warning' : 'error', message: d.message, source: 'Ondisos' });
        });
        return out;
    }, { needsRefresh: null });
}

function attach(textarea, options) {
    options = options || {};

    var parent = document.createElement('div');
    parent.className = 'se-cm form-control p-0';
    textarea.parentNode.insertBefore(parent, textarea);
    textarea.style.display = 'none';

    var view = new EditorView({
        parent: parent,
        state: EditorState.create({
            doc: textarea.value,
            extensions: [
                lineNumbers(),
                highlightActiveLineGutter(),
                highlightActiveLine(),
                drawSelection(),
                history(),
                foldGutter(),
                indentOnInput(),
                bracketMatching(),
                syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
                json(),
                syntaxDiagnostics(),
                serverDiagnostics(options.diagnostics),
                lintGutter(),
                keymap.of([].concat(defaultKeymap, historyKeymap, foldKeymap, lintKeymap, [indentWithTab])),
                EditorView.updateListener.of(function (update) {
                    if (update.docChanged) {
                        textarea.value = update.state.doc.toString();
                    }
                }),
                EditorView.theme({
                    '&': { fontSize: '0.8rem', height: '32rem' },
                    '.cm-scroller': { fontFamily: 'var(--bs-font-monospace, monospace)', overflow: 'auto' },
                }),
            ],
        }),
    });

    var api = {
        view: view,
        focus: function () { view.focus(); },
        goto: function (line) {
            var doc = view.state.doc;
            if (line < 1 || line > doc.lines) {
                return;
            }
            var l = doc.line(line);
            view.dispatch({ selection: { anchor: l.from, head: l.to }, effects: EditorView.scrollIntoView(l.from, { y: 'center' }) });
            view.focus();
        },
    };
    window.OndisosSurveyEditor.last = api; // handy for debugging in the browser console
    return api;
}

window.OndisosSurveyEditor = { attach: attach };
