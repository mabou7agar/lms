import { Fragment } from "react";
import { hashString, mulberry32 } from "./seed";
import { FAMILY_FIELD } from "./palette";
import type { CoverFamily } from "./types";

/**
 * Deterministic technical artwork for a course cover field. Rendered as a single lightweight SVG
 * (a few dozen primitives), seeded from the course id so every card is stable across renders and
 * SSR/hydration but no two courses look cloned. Purely decorative -> the SVG is aria-hidden and
 * the parent supplies the accessible name.
 */

const VB = 300; // square viewBox; the field clips it via preserveAspectRatio="xMidYMid slice"

type Pt = { x: number; y: number; r: number; gold: boolean };
type Edge = { x1: number; y1: number; x2: number; y2: number };

function AiArt({ rng, accent }: { rng: () => number; accent: string }) {
  const nodes: Pt[] = [];
  const goldAt = Math.floor(rng() * 14);
  for (let i = 0; i < 14; i += 1) {
    nodes.push({
      x: 70 + rng() * 210,
      y: 26 + rng() * 150,
      r: 1.1 + rng() * 1.9,
      gold: i === goldAt,
    });
  }
  const edges: Edge[] = [];
  nodes.forEach((a, i) => {
    nodes.forEach((b, j) => {
      if (j <= i) return;
      if (Math.hypot(a.x - b.x, a.y - b.y) < 64) {
        edges.push({ x1: a.x, y1: a.y, x2: b.x, y2: b.y });
      }
    });
  });
  return (
    <g fill="none">
      <g stroke={accent} strokeOpacity="0.22" strokeWidth="0.5">
        {edges.map((e, i) => (
          <line key={i} x1={e.x1} y1={e.y1} x2={e.x2} y2={e.y2} />
        ))}
      </g>
      {nodes.map((n, i) => (
        <Fragment key={i}>
          {n.gold ? (
            <circle cx={n.x} cy={n.y} r={n.r + 3} fill="var(--gold)" fillOpacity="0.18" />
          ) : null}
          <circle
            cx={n.x}
            cy={n.y}
            r={n.r}
            fill={n.gold ? "var(--gold)" : "#ffffff"}
            fillOpacity={n.gold ? 0.95 : 0.55}
          />
        </Fragment>
      ))}
    </g>
  );
}

function DataArt({ rng, accent }: { rng: () => number; accent: string }) {
  const cells: { x: number; y: number; a: number }[] = [];
  const cols = 11;
  const rows = 7;
  const stepX = VB / (cols + 1);
  const stepY = 176 / (rows + 1);
  for (let r = 1; r <= rows; r += 1) {
    for (let c = 1; c <= cols; c += 1) {
      const x = c * stepX;
      const y = r * stepY + 8;
      const a = Math.sin(x * 0.03) + Math.cos(y * 0.05) + (rng() - 0.5) * 0.5;
      cells.push({ x, y, a });
    }
  }
  const len = 8;
  return (
    <g fill="none" stroke={accent} strokeOpacity="0.24" strokeWidth="0.7" strokeLinecap="round">
      {cells.map((cell, i) => {
        const dx = Math.cos(cell.a) * len;
        const dy = Math.sin(cell.a) * len;
        return (
          <line
            key={i}
            x1={cell.x - dx / 2}
            y1={cell.y - dy / 2}
            x2={cell.x + dx / 2}
            y2={cell.y + dy / 2}
          />
        );
      })}
      <g stroke="#ffffff" strokeOpacity="0.08">
        <path d="M-20 150 Q 150 96 320 150" />
        <path d="M-20 176 Q 150 122 320 176" />
      </g>
    </g>
  );
}

function GovernanceArt({ rng, accent }: { rng: () => number; accent: string }) {
  const bays = 5;
  const gap = VB / bays;
  const baseY = 196;
  const paths: string[] = [];
  for (let i = 0; i < bays; i += 1) {
    const cx = gap * i + gap / 2 + (rng() - 0.5) * 8;
    const half = gap * 0.34;
    const springY = 96 + rng() * 14; // where the arch springs from the columns
    const topY = 40 + rng() * 10;
    // two columns + a semicircular arch bridging them
    paths.push(
      `M ${cx - half} ${baseY} L ${cx - half} ${springY} ` +
        `A ${half} ${springY - topY} 0 0 1 ${cx + half} ${springY} ` +
        `L ${cx + half} ${baseY}`,
    );
  }
  return (
    <g fill="none" stroke={accent} strokeOpacity="0.2" strokeWidth="0.7">
      {paths.map((d, i) => (
        <path key={i} d={d} />
      ))}
      <line x1="0" y1={baseY} x2={VB} y2={baseY} stroke="#ffffff" strokeOpacity="0.1" strokeWidth="0.7" />
    </g>
  );
}

function LeadershipArt({ rng, accent }: { rng: () => number; accent: string }) {
  const vx = 150 + (rng() - 0.5) * 40;
  const vy = 150;
  const rays: { x: number; y: number }[] = [];
  for (let i = 0; i <= 12; i += 1) {
    rays.push({ x: (VB / 12) * i, y: 300 });
  }
  const steps: string[] = [];
  for (let i = 0; i < 4; i += 1) {
    const w = 96 - i * 18;
    const y = 120 - i * 20;
    steps.push(`M ${vx - w} ${y} L ${vx + w} ${y}`);
  }
  return (
    <g fill="none">
      <g stroke={accent} strokeOpacity="0.16" strokeWidth="0.5">
        {rays.map((p, i) => (
          <line key={i} x1={p.x} y1={p.y} x2={vx} y2={vy} />
        ))}
      </g>
      <g stroke="#ffffff" strokeOpacity="0.14" strokeWidth="0.7">
        {steps.map((d, i) => (
          <path key={i} d={d} />
        ))}
      </g>
      <circle cx={vx} cy={vy} r="2.4" fill="var(--gold)" fillOpacity="0.9" />
      <circle cx={vx} cy={vy} r="6" fill="var(--gold)" fillOpacity="0.16" />
    </g>
  );
}

export function CoverArt({ family, seed, className }: { family: CoverFamily; seed: string; className?: string }) {
  const rng = mulberry32(hashString(`${family}:${seed}`));
  const accent = FAMILY_FIELD[family].accent;
  return (
    <svg
      viewBox={`0 0 ${VB} ${VB}`}
      className={className}
      preserveAspectRatio="xMidYMid slice"
      aria-hidden="true"
      focusable="false"
    >
      {family === "ai" ? <AiArt rng={rng} accent={accent} /> : null}
      {family === "data" ? <DataArt rng={rng} accent={accent} /> : null}
      {family === "governance" ? <GovernanceArt rng={rng} accent={accent} /> : null}
      {family === "leadership" ? <LeadershipArt rng={rng} accent={accent} /> : null}
    </svg>
  );
}
