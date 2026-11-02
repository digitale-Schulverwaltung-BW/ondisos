// Undo and redo for the survey builder: snapshots of the survey text.
//
// The text is the single source of truth, so a step back is the earlier text, exactly; nothing is replayed or inverted.
// Each step remembers the selection before and after the change, so undo shows the element that changed.
// A change made outside the builder (in the JSON tab) is recorded like any other step when the builder sees it.
//
// Pure logic, no DOM: runs in the browser and under `node --test`.

/**
 * @typedef {{text:string, before:*, after:*}} Step   `before`/`after`: selection around the change that led to `text`
 * @param {{limit?:number, maxChars?:number}} [options] at most `limit` steps and `maxChars` characters of text are kept;
 *        the oldest steps go first (a survey may be 512 KB, a hundred copies of it would be too much for a browser tab)
 */
export function createHistory({ limit = 100, maxChars = 8 * 1024 * 1024 } = {}) {
    /** @type {Step[]} */
    let steps = [];
    let at = -1;

    function trim() {
        let chars = steps.reduce((sum, s) => sum + s.text.length, 0);
        while (steps.length > 1 && at > 0 && (steps.length > limit || chars > maxChars)) {
            chars -= steps[0].text.length;
            steps.shift();
            at -= 1;
        }
    }

    return {
        /** Starts over with `text` as the only state (a newly loaded survey). */
        reset(text, selected = null) {
            steps = [{ text, before: selected, after: selected }];
            at = 0;
        },
        /** The text of the current state (undefined before reset). */
        current() {
            return at >= 0 ? steps[at].text : undefined;
        },
        /**
         * Records a change that led to `text`. Steps that were undone are dropped (no redo after a new change).
         * The same text again records nothing.
         */
        record(text, { before = null, after = null } = {}) {
            if (at < 0) { this.reset(text, after); return; }
            if (steps[at].text === text) { return; }
            steps = steps.slice(0, at + 1);
            steps.push({ text, before, after });
            at += 1;
            trim();
        },
        canUndo: () => at > 0,
        canRedo: () => at >= 0 && at < steps.length - 1,
        /** @returns {{text:string, selected:*}|null} the earlier state, with the selection before the undone change */
        undo() {
            if (at <= 0) { return null; }
            const undone = steps[at];
            at -= 1;
            return { text: steps[at].text, selected: undone.before };
        },
        /** @returns {{text:string, selected:*}|null} the state again, with the selection after the change */
        redo() {
            if (at < 0 || at >= steps.length - 1) { return null; }
            at += 1;
            return { text: steps[at].text, selected: steps[at].after };
        },
        size: () => steps.length,
    };
}
