import { describe, expect, it, vi } from 'vitest';
import { useState } from 'react';
import { fireEvent, screen } from '@testing-library/react';
import { renderWithI18n, rubricFixture } from './_helpers';
import {
  computeRubricScore,
  selectionFromResult,
  selectionToResult,
} from '@/components/assignments/grading/utils';

vi.mock('@/lib/assignments/assignments-i18n', () => ({
  useAssignmentsI18n: () => ({ t: (_k: string, f?: string) => f ?? _k }),
}));

import { RubricGrader } from '@/components/assignments/grading/RubricGrader';

describe('computeRubricScore', () => {
  it('sums selected level points and scales to max grade', () => {
    const selection = { 'crit-1': 'lvl-1b', 'crit-2': 'lvl-2b' }; // 6 + 10 = 16 of 20
    const b = computeRubricScore(rubricFixture, selection, 100);
    expect(b.raw).toBe(16);
    expect(b.outOf).toBe(20);
    expect(b.scaled).toBe(80); // 16/20 * 100
    expect(b.complete).toBe(true);
    expect(b.selectedCount).toBe(2);
  });

  it('reports incomplete when a criterion is unscored', () => {
    const b = computeRubricScore(rubricFixture, { 'crit-1': 'lvl-1c' }, 100);
    expect(b.raw).toBe(10);
    expect(b.complete).toBe(false);
    expect(b.selectedCount).toBe(1);
  });

  it('round-trips selection <-> rubric_result', () => {
    const result = [
      { criterion_public_id: 'crit-1', level_public_id: 'lvl-1a' },
      { criterion_public_id: 'crit-2', level_public_id: 'lvl-2b' },
    ];
    expect(selectionToResult(selectionFromResult(result))).toEqual(result);
  });
});

describe('RubricGrader', () => {
  it('updates the computed score as levels are selected', () => {
    function Harness() {
      const [sel, setSel] = useState<Record<string, string>>({});
      return (
        <RubricGrader
          rubric={rubricFixture}
          selection={sel}
          maxGrade={100}
          onSelect={(c, l) => setSel((s) => ({ ...s, [c]: l }))}
        />
      );
    }
    renderWithI18n(<Harness />);
    // pick 'Excellent' (10) on Clarity and 'Strong' (10) on Evidence -> 20/20 -> 100
    fireEvent.click(screen.getByTestId('level-lvl-1c').querySelector('input')!);
    fireEvent.click(screen.getByTestId('level-lvl-2b').querySelector('input')!);
    const score = screen.getByTestId('rubric-score');
    expect(score).toHaveAttribute('data-raw', '20');
    expect(score).toHaveAttribute('data-scaled', '100');
    expect(score).toHaveAttribute('data-complete', 'true');
  });
});
