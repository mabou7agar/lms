import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { makeAssignment, renderWithI18n } from './_helpers';

vi.mock('@/lib/assignments/assignments-i18n', () => ({
  useAssignmentsI18n: () => ({ t: (_k: string, f?: string) => f ?? _k }),
}));
vi.mock('@/components/ui', () => ({
  Button: ({ children, ...props }: any) => <button {...props}>{children}</button>,
}));
vi.mock('@/lib/api/client', () => ({ apiClient: { post: vi.fn(), get: vi.fn() } }));
vi.mock('@/lib/assignments/assignments-hooks', () => ({}));

import { SubmissionFileUploader } from '@/components/assignments/submission/SubmissionFileUploader';
import type {
  SubmissionUploadClient,
  UploadTransport,
} from '@/components/assignments/submission/upload/uploadClient';

function fileOfSize(name: string, type: string, size: number): File {
  const f = new File(['x'], name, { type });
  Object.defineProperty(f, 'size', { value: size });
  return f;
}

function fakeClient(): SubmissionUploadClient {
  return {
    createTicket: vi.fn(async () => ({
      media: {
        id: 'media-xyz',
        status: 'uploading',
        original_filename: 'ok.pdf',
        mime_type: 'application/pdf',
        size_bytes: 10,
        is_ready: false,
      },
      upload: {
        url: 'https://provider.test/upload',
        method: 'PUT',
        headers: {},
        fields: {},
        expires_at: '2026-08-01T00:00:00Z',
      },
      upload_token: 'tok-1',
    })),
    finalize: vi.fn(async () => ({
      id: 'media-xyz',
      status: 'ready',
      original_filename: 'ok.pdf',
      mime_type: 'application/pdf',
      size_bytes: 10,
      is_ready: true,
    })),
  };
}

describe('SubmissionFileUploader', () => {
  const attach = vi.fn(async () => undefined);
  const detach = vi.fn(async () => undefined);
  beforeEach(() => {
    attach.mockClear();
    detach.mockClear();
  });

  it('runs the full pipeline with progress and attaches the finalized media id', async () => {
    const client = fakeClient();
    const progressSteps: number[] = [];
    const transport: UploadTransport = async ({ onProgress }) => {
      onProgress?.(0.5);
      progressSteps.push(0.5);
      onProgress?.(1);
    };

    renderWithI18n(
      <SubmissionFileUploader
        assignment={makeAssignment()}
        attachedFiles={[]}
        onAttach={attach}
        onDetach={detach}
        client={client}
        transport={transport}
      />,
    );

    fireEvent.change(screen.getByTestId('file-input'), {
      target: { files: [fileOfSize('ok.pdf', 'application/pdf', 10)] },
    });

    await waitFor(() => expect(attach).toHaveBeenCalledWith('media-xyz'));
    expect(client.createTicket).toHaveBeenCalledTimes(1);
    expect(client.finalize).toHaveBeenCalledWith('media-xyz', 'tok-1');
    await waitFor(() => {
      const item = screen.getByTestId('upload-items').querySelector('[data-stage="done"]');
      expect(item).not.toBeNull();
    });
  });

  it('rejects a disallowed file type before uploading', async () => {
    const client = fakeClient();
    renderWithI18n(
      <SubmissionFileUploader
        assignment={makeAssignment()}
        attachedFiles={[]}
        onAttach={attach}
        onDetach={detach}
        client={client}
        transport={async () => undefined}
      />,
    );

    fireEvent.change(screen.getByTestId('file-input'), {
      target: { files: [fileOfSize('virus.exe', 'application/octet-stream', 10)] },
    });

    await waitFor(() =>
      expect(screen.getByTestId('upload-items').querySelector('[data-stage="error"]')).not.toBeNull(),
    );
    expect(client.createTicket).not.toHaveBeenCalled();
    expect(attach).not.toHaveBeenCalled();
    expect(screen.getByText(/Allowed file types/i)).toBeInTheDocument();
  });

  it('rejects a file that exceeds max_file_size', async () => {
    const client = fakeClient();
    renderWithI18n(
      <SubmissionFileUploader
        assignment={makeAssignment({ max_file_size: 1000 })}
        attachedFiles={[]}
        onAttach={attach}
        onDetach={detach}
        client={client}
        transport={async () => undefined}
      />,
    );

    fireEvent.change(screen.getByTestId('file-input'), {
      target: { files: [fileOfSize('big.pdf', 'application/pdf', 5000)] },
    });

    await waitFor(() =>
      expect(screen.getByText(/exceeds the maximum size/i)).toBeInTheDocument(),
    );
    expect(client.createTicket).not.toHaveBeenCalled();
  });

  it('detaches an already-attached file', async () => {
    renderWithI18n(
      <SubmissionFileUploader
        assignment={makeAssignment()}
        attachedFiles={[{ id: 'f-1', media_id: 'm-1', filename: 'done.pdf' }]}
        onAttach={attach}
        onDetach={detach}
        client={fakeClient()}
        transport={async () => undefined}
      />,
    );
    fireEvent.click(screen.getByText('Remove'));
    await waitFor(() => expect(detach).toHaveBeenCalledTimes(1));
  });
});
