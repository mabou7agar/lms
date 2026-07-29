import { describe, expect, it, vi } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';

import { renderWithI18n } from '../render';
import { LessonNav } from '@/components/learning/player/LessonNav';
import type { RuntimeCurriculum } from '@/lib/learning/player-api';
import { lesson } from './player-test-helpers';

vi.mock('@/components/ui', () => ({
  Button: ({ children, as: As = 'button', ...rest }: any) => <As {...rest}>{children}</As>,
}));

function nav(): RuntimeCurriculum {
  return {
    course: { id: 'crs_1', title: 'C', slug: 'c' },
    enrollment: { id: 'enr_1', status: 'active', progress_percentage: 0 },
    sections: [
      {
        id: 'sec_1',
        title: 'S',
        lessons: [
          lesson({ id: 'lsn_1' }),
          lesson({ id: 'lsn_2' }),
          lesson({ id: 'lsn_3', locked: true, lock_reason: 'drip_not_released' }),
          lesson({ id: 'lsn_4' }),
        ],
      },
    ],
  };
}

describe('LessonNav', () => {
  it('navigates to the previous and next navigable lessons, skipping locked ones', () => {
    const onNavigate = vi.fn();
    renderWithI18n(
      <LessonNav curriculum={nav()} currentLessonId="lsn_2" onNavigate={onNavigate} />,
    );

    fireEvent.click(screen.getByTestId('nav-previous'));
    expect(onNavigate).toHaveBeenCalledWith('lsn_1');

    // lsn_3 is locked -> next lands on lsn_4.
    fireEvent.click(screen.getByTestId('nav-next'));
    expect(onNavigate).toHaveBeenCalledWith('lsn_4');
  });

  it('disables next at the end of the curriculum', () => {
    const onNavigate = vi.fn();
    renderWithI18n(
      <LessonNav curriculum={nav()} currentLessonId="lsn_4" onNavigate={onNavigate} />,
    );
    expect(screen.getByTestId('nav-next')).toBeDisabled();
    expect(screen.getByTestId('nav-previous')).not.toBeDisabled();
  });
});
