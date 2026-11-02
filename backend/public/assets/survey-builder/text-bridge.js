// The bridge between the builder and the text of the survey. The textarea #survey-text always holds the current text
// (the form posts it, the code editor keeps it up to date); the builder reads it when its tab opens and writes every
// change back. With the code editor present the change goes in as a small edit, so the editor's own undo (Ctrl+Z in
// the "JSON" tab) can take it back and the cursor stays where it was.

/**
 * @param {HTMLTextAreaElement} area
 * @param {() => ({view?: object}|undefined)} getEditor the code editor, if there is one (window.OndisosSurveyEditor.last)
 */
export function createTextBridge(area, getEditor) {
    return {
        get() {
            return area.value;
        },

        /** Replaces the text; only the part that differs is changed. */
        set(text) {
            const old = area.value;
            if (old === text) { return; }
            let head = 0;
            const max = Math.min(old.length, text.length);
            while (head < max && old.charCodeAt(head) === text.charCodeAt(head)) { head += 1; }
            let tail = 0;
            while (tail < max - head && old.charCodeAt(old.length - 1 - tail) === text.charCodeAt(text.length - 1 - tail)) { tail += 1; }
            const editor = getEditor();
            if (editor && editor.view) {
                editor.view.dispatch({ changes: { from: head, to: old.length - tail, insert: text.slice(head, text.length - tail) } });
            } else {
                area.value = text;
            }
        },

        /** Call when the code editor becomes visible again (it cannot measure itself while hidden). */
        refresh() {
            const editor = getEditor();
            if (editor && editor.view && typeof editor.view.requestMeasure === 'function') { editor.view.requestMeasure(); }
        },
    };
}
