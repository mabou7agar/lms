import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, screen, within } from '@testing-library/react';

import type { GradebookQuery } from '@/lib/gradebook/gradebook-api';

import { assignmentCell, makePage, makeRow, quizCell, renderWithI18n } from './test-utils';

// ── Mocks (hoisted above the module graph) ──────────────────────────────────
const mocks = vi.hoisted(() => ({
  useGradebook: vi.fn(),
  useGradebookExport: vi.fn(),
  useAuth: vi.fn(),
  exportMutate: vi.fn(),
}));

vi.mock('@/lib/gradebook/gradebook-hooks', () => ({
  useGradebook: mocks.useGradebook,
  useGradebookExport: mocks.useGradebookExport,
}));

vi.mock('@/lib/auth/auth-context', () => ({ useAuth: mocks.useAuth }));

// gradebook-api imports the shared client — stub it so the module graph resolves.
vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), data: (x: unknown) => x },
  ApiRequestError: class ApiRequestError extends Error {},
}));

vi.mock('@/components/ui', () => ({
  Button: ({ children, ...props }: any) => <button {...props}>{children}</button>,
  Badge: ({ children, variant, ...props }: any) => (
    <span data-variant={variant} {...props}>
      {children}
    </span>
  ),
  Skeleton: (props: any) => <div {...props} />,
  Spinner: (props: any) => <div {...props} />,
  // Functional stub matching the real primitive's page/lastPage/onPageChange API.
  Pagination: ({ page, lastPage, onPageChange }: any) => (
    <div data-testid="gb-pagination-ctl">
      <button data-testid="gb-page-prev" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>
        prev
      </button>
      <button data-testid="gb-page-next" disabled={page >= lastPage} onClick={() => onPageChange(page + 1)}>
        next
      </button>
    </div>
  ),
  toast: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
}));

// Composed Drawer primitive (subpath, matching media-details-drawer).
vi.mock('@/components/ui/drawer', () => ({
  Drawer: ({ open, children }: any) => (open ? <div role="dialog">{children}</div> : null),
  DrawerContent: ({ children, ...props }: any) => <div {...props}>{children}</div>,
  DrawerHeader: ({ children }: any) => <div>{children}</div>,
  DrawerTitle: ({ children }: any) => <h2>{children}</h2>,
  DrawerDescription: ({ children }: any) => <p>{children}</p>,
  DrawerFooter: ({ children }: any) => <div>{children}</div>,
}));

import { GradebookView } from '@/components/gradebook/gradebook-view';

// ── Fixtures ─────────────────────────────────────────────────────────────────
const instructor = { id: 1, name: 'Prof', roles: ['instructor'] };
const learnerUser = { id: 9, name: 'Stu', roles: ['learner'] };

const page = makePage([
  makeRow(101, [
    assignmentCell({ passed: true, percent: 80, score: 8 }),
    quizCell({ passed: false, percent: 40, score: 4 }),
  ]),
  makeRow(102, [
    assignmentCell({ missing: true, status: null, score: null, percent: null, passed: null }),
    quizCell({ is_late: true, passed: true, percent: 90, score: 9 }),
  ]),
]);

function mockQuery(value: Record<string, unknown>) {
  mocks.useGradebook.mockReturnValue({
    data: undefined,
    isLoading: false,
    isError: false,
    isFetching: false,
    refetch: vi.fn(),
    ...value,
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  mocks.useAuth.mockReturnValue({ user: instructor });
  mocks.useGradebookExport.mockReturnValue({ mutate: mocks.exportMutate, isPending: false });
  mockQuery({ data: page });
});

describe('GradebookView', () => {
  it('renders learner rows and derived assignment + quiz columns', () => {
    renderWithI18n(<GradebookView publicId="crs_1" />);

    // columns derived from row cells
    expect(screen.getByTestId('gb-col-asg_1')).toHaveTextContent('Essay 1');
    expect(screen.getByTestId('gb-col-qz_1')).toHaveTextContent('Quiz 1');

    // one row per learner
    expect(screen.getByTestId('gb-row-101')).toBeInTheDocument();
    expect(screen.getByTestId('gb-row-102')).toBeInTheDocument();
    expect(screen.getByTestId('gb-open-101')).toHaveTextContent('Learner #101');
  });

  it('renders status / missing / late / pass-fail badges', () => {
    renderWithI18n(<GradebookView publicId="crs_1" />);

    // passed on row 101 assignment
    const row101 = within(screen.getByTestId('gb-row-101'));
    expect(row101.getByTestId('gb-status-passed')).toBeInTheDocument();
    expect(row101.getByTestId('gb-status-failed')).toBeInTheDocument();

    // missing + late on row 102
    const row102 = within(screen.getByTestId('gb-row-102'));
    expect(row102.getByTestId('gb-status-missing')).toBeInTheDocument();
    expect(row102.getByTestId('gb-status-late')).toBeInTheDocument();
  });

  it('applies the missing/late row filter and re-queries', () => {
    renderWithI18n(<GradebookView publicId="crs_1" />);

    fireEvent.change(screen.getByTestId('gb-filter-only'), { target: { value: 'missing' } });

    const lastCall = mocks.useGradebook.mock.calls.at(-1);
    expect(lastCall?.[0]).toBe('crs_1');
    expect((lastCall?.[1] as GradebookQuery).only).toBe('missing');
    // filter change resets to page 1
    expect((lastCall?.[1] as GradebookQuery).page).toBe(1);
  });

  it('paginates by re-querying with the next page', () => {
    renderWithI18n(<GradebookView publicId="crs_1" />);

    fireEvent.click(screen.getByTestId('gb-page-next'));

    const lastCall = mocks.useGradebook.mock.calls.at(-1);
    expect((lastCall?.[1] as GradebookQuery).page).toBe(2);
  });

  it('opens the learner detail drawer', () => {
    renderWithI18n(<GradebookView publicId="crs_1" />);

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('gb-open-101'));

    const dialog = screen.getByRole('dialog');
    expect(dialog).toBeInTheDocument();
    expect(within(dialog).getByTestId('gb-drawer-item-asg_1')).toBeInTheDocument();
    expect(within(dialog).getByTestId('gb-drawer-item-qz_1')).toBeInTheDocument();
  });

  it('CSV export action calls the export hook', () => {
    renderWithI18n(<GradebookView publicId="crs_1" />);

    fireEvent.click(screen.getByTestId('gb-export'));
    expect(mocks.exportMutate).toHaveBeenCalledTimes(1);
  });

  it('blocks non-instructors (permission gate)', () => {
    mocks.useAuth.mockReturnValue({ user: learnerUser });
    renderWithI18n(<GradebookView publicId="crs_1" />);

    expect(screen.getByTestId('gb-gate')).toBeInTheDocument();
    expect(screen.queryByTestId('gb-table')).not.toBeInTheDocument();
    // query disabled for unauthorized users
    const lastCall = mocks.useGradebook.mock.calls.at(-1);
    expect((lastCall?.[2] as { enabled?: boolean }).enabled).toBe(false);
  });

  it('renders the error state on API failure', () => {
    mockQuery({ data: undefined, isError: true });
    renderWithI18n(<GradebookView publicId="crs_1" />);

    expect(screen.getByTestId('gb-error')).toBeInTheDocument();
    expect(screen.queryByTestId('gb-table')).not.toBeInTheDocument();
  });

  it('renders the loading state', () => {
    mockQuery({ data: undefined, isLoading: true });
    renderWithI18n(<GradebookView publicId="crs_1" />);

    expect(screen.getByTestId('gb-loading')).toBeInTheDocument();
  });

  it('renders the empty state when there are no learners', () => {
    mockQuery({ data: makePage([], { total: 0, from: null, to: null, last_page: 1 }) });
    renderWithI18n(<GradebookView publicId="crs_1" />);

    expect(screen.getByTestId('gb-empty')).toBeInTheDocument();
  });

  it('renders Arabic labels and RTL when locale is ar', () => {
    renderWithI18n(<GradebookView publicId="crs_1" />, 'ar');
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('سجل الدرجات');
  });
});
