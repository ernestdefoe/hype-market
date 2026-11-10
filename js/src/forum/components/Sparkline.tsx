import Component from 'flarum/common/Component';

interface Attrs {
  points: Array<[number, number]>;
  /** The member's entry: a dot where they backed it. */
  entry?: { at: number; price: number } | null;
  className?: string;
  label?: string;
}

let gradients = 0;

/**
 * The price line: an SVG polyline over an area fill, scaled to its own box
 * (preserveAspectRatio none, non-scaling stroke). Colour comes from
 * currentColor, so the trend class and any theme decide it.
 */
export default class Sparkline extends Component<Attrs> {
  id = `HypeSpark-${++gradients}`;

  view() {
    const points = this.attrs.points.length ? this.attrs.points : [[0, 10] as [number, number]];
    const line = points.length === 1 ? [points[0], [points[0][0] + 1, points[0][1]] as [number, number]] : points;
    const t0 = line[0][0];
    const t1 = Math.max(t0 + 1, line[line.length - 1][0]);
    const prices = line.map((p) => p[1]).concat(this.attrs.entry ? [this.attrs.entry.price] : []);
    const lo = Math.min(...prices);
    const hi = Math.max(...prices, lo + 1);
    const x = (at: number) => ((at - t0) / (t1 - t0)) * 100;
    const y = (p: number) => 28 - ((p - lo) / (hi - lo)) * 24;
    const coords = line.map(([at, p]) => `${x(at).toFixed(2)},${y(p).toFixed(2)}`);
    const entry = this.attrs.entry;

    return (
      <svg
        className={'HypeSparkline ' + (this.attrs.className || '')}
        viewBox="0 0 100 30"
        preserveAspectRatio="none"
        role={this.attrs.label ? 'img' : undefined}
        aria-label={this.attrs.label}
        aria-hidden={this.attrs.label ? undefined : 'true'}
        focusable="false"
      >
        <defs>
          <linearGradient id={this.id} x1="0" x2="0" y1="0" y2="1">
            <stop offset="0" stop-color="currentColor" stop-opacity="0.28" />
            <stop offset="1" stop-color="currentColor" stop-opacity="0" />
          </linearGradient>
        </defs>
        <polygon points={`0,30 ${coords.join(' ')} 100,30`} fill={`url(#${this.id})`} />
        <polyline
          points={coords.join(' ')}
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          vector-effect="non-scaling-stroke"
          stroke-linejoin="round"
          stroke-linecap="round"
        />
        {entry && entry.at >= t0 ? (
          <line
            className="HypeSparkline-entry"
            x1={x(Math.min(entry.at, t1))}
            x2={x(Math.min(entry.at, t1))}
            y1="0"
            y2="30"
            stroke="currentColor"
            stroke-width="1"
            stroke-dasharray="2 2"
            vector-effect="non-scaling-stroke"
          />
        ) : null}
      </svg>
    );
  }
}
