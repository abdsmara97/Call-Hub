/**
 * @mention autocomplete for the composer.
 *
 * Candidates are rendered into the page by the server from the room's own
 * membership. That is the security property worth keeping: there is no
 * user-search endpoint to authorise, rate-limit, or leak names from, and the
 * list can never exceed the room the author is already allowed to read.
 */

const MAX_RESULTS = 8;
const MAX_QUERY = 30;

export function registerMentionAutocomplete(Alpine) {
    Alpine.data('mentionAutocomplete', (candidates = []) => ({
        people: candidates,
        open: false,
        query: '',
        at: null, // character offset of the '@' being completed
        highlighted: 0,

        /**
         * Gated on there being an active '@', NOT on `open` — scan() decides
         * whether to open by asking how many results there are, so keying this
         * off `open` deadlocks: it returns nothing, so nothing ever opens.
         */
        get results() {
            if (this.at === null) return [];

            const q = this.query.toLowerCase();

            const matches = this.people.filter((p) => p.name.toLowerCase().includes(q));

            // Someone whose name *starts* with what you typed is far more
            // likely to be who you meant.
            matches.sort((a, b) => {
                const aStarts = a.name.toLowerCase().split(' ').some((w) => w.startsWith(q));
                const bStarts = b.name.toLowerCase().split(' ').some((w) => w.startsWith(q));

                if (aStarts !== bStarts) return aStarts ? -1 : 1;

                return a.name.localeCompare(b.name);
            });

            return matches.slice(0, MAX_RESULTS);
        },

        /** Re-read the caret on every keystroke and decide whether to offer names. */
        scan() {
            const box = this.$refs.composer;
            if (! box) return this.close();

            const upToCaret = box.value.slice(0, box.selectionStart ?? 0);
            const at = upToCaret.lastIndexOf('@');

            if (at === -1) return this.close();

            // The '@' has to start a word, matching how the server parses it.
            const before = at === 0 ? '' : upToCaret[at - 1];
            if (before && /[\p{L}\p{N}]/u.test(before)) return this.close();

            const query = upToCaret.slice(at + 1);

            // A newline or an over-long run means they moved on and the '@'
            // was just an at-sign.
            if (query.includes('\n') || query.length > MAX_QUERY) return this.close();

            this.at = at;
            this.query = query;
            this.highlighted = 0;
            this.open = this.results.length > 0;
        },

        close() {
            this.open = false;
            this.query = '';
            this.at = null;
            this.highlighted = 0;
        },

        move(delta) {
            if (! this.open) return;

            const count = this.results.length;
            this.highlighted = (this.highlighted + delta + count) % count;
        },

        choose(person = null) {
            const pick = person ?? this.results[this.highlighted];
            const box = this.$refs.composer;

            if (! pick || ! box || this.at === null) return this.close();

            const caret = box.selectionStart ?? box.value.length;
            const insert = `@${pick.name} `;
            const next = box.value.slice(0, this.at) + insert + box.value.slice(caret);
            const cursor = this.at + insert.length;

            box.value = next;
            box.focus();
            box.selectionStart = box.selectionEnd = cursor;

            // wire:model only learns about this if an input event is raised.
            box.dispatchEvent(new Event('input', { bubbles: true }));

            this.close();
        },
    }));
}
