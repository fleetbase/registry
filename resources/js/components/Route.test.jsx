import { render, screen } from '@testing-library/react';
import Route, { releaseStops } from './Route';

describe('releaseStops', () => {
    it('marks the review stop as current while a release is in review', () => {
        const stops = releaseStops({ status: 'in_review' });
        expect(stops.map((s) => [s.key, s.state])).toEqual([
            ['uploaded', 'done'],
            ['checks', 'done'],
            ['submitted', 'done'],
            ['review', 'now'],
            ['live', 'todo'],
        ]);
    });

    it('fails the checks stop when checks failed and asks the publisher to submit when they passed', () => {
        expect(releaseStops({ status: 'failed_checks' }).find((s) => s.key === 'checks').state).toBe('fail');
        expect(releaseStops({ status: 'uploaded' }).find((s) => s.key === 'submitted').state).toBe('you');
    });

    it('renders each stop with a state for screen readers', () => {
        render(<Route stops={releaseStops({ status: 'rejected' })} label="Release route" />);
        expect(screen.getByRole('list', { name: 'Release route' })).toBeInTheDocument();
        expect(screen.getByText(', failed')).toBeInTheDocument();
    });
});
