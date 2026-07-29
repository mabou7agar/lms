'use client';

import { Button, toast } from '@/components/ui';

import { gradebookCsvFilename, triggerBlobDownload } from '@/lib/gradebook/gradebook-api';
import { useGradebookExport } from '@/lib/gradebook/gradebook-hooks';
import { useGradebookI18n } from '@/lib/gradebook/gradebook-i18n';

export interface GradebookExportButtonProps {
  publicId: string;
}

/**
 * Triggers the backend CSV export (authorized, instructor-only). On success the
 * streamed CSV is saved to disk and a success toast is surfaced; failures raise
 * an error toast.
 */
export function GradebookExportButton({ publicId }: GradebookExportButtonProps) {
  const { t } = useGradebookI18n();
  const exportMutation = useGradebookExport(publicId);

  const handleExport = () => {
    exportMutation.mutate(undefined, {
      onSuccess: (blob) => {
        triggerBlobDownload(blob, gradebookCsvFilename(publicId));
        toast.success(t('export.success'));
      },
      onError: () => {
        toast.error(t('export.error'));
      },
    });
  };

  return (
    <Button
      variant="primary"
      onClick={handleExport}
      disabled={exportMutation.isPending}
      data-testid="gb-export"
    >
      {exportMutation.isPending ? t('export.pending') : t('export.action')}
    </Button>
  );
}
