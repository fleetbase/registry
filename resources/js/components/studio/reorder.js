/**
 * Reordering helpers for the screenshot grid. A drag targets a *slot*: the gap before item
 * `slot` (0…length, where `length` is after the last item).
 */

/** A copy of the list with the item at `from` moved to index `to`. */
export function moveItem(list, from, to) {
    const items = [...list];
    if (from < 0 || from >= items.length) {
        return items;
    }
    const target = Math.max(0, Math.min(to, items.length - 1));
    const [item] = items.splice(from, 1);
    items.splice(target, 0, item);
    return items;
}

/** The index an item dragged from `from` ends up at when dropped into `slot`. */
export function slotToIndex(from, slot) {
    return slot > from ? slot - 1 : slot;
}

/** Whether dropping the item from `from` into `slot` changes nothing. */
export function isNoopDrop(from, slot) {
    return slot === from || slot === from + 1;
}

/** The slot a pointer at `x` over the item at `index` (with bounding `rect`) points to. */
export function slotAt(index, rect, x) {
    return x < rect.left + rect.width / 2 ? index : index + 1;
}

/** The list after dropping the item from `from` into `slot`, or null when nothing moves. */
export function dropInto(list, from, slot) {
    if (from === null || from === undefined || slot === null || slot === undefined || isNoopDrop(from, slot)) {
        return null;
    }
    return moveItem(list, from, slotToIndex(from, slot));
}

/** What a screen reader hears after a move: `Screenshot moved to position 1 of 4, the cover.` */
export function moveAnnouncement(label, to, total) {
    return `${label} moved to position ${to + 1} of ${total}${to === 0 ? ', the cover' : ''}.`;
}
