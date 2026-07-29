import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { makeInstructorSubmission, renderWithI18n } from './_helpers';

vi.mock('@/lib/assignments/assignments-i18n', () => ({
  useAssignmentsI18n: () => ({ t: (_k: string, f?: string) => f ?? _k }),
}));
vi.mock('@/lib/api/client', () => ({ apiClient: { get: vi.fn(), post: vi.fn() } }));

import { LearnerSubmissionViewer } from '@/components/assignments/grading/LearnerSubmissionViewer';
import { SubmissionFileList } from '@/components/assignments/grading/SubmissionFileList';

describe('secure file access', () => {
  const openSpy = vi.fn();
  beforeEach(() => {
    openSpy.mockClear();
    window.open = openSpy;
  });

  it('resolves a signed URL only when the grader opens a file', async () => {
    const resolveUrl = vi.fn(async () => 'https://signed.example/file?token=abc');
    renderWithI18n(
      <SubmissionFileList
        files={[{ id: 'f-1', media_id: 'm-1', filename: 'essay.pdf' }]}
        resolveUrl={resolveUrl}
      />,
    );
    expect(resolveUrl).not.toHaveBeenCalled(); // nothing pre-fetched
    fireEvent.click(screen.getByTestId('file-open-f-1'));
    await waitFor(() => expect(resolveUrl).toHaveBeenCalledWith('m-1'));
    await waitFor(() =>
      expect(openSpy).toHaveBeenCalledWith(
        'https://signed.example/file?token=abc',
        '_blank',
        'noopener,noreferrer',
      ),
    );
  });

  it('surfaces an access error inline instead of a broken link', async () => {
    const resolveUrl = vi.fn(async () => {
      throw new Error('403');
    });
    renderWithI18n(
      <SubmissionFileList files={[{ id: 'f-2', media_id: 'm-2', filename: 'a.pdf' }]} resolveUrl={resolveUrl} />,
    );
    fireEvent.click(screen.getByTestId('file-open-f-2'));
    await waitFor(() => expect(screen.getByTestId('file-error-f-2')).toBeInTheDocument());
  });

  it('viewer renders learner text and files', () => {
    const resolveUrl = vi.fn(async () => 'x');
    renderWithI18n(
      <LearnerSubmissionViewer submission={makeInstructorSubmission()} resolveFileUrl={resolveUrl} />,
    );
    expect(screen.getByTestId('viewer-text')).toHaveTextContent('My essay body.');
    expect(screen.getByTestId('grader-files')).toHaveTextContent('essay.pdf');
  });
});
