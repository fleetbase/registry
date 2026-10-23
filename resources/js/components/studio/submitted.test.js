import { describe, expect, it } from 'vitest';
import { daysAgo, reviewDay, submittedAgo, withSubmissionDates } from './submitted';
import { releaseStops } from '../Route';
import { groupInbox } from './inbox';

const now = new Date('2026-10-14T12:00:00Z');

describe('submitted dates', () => {
    it('says how long ago a release was submitted', () => {
        expect(daysAgo('2026-10-14T08:00:00Z', now)).toBe('today');
        expect(daysAgo('2026-10-13T08:00:00Z', now)).toBe('yesterday');
        expect(daysAgo('2026-10-12T08:00:00Z', now)).toBe('2 days ago');
        expect(daysAgo(null, now)).toBe('');
        expect(submittedAgo({ submitted_at: '2026-10-12T08:00:00Z' }, now)).toBe('Submitted 2 days ago');
        expect(submittedAgo({ submission: { submitted_at: '2026-10-12T08:00:00Z' } }, now)).toBe('Submitted 2 days ago');
        expect(submittedAgo({ submitted_at: null }, now)).toBe('');
    });

    it('counts review days from 1', () => {
        expect(reviewDay({ submitted_at: '2026-10-14T08:00:00Z' }, now)).toBe(1);
        expect(reviewDay({ submitted_at: '2026-10-12T08:00:00Z' }, now)).toBe(2);
        expect(reviewDay({}, now)).toBeNull();
    });

    it('puts the dates on the route', () => {
        const release = { status: 'in_review', submitted_at: '2026-10-12T08:00:00Z' };
        const stops = withSubmissionDates(releaseStops(release), release, { now });
        expect(stops.find((s) => s.key === 'submitted').sub).toBe('2 days ago');
        expect(stops.find((s) => s.key === 'review')).toMatchObject({ state: 'now', sub: 'day 2' });

        const approved = { status: 'approved', submitted_at: '2026-10-12T08:00:00Z' };
        expect(withSubmissionDates(releaseStops(approved), approved, { now, format: () => '12 Oct' }).find((s) => s.key === 'review').sub).toBe('approved');

        // A withdrawn release (back to uploaded) keeps "your move".
        const withdrawn = { status: 'uploaded', submission: { submitted_at: '2026-10-12T08:00:00Z' } };
        expect(withSubmissionDates(releaseStops(withdrawn), withdrawn, { now }).find((s) => s.key === 'submitted').sub).toBe('your move');
    });

    it('shows the submitted age in the inbox', () => {
        const listing = (slug, release) => ({ id: slug, slug, name: slug, status: 'published', releases: [release], readiness: { ready: true, missing: [] } });
        const { fleetbase } = groupInbox(
            [
                listing('dock', { id: 'a', version: '0.3.0', status: 'in_review', created_at: '2026-10-10T00:00:00Z', submitted_at: '2026-10-12T08:00:00Z' }),
                listing('weigh', { id: 'b', version: '3.0.0', status: 'approved', created_at: '2026-10-10T00:00:00Z', submitted_at: '2026-10-11T08:00:00Z', decided_at: '2026-10-13T09:00:00Z' }),
                listing('totes', { id: 'c', version: '1.0.0', status: 'submitted', created_at: '2026-10-10T00:00:00Z' }),
            ],
            now
        );
        expect(fleetbase.map((item) => item.detail)).toEqual([
            'Submitted 2 days ago. Reviews usually take a few business days.',
            'Approved yesterday. It goes live when Fleetbase publishes it.',
            'Reviews usually take a few business days.',
        ]);
    });
});
