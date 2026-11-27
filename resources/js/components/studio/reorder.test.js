import { describe, expect, it } from 'vitest';
import { dropInto, isNoopDrop, moveAnnouncement, moveItem, slotAt, slotToIndex } from './reorder';

describe('screenshot reordering', () => {
    const list = ['a', 'b', 'c', 'd'];

    it('moves an item to an index without changing the input', () => {
        expect(moveItem(list, 0, 2)).toEqual(['b', 'c', 'a', 'd']);
        expect(moveItem(list, 3, 0)).toEqual(['d', 'a', 'b', 'c']);
        expect(moveItem(list, 1, 1)).toEqual(list);
        expect(moveItem(list, 1, 99)).toEqual(['a', 'c', 'd', 'b']);
        expect(moveItem(list, 9, 0)).toEqual(list);
        expect(list).toEqual(['a', 'b', 'c', 'd']);
    });

    it('turns a drop slot into the index the item lands at', () => {
        // Slots are the gaps before each item; slot 4 is after the last.
        expect(slotToIndex(0, 4)).toBe(3);
        expect(slotToIndex(3, 0)).toBe(0);
        expect(slotToIndex(1, 3)).toBe(2);
        expect(isNoopDrop(1, 1)).toBe(true);
        expect(isNoopDrop(1, 2)).toBe(true);
        expect(isNoopDrop(1, 3)).toBe(false);
    });

    it('drops into a slot, or answers null when nothing moves', () => {
        expect(dropInto(list, 0, 4)).toEqual(['b', 'c', 'd', 'a']);
        expect(dropInto(list, 3, 0)).toEqual(['d', 'a', 'b', 'c']);
        expect(dropInto(list, 2, 1)).toEqual(['a', 'c', 'b', 'd']);
        expect(dropInto(list, 2, 2)).toBeNull();
        expect(dropInto(list, 2, 3)).toBeNull();
        expect(dropInto(list, null, 1)).toBeNull();
    });

    it('picks the slot by which half of a tile the pointer is over', () => {
        const rect = { left: 100, width: 200 };
        expect(slotAt(2, rect, 150)).toBe(2);
        expect(slotAt(2, rect, 250)).toBe(3);
    });

    it('says where the screenshot landed', () => {
        expect(moveAnnouncement('Screenshot 3', 0, 4)).toBe('Screenshot 3 moved to position 1 of 4, the cover.');
        expect(moveAnnouncement('Screenshot “Labels”', 2, 4)).toBe('Screenshot “Labels” moved to position 3 of 4.');
    });
});
