import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { renderWithI18n } from './_helpers';

const h = vi.hoisted(() => ({
  saveMutate: vi.fn(async () => undefined),
}));

vi.mock('@/lib/assignments/assignments-i18n', () => ({
  useAssignmentsI18n: () => ({ t: (_k: string, f?: string) => f ?? _k }),
}));

vi.mock('@/components/ui', () => ({
  Button: ({ children, ...props }: any) => <button {...props}>{children}</button>,
}));

vi.mock('@/lib/assignments/assignments-hooks', () => ({
  useSaveDraft: () => ({ mutateAsync: h.saveMutate, isPending: false, isError: false }),
}));

import { DraftEditor } from '@/components/assignments/submission/DraftEditor';

describe('DraftEditor', () => {
  beforeEach(() => h.saveMutate.mockClear());

  it('saves text_response on manual save and shows a saved indicator', async () => {
    renderWithI18n(
      <DraftEditor assignmentId="asg-1" submissionType="text_file" autosaveMs={0} />,
    );

    fireEvent.change(screen.getByTestId('draft-text'), { target: { value: 'Hello world' } });
    fireEvent.click(screen.getByText('Save draft'));

    await waitFor(() => expect(h.saveMutate).toHaveBeenCalledTimes(1));
    expect(h.saveMutate).toHaveBeenCalledWith(
      expect.objectContaining({ text_response: 'Hello world' }),
    );
    await waitFor(() => expect(screen.getByTestId('draft-saved')).toBeInTheDocument());
  });

  it('renders the URL field for url submission types and persists external_url', async () => {
    renderWithI18n(<DraftEditor assignmentId="asg-1" submissionType="url" autosaveMs={0} />);
    expect(screen.queryByTestId('draft-text')).not.toBeInTheDocument();
    fireEvent.change(screen.getByTestId('draft-url'), {
      target: { value: 'https://example.com/work' },
    });
    fireEvent.click(screen.getByText('Save draft'));
    await waitFor(() =>
      expect(h.saveMutate).toHaveBeenCalledWith(
        expect.objectContaining({ external_url: 'https://example.com/work' }),
      ),
    );
  });
});
