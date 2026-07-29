import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { makeInstructorSubmission, renderWithI18n } from './_helpers';

const h = vi.hoisted(() => ({
  submission: null as any,
  refetch: vi.fn(),
  grade: vi.fn(async () => undefined),
  release: vi.fn(async () => undefined),
  requestChanges: vi.fn(async () => undefined),
  gradeIsError: false,
}));

vi.mock('@/lib/assignments/assignments-i18n', () => ({
  useAssignmentsI18n: () => ({ t: (_k: string, f?: string) => f ?? _k }),
}));
vi.mock('@/components/ui', () => ({
  Button: ({ children, ...props }: any) => <button {...props}>{children}</button>,
}));
vi.mock('@/lib/api/client', () => ({ apiClient: { get: vi.fn(), post: vi.fn() } }));
vi.mock('@/lib/assignments/assignments-hooks', () => ({
  useInstructorSubmission: () => ({
    data: h.submission,
    isLoading: false,
    isError: false,
    isFetching: false,
    refetch: h.refetch,
  }),
  useGradeSubmission: () => ({ mutateAsync: h.grade, isPending: false, isError: h.gradeIsError }),
  useReleaseGrade: () => ({ mutateAsync: h.release, isPending: false, isError: false }),
  useRequestChanges: () => ({ mutateAsync: h.requestChanges, isPending: false, isError: false }),
}));

import { GradePanel } from '@/components/assignments/grading/GradePanel';

function gradedSubmission() {
  return makeInstructorSubmission({
    grade: {
      score: null,
      passed: null,
      feedback: 'Nice work',
      private_notes: 'secret note',
      rubric_result: [
        { criterion_public_id: 'crit-1', level_public_id: 'lvl-1b' },
        { criterion_public_id: 'crit-2', level_public_id: 'lvl-2b' },
      ],
      version: 2,
      released_at: null,
    },
  });
}

describe('GradePanel', () => {
  beforeEach(() => {
    h.submission = gradedSubmission();
    h.gradeIsError = false;
    h.refetch.mockReset();
    h.refetch.mockResolvedValue({ data: h.submission });
    h.grade.mockReset();
    h.grade.mockResolvedValue(undefined);
    h.release.mockReset();
    h.release.mockResolvedValue(undefined);
    h.requestChanges.mockReset();
    h.requestChanges.mockResolvedValue(undefined);
  });

  it('hydrates private notes and feedback from the grade', () => {
    renderWithI18n(<GradePanel submissionId="sub-1" maxGrade={100} />);
    expect(screen.getByTestId('feedback-input')).toHaveValue('Nice work');
    expect(screen.getByTestId('private-notes-input')).toHaveValue('secret note');
  });

  it('saves a draft grade with rubric_result, private_notes and expected_version', async () => {
    renderWithI18n(<GradePanel submissionId="sub-1" maxGrade={100} />);
    fireEvent.click(screen.getByTestId('save-draft-grade'));
    await waitFor(() => expect(h.grade).toHaveBeenCalledTimes(1));
    expect(h.grade).toHaveBeenCalledWith(
      expect.objectContaining({
        expected_version: 2,
        private_notes: 'secret note',
        feedback: 'Nice work',
        rubric_result: [
          { criterion_public_id: 'crit-1', level_public_id: 'lvl-1b' },
          { criterion_public_id: 'crit-2', level_public_id: 'lvl-2b' },
        ],
      }),
    );
    // success path re-reads the authoritative copy
    expect(h.refetch).toHaveBeenCalled();
  });

  it('releases the grade', async () => {
    renderWithI18n(<GradePanel submissionId="sub-1" maxGrade={100} />);
    fireEvent.click(screen.getByTestId('release-grade'));
    await waitFor(() => expect(h.release).toHaveBeenCalledWith(undefined));
  });

  it('requests changes with a note', async () => {
    renderWithI18n(<GradePanel submissionId="sub-1" maxGrade={100} />);
    fireEvent.click(screen.getByTestId('request-changes-open'));
    fireEvent.change(screen.getByTestId('request-changes-note'), {
      target: { value: 'Add citations' },
    });
    fireEvent.click(screen.getByTestId('request-changes-confirm'));
    await waitFor(() =>
      expect(h.requestChanges).toHaveBeenCalledWith({ note: 'Add citations' }),
    );
  });

  it('handles a 409 conflict by warning and reloading, not overwriting', async () => {
    h.grade.mockRejectedValueOnce({ status: 409 });
    renderWithI18n(<GradePanel submissionId="sub-1" maxGrade={100} />);
    fireEvent.click(screen.getByTestId('save-draft-grade'));
    await waitFor(() => expect(screen.getByTestId('conflict-warning')).toBeInTheDocument());
    // reloads the latest server copy after the conflict
    expect(h.refetch).toHaveBeenCalled();
  });

  it('does not show a released banner for an unreleased grade', () => {
    renderWithI18n(<GradePanel submissionId="sub-1" maxGrade={100} />);
    expect(screen.queryByTestId('already-released')).not.toBeInTheDocument();
  });

  it('shows the released banner and disables release once released', () => {
    h.submission = makeInstructorSubmission({
      status: 'released',
      grade: {
        score: 80,
        passed: true,
        feedback: 'done',
        private_notes: null,
        rubric_result: null,
        version: 3,
        released_at: '2026-07-20T00:00:00Z',
      },
    });
    renderWithI18n(<GradePanel submissionId="sub-1" maxGrade={100} />);
    expect(screen.getByTestId('already-released')).toBeInTheDocument();
    expect(screen.getByTestId('release-grade')).toBeDisabled();
  });
});
