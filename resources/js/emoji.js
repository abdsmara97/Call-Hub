/**
 * Emoji picker.
 *
 * A curated set rather than the full Unicode table: this is a work chat, and a
 * few hundred well-chosen pictographs are faster to scan than two thousand. The
 * groups lean towards what an operations, logistics and facilities business
 * actually reaches for.
 *
 * Deliberately no external library — a strict CSP blocks CDN scripts, and a
 * picker is not worth a dependency.
 */

export const QUICK_REACTIONS = ['👍', '🎉', '❤️', '😄', '👏', '✅', '👀', '🙏'];

const GROUPS = [
    {
        name: 'Reactions',
        emoji: [
            ['👍', 'thumbs up yes agree good ok approve'],
            ['👎', 'thumbs down no disagree bad reject'],
            ['👏', 'clap applause well done praise'],
            ['🙏', 'thanks please pray grateful'],
            ['❤️', 'heart love like'],
            ['🔥', 'fire hot great amazing'],
            ['🎉', 'party celebrate tada done'],
            ['✅', 'check tick done complete yes'],
            ['❌', 'cross no wrong fail'],
            ['👀', 'eyes looking watching seen'],
            ['💯', 'hundred perfect full'],
            ['🚀', 'rocket launch ship fast'],
        ],
    },
    {
        name: 'Faces',
        emoji: [
            ['😀', 'grin happy smile'],
            ['😄', 'smile happy joy'],
            ['😅', 'sweat nervous laugh phew'],
            ['😂', 'laugh cry funny lol'],
            ['🙂', 'slight smile ok'],
            ['😉', 'wink joke'],
            ['😊', 'blush happy warm'],
            ['😍', 'love heart eyes'],
            ['🤔', 'think hmm consider'],
            ['😐', 'neutral meh flat'],
            ['🙄', 'roll eyes really'],
            ['😴', 'sleep tired bored'],
            ['😭', 'cry sad sob'],
            ['😡', 'angry cross mad'],
            ['😱', 'shock scream fear'],
            ['🤯', 'mind blown shock'],
            ['🥳', 'party celebrate birthday'],
            ['😎', 'cool sunglasses'],
            ['🤝', 'handshake deal agree'],
            ['🫡', 'salute yes sir understood'],
        ],
    },
    {
        name: 'People',
        emoji: [
            ['👋', 'wave hello hi bye'],
            ['✋', 'hand stop halt'],
            ['🤚', 'raised hand'],
            ['👌', 'ok perfect fine'],
            ['✌️', 'peace victory'],
            ['🤞', 'fingers crossed hope luck'],
            ['💪', 'strong muscle effort'],
            ['🙌', 'hooray celebrate raise hands'],
            ['👉', 'point right this'],
            ['👇', 'point down below'],
            ['🧑‍🏭', 'worker factory operator'],
            ['👷', 'construction worker site hard hat'],
            ['🧑‍🔧', 'mechanic engineer maintenance'],
            ['🚚', 'lorry truck delivery'],
            ['🧑‍💻', 'developer it computer'],
            ['👮', 'security police guard'],
        ],
    },
    {
        name: 'Work',
        emoji: [
            ['📦', 'box parcel package stock'],
            ['🚛', 'lorry articulated freight haulage'],
            ['🚐', 'van fleet vehicle'],
            ['🏭', 'factory plant site'],
            ['🏢', 'office building'],
            ['🔧', 'spanner wrench fix maintenance'],
            ['🔨', 'hammer build repair'],
            ['⚙️', 'gear settings machine'],
            ['🧰', 'toolbox tools kit'],
            ['🪜', 'ladder access height'],
            ['📋', 'clipboard checklist rota'],
            ['📅', 'calendar date schedule shift'],
            ['🕗', 'clock time shift hours'],
            ['📈', 'chart up growth improve'],
            ['📉', 'chart down drop decline'],
            ['📊', 'bar chart report figures'],
            ['💰', 'money cost budget'],
            ['🧾', 'receipt invoice bill'],
            ['📌', 'pin important note'],
            ['📎', 'paperclip attach file'],
            ['🗂️', 'files folder records'],
            ['🖨️', 'printer print'],
        ],
    },
    {
        name: 'Alerts',
        emoji: [
            ['⚠️', 'warning caution careful'],
            ['🚨', 'alarm emergency urgent siren'],
            ['🚧', 'roadworks closed barrier blocked'],
            ['⛔', 'no entry stop forbidden'],
            ['🔴', 'red circle stop critical'],
            ['🟠', 'orange circle warning medium'],
            ['🟢', 'green circle go clear ok'],
            ['❗', 'exclamation important'],
            ['❓', 'question query unsure'],
            ['🆘', 'sos help emergency'],
            ['🔒', 'lock secure private closed'],
            ['🔓', 'unlock open access'],
            ['⏰', 'alarm clock deadline reminder'],
            ['🩹', 'plaster injury first aid'],
            ['🧯', 'fire extinguisher safety'],
            ['🦺', 'hi vis safety vest ppe'],
        ],
    },
    {
        name: 'Things',
        emoji: [
            ['☕', 'coffee break tea'],
            ['🍕', 'pizza food lunch'],
            ['🍰', 'cake birthday celebrate'],
            ['🎂', 'birthday cake'],
            ['🎁', 'gift present'],
            ['📱', 'phone mobile call'],
            ['💻', 'laptop computer'],
            ['📧', 'email mail message'],
            ['🔑', 'key access'],
            ['🚪', 'door entrance exit'],
            ['🅿️', 'parking car park'],
            ['☀️', 'sun sunny weather'],
            ['🌧️', 'rain wet weather'],
            ['❄️', 'snow cold ice frozen'],
            ['🌙', 'moon night shift'],
            ['⭐', 'star favourite good'],
        ],
    },
];

/** Flat list, used by search. */
const ALL = GROUPS.flatMap((group) =>
    group.emoji.map(([emoji, keywords]) => ({ emoji, keywords, group: group.name })),
);

export function registerEmojiPicker(Alpine) {
    Alpine.data('emojiPicker', (onPick = null) => ({
        open: false,
        query: '',
        groups: GROUPS.map((g) => ({ name: g.name, emoji: g.emoji.map(([e]) => e) })),

        toggle() {
            this.open = !this.open;
            this.query = '';

            if (this.open) {
                this.$nextTick(() => this.$refs.search?.focus());
            }
        },

        close() {
            this.open = false;
            this.query = '';
        },

        /** Search matches the keywords, never the pictograph itself. */
        get results() {
            const q = this.query.trim().toLowerCase();
            if (q === '') return null;

            return ALL.filter((item) => item.keywords.includes(q) || item.keywords.split(' ').some((k) => k.startsWith(q)))
                .slice(0, 48)
                .map((item) => item.emoji);
        },

        pick(emoji) {
            this.close();

            if (typeof onPick === 'function') {
                onPick(emoji);
                return;
            }

            // Default: hand it to whoever is listening on this element.
            this.$dispatch('emoji-picked', { emoji });
        },
    }));

    /**
     * Inserts at the caret rather than appending, so someone who has gone back
     * to fix a typo does not get the emoji stuck on the end.
     */
    Alpine.magic('insertEmoji', () => (textarea, emoji) => {
        if (!textarea) return emoji;

        const start = textarea.selectionStart ?? textarea.value.length;
        const end = textarea.selectionEnd ?? textarea.value.length;
        const next = textarea.value.slice(0, start) + emoji + textarea.value.slice(end);

        textarea.value = next;
        textarea.focus();
        textarea.selectionStart = textarea.selectionEnd = start + emoji.length;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));

        return next;
    });
}
