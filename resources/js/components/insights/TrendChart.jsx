import { useEffect, useId, useRef, useState } from 'react';
import { bucketLabel, compact, niceMax, ticks } from './insights';

const HEIGHT = 240;
const PAD = { top: 12, right: 16, bottom: 28, left: 40 };

/** The two lines, in plot order. Uninstalls are dashed so the pair never relies on colour alone. */
export const SERIES = [
    { key: 'installs', label: 'Installs', className: 'ins-installs' },
    { key: 'uninstalls', label: 'Uninstalls', className: 'ins-uninstalls', dashed: true },
];

/** The chart's width follows its container; SSR and jsdom get the default. */
function useWidth(fallback = 640) {
    const ref = useRef(null);
    const [width, setWidth] = useState(fallback);
    useEffect(() => {
        if (!ref.current || typeof ResizeObserver === 'undefined') {
            return undefined;
        }
        const observer = new ResizeObserver(([entry]) => setWidth(Math.max(280, Math.round(entry.contentRect.width))));
        observer.observe(ref.current);
        return () => observer.disconnect();
    }, []);
    return [ref, width];
}

/**
 * Installs and uninstalls per day, week or month, as two lines on one axis, with a crosshair
 * readout on hover and arrow keys, and the same numbers as a table under it.
 */
export default function TrendChart({ series = [], group = 'day', partialFirst = false, partialLast = false }) {
    const [ref, width] = useWidth();
    const [active, setActive] = useState(null);
    const id = useId();

    const count = series.length;
    const plotWidth = width - PAD.left - PAD.right;
    const plotHeight = HEIGHT - PAD.top - PAD.bottom;
    const max = niceMax(Math.max(0, ...series.flatMap((row) => SERIES.map((s) => row[s.key] ?? 0))));
    const x = (index) => PAD.left + (count <= 1 ? plotWidth / 2 : (index / (count - 1)) * plotWidth);
    const y = (value) => PAD.top + plotHeight - (value / max) * plotHeight;
    const labelled = count <= 1 ? [0] : [...new Set([0, Math.floor((count - 1) / 2), count - 1])];
    const point = active !== null ? series[active] : null;
    // The bucket holding today is still filling up: draw its segment faint and its dot open.
    // A bucket the range only partly covers (today's, or a week or month cut by the range) is drawn
    // faint, with an open dot at the end, so a short bucket never reads as a drop.
    const first = partialFirst && count > 2 ? 1 : 0;
    const last = partialLast && count > 1 ? count - 1 : count;
    const label = (index) => `${bucketLabel(series[index].date, group)}${partialLast && index === count - 1 ? ' (so far)' : partialFirst && index === 0 ? ' (part)' : ''}`;
    const note = partialFirst && partialLast ? `The first ${group} is partial and the last is still in progress.` : partialLast ? `The last ${group} is still in progress.` : partialFirst ? `The first ${group} is partial.` : null;
    const points = (key, from, to) => series.slice(from, to).map((row, offset) => `${x(from + offset)},${y(row[key] ?? 0)}`).join(' ');

    function pick(event) {
        const box = event.currentTarget.getBoundingClientRect();
        const ratio = box.width ? (event.clientX - box.left) / box.width : 0;
        const position = ((ratio * width - PAD.left) / plotWidth) * (count - 1);
        setActive(Math.min(count - 1, Math.max(0, Math.round(position))));
    }

    function keys(event) {
        const move = {
            ArrowRight: (current) => current + 1,
            ArrowLeft: (current) => current - 1,
            Home: () => 0,
            End: () => count - 1,
        }[event.key];
        if (!move) {
            return;
        }
        event.preventDefault();
        setActive((current) => Math.min(count - 1, Math.max(0, move(current ?? count - 1))));
    }

    return (
        <figure className="ins-chart" style={{ margin: 0 }} data-chart="trend">
            <div className="ins-legend" aria-hidden="true">
                {SERIES.map((s) => (
                    <span key={s.key} className="row" style={{ gap: 6 }}>
                        <svg width="18" height="6" className={s.className}>
                            <line x1="1" y1="3" x2="17" y2="3" strokeWidth="2" strokeLinecap="round" strokeDasharray={s.dashed ? '4 3' : undefined} />
                        </svg>
                        {s.label}
                    </span>
                ))}
                {note && <span className="muted">{note}</span>}
            </div>
            <div ref={ref} style={{ position: 'relative' }}>
                <svg width={width} height={HEIGHT} viewBox={`0 0 ${width} ${HEIGHT}`} role="img" aria-labelledby={`${id}-desc`} style={{ display: 'block', maxWidth: '100%' }}>
                    <desc id={`${id}-desc`}>
                        Installs and uninstalls per {group}: {series.reduce((sum, row) => sum + row.installs, 0)} installs and {series.reduce((sum, row) => sum + row.uninstalls, 0)} uninstalls over {count} {group}
                        {count === 1 ? '' : 's'}. The table below lists every value.
                    </desc>
                    <g className="ins-grid">
                        {ticks(max).map((tick) => (
                            <g key={tick}>
                                <line x1={PAD.left} x2={width - PAD.right} y1={y(tick)} y2={y(tick)} />
                                <text x={PAD.left - 8} y={y(tick)} dy="0.32em" textAnchor="end">
                                    {compact(tick)}
                                </text>
                            </g>
                        ))}
                        {labelled.map((index) => (
                            <text key={index} x={x(index)} y={HEIGHT - 8} textAnchor={count > 1 && index === 0 ? 'start' : count > 1 && index === count - 1 ? 'end' : 'middle'}>
                                {series[index] ? bucketLabel(series[index].date, group).replace('Week of ', '') : ''}
                            </text>
                        ))}
                    </g>
                    {SERIES.map((s) => (
                        <g key={s.key} className={s.className}>
                            {count > 1 && (
                                <polyline
                                    fill="none"
                                    strokeWidth="2"
                                    strokeLinejoin="round"
                                    strokeLinecap="round"
                                    strokeDasharray={s.dashed ? '5 4' : undefined}
                                    points={points(s.key, first, last)}
                                />
                            )}
                            {first > 0 && <polyline className="ins-partial" fill="none" strokeWidth="2" strokeLinecap="round" strokeDasharray="2 4" points={points(s.key, 0, 2)} />}
                            {last < count && <polyline className="ins-partial" fill="none" strokeWidth="2" strokeLinecap="round" strokeDasharray="2 4" points={points(s.key, count - 2, count)} />}
                            {count > 0 && <circle className={`ins-dot${partialLast ? ' ins-open' : ''}`} cx={x(count - 1)} cy={y(series[count - 1][s.key] ?? 0)} r="4" />}
                        </g>
                    ))}
                    {point && (
                        <g className="ins-cross" aria-hidden="true">
                            <line x1={x(active)} x2={x(active)} y1={PAD.top} y2={PAD.top + plotHeight} />
                            {SERIES.map((s) => (
                                <circle key={s.key} className={`ins-dot ${s.className}`} cx={x(active)} cy={y(point[s.key] ?? 0)} r="4" />
                            ))}
                        </g>
                    )}
                    {count > 0 && (
                        <rect
                            x={0}
                            y={0}
                            width={width}
                            height={HEIGHT}
                            fill="transparent"
                            tabIndex={0}
                            role="slider"
                            aria-label={`Installs and uninstalls by ${group}`}
                            aria-valuemin={0}
                            aria-valuemax={count - 1}
                            aria-valuenow={active ?? 0}
                            aria-valuetext={point ? `${label(active)}: ${point.installs} installs, ${point.uninstalls} uninstalls` : 'Use the arrow keys to read each value'}
                            onPointerMove={pick}
                            onPointerLeave={() => setActive(null)}
                            onFocus={() => setActive((current) => current ?? count - 1)}
                            onBlur={() => setActive(null)}
                            onKeyDown={keys}
                            style={{ outline: 'none', cursor: 'crosshair' }}
                        />
                    )}
                </svg>
                {point && (
                    <div className="ins-tip" role="status" style={{ left: Math.min(Math.max(x(active), 90), width - 90) }} data-tooltip>
                        <strong className="xs">{label(active)}</strong>
                        {SERIES.map((s) => (
                            <span key={s.key} className="row" style={{ gap: 6 }}>
                                <svg width="14" height="6" className={s.className} aria-hidden="true">
                                    <line x1="1" y1="3" x2="13" y2="3" strokeWidth="2" strokeLinecap="round" strokeDasharray={s.dashed ? '3 2' : undefined} />
                                </svg>
                                <strong className="num">{point[s.key]}</strong>
                                <span className="muted">{s.label.toLowerCase()}</span>
                            </span>
                        ))}
                        {point.updates > 0 && <span className="xs muted">{point.updates} updated</span>}
                    </div>
                )}
            </div>
            <details className="ins-table">
                <summary className="small">Show as a table</summary>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th scope="col">{group === 'day' ? 'Day' : group === 'week' ? 'Week' : 'Month'}</th>
                                <th scope="col" style={{ textAlign: 'right' }}>
                                    Installs
                                </th>
                                <th scope="col" style={{ textAlign: 'right' }}>
                                    Updates
                                </th>
                                <th scope="col" style={{ textAlign: 'right' }}>
                                    Uninstalls
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {series.map((row) => (
                                <tr key={row.date}>
                                    <td>{bucketLabel(row.date, group)}</td>
                                    <td className="num" style={{ textAlign: 'right' }}>
                                        {row.installs}
                                    </td>
                                    <td className="num" style={{ textAlign: 'right' }}>
                                        {row.updates}
                                    </td>
                                    <td className="num" style={{ textAlign: 'right' }}>
                                        {row.uninstalls}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </details>
        </figure>
    );
}
