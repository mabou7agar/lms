/**
 * Deterministic seeding for the generative cover artwork.
 *
 * Artwork MUST be identical on the server and the client (no `Math.random` at render) or React
 * will report a hydration mismatch. We derive a stable 32-bit seed from the course identity and
 * draw with a small deterministic PRNG (mulberry32).
 */

/** FNV-1a string hash -> unsigned 32-bit int. Stable across server/client. */
export function hashString(input: string): number {
  let h = 2166136261 >>> 0;
  for (let i = 0; i < input.length; i += 1) {
    h ^= input.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return h >>> 0;
}

/** Deterministic PRNG in [0, 1). Seeded so a given course always renders the same artwork. */
export function mulberry32(seed: number): () => number {
  let a = seed >>> 0;
  return function next(): number {
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
