import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import { makeQueueRow, renderWithI18n } from './_helpers';

const h = vi.hoisted(() => ({ useGradingQueue: vi.fn() }));

vi.mock('@/lib/assignments/assignments-i18n', () => ({
  useAssignmentsI18n: () => ({ t: (_k: string, f?: string) => f ?? _k }),
}));
vi.mock('@/components/ui', () => ({
  Button: ({ children, ...props }: any) => <button {...props}>{children}</button>,
}));
vi.mock('@/lib/assignments/assignments-hooks', () => ({
  useGradingQueue: (...args: unknown[]) => h.useGradingQueue(...args),
}));

import { GradingQueue } from '@/components/assignments/grading/GradingQueue';

function queueResult(rows = [makeQueueRow(), makeQueueRow({ id: 'sub-2', learner_id: 7, is_late: true })]) {
  return {
    data: { data: rows, meta: { current_page: 1, last_page: 3, per_page: 20, total: 5 } },
    isLoading: false,
    isError: false,
    isFetching: false,
    refetch: vi.fn(),
  };
}

describe('GradingQueue', () => {
  beforeEach(() => {
    h.useGradingQueue.mockReset();
    h.useGradingQueue.mockReturnValue(queueResult());
  });

  it('renders rows and total', () => {
    renderWithI18n(<GradingQueue assignmentId="asg-1" maxGrade={100} />);
    expect(screen.getByTestId('queue-rows').querySelectorAll('li')).toHaveLength(2);
    expect(screen.getByTestId('queue-total')).toHaveTextContent('5');
    expect(screen.getByTestId('queue-late-sub-2')).toBeInTheDocument();
  });

  it('passes the filter through to the hook when a filter tab is clicked', () => {
    renderWithI18n(<GradingQueue assignmentId="asg-1" />);
    fireEvent.click(screen.getByTestId('queue-filter-missing'));
    const lastCall = h.useGradingQueue.mock.calls.at(-1);
    expect(lastCall?.[0]).toBe('asg-1');
    expect(lastCall?.[1]).toEqual(expect.objectContaining({ only: 'missing', page: 1 }));
  });

  it('advances the page via pagination', () => {
    renderWithI18n(<GradingQueue assignmentId="asg-1" />);
    fireEvent.click(screen.getByTestId('queue-next'));
    const lastCall = h.useGradingQueue.mock.calls.at(-1);
    expect(lastCall?.[1]).toEqual(expect.objectContaining({ page: 2 }));
  });

  it('selects a submission', () => {
    const onSelect = vi.fn();
    renderWithI18n(<GradingQueue assignmentId="asg-1" onSelect={onSelect} />);
    fireEvent.click(screen.getByTestId('queue-row-sub-1'));
    expect(onSelect).toHaveBeenCalledWith('sub-1', expect.objectContaining({ id: 'sub-1' }));
  });

  it('renders an empty state', () => {
    h.useGradingQueue.mockReturnValue({ ...queueResult([]), data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } } });
    renderWithI18n(<GradingQueue assignmentId="asg-1" />);
    expect(screen.getByTestId('queue-empty')).toBeInTheDocument();
  });
});
