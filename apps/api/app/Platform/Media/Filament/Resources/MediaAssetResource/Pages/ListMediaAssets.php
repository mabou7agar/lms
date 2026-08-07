<?php

namespace App\Platform\Media\Filament\Resources\MediaAssetResource\Pages;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Media\Filament\Resources\MediaAssetResource;
use App\Platform\Media\Ingestion\Data\AdminUploadOutcome;
use App\Platform\Media\Services\MediaAdminUploadService;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Media library list.
 *
 * Assets are still never AUTHORED as rows here — the DAM stays engine-driven. But D5/D4 add an
 * explicit drag-and-drop UPLOAD affordance as a header action: it collects one or more files plus a
 * purpose and hands each file to the existing engine via MediaAdminUploadService (createDirectUpload
 * → push bytes → finalizeUpload). D4 makes it a BATCH: every file is validated and ingested
 * independently, so one rejected file (wrong type/oversize) reports its own error without failing the
 * others. Size/type limits are the engine's (enforced against the chosen MediaPurpose) — not
 * re-declared here.
 */
class ListMediaAssets extends ListRecords
{
    protected static string $resource = MediaAssetResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->uploadAction(),
        ];
    }

    private function uploadAction(): Action
    {
        return Action::make('uploadMedia')
            ->label('Upload media')
            ->icon('heroicon-o-arrow-up-tray')
            ->modalHeading('Upload media')
            ->modalDescription('Drag and drop one or more files. Each is validated and ingested independently; a single bad file will not fail the rest.')
            ->modalSubmitActionLabel('Upload')
            ->visible(fn (): bool => Auth::user() instanceof Actor)
            ->schema([
                Select::make('purpose')
                    ->label('Purpose')
                    ->required()
                    ->options(fn (): array => collect(MediaPurpose::cases())
                        ->mapWithKeys(fn (MediaPurpose $p): array => [
                            $p->value => ucfirst(str_replace('_', ' ', $p->value)),
                        ])
                        ->all()),
                FileUpload::make('files')
                    ->label('Files')
                    ->required()
                    ->multiple()
                    ->storeFiles(false),
            ])
            ->action(function (array $data): void {
                $user = Auth::user();

                if (! $user instanceof Actor) {
                    Notification::make()->title('Not authorized')->danger()->send();

                    return;
                }

                $purpose = MediaPurpose::tryFrom((string) ($data['purpose'] ?? ''));

                if ($purpose === null) {
                    Notification::make()->title('Choose a purpose')->danger()->send();

                    return;
                }

                $files = $this->normalizeUploads($data['files'] ?? []);

                if ($files === []) {
                    Notification::make()->title('No files to upload')->danger()->send();

                    return;
                }

                $outcomes = app(MediaAdminUploadService::class)->uploadMany($user->actorId(), $purpose, $files);

                $this->notifyBatch($outcomes);
            });
    }

    /**
     * Turn Filament's temporary uploads into the shape MediaAdminUploadService::uploadMany expects.
     *
     * @param  mixed  $uploads
     * @return list<array{filename: string, mime_type: string, size_bytes: int, contents: string}>
     */
    private function normalizeUploads(mixed $uploads): array
    {
        $uploads = is_array($uploads) ? $uploads : [$uploads];
        $files = [];

        foreach ($uploads as $upload) {
            if (! $upload instanceof TemporaryUploadedFile) {
                continue;
            }

            $files[] = [
                'filename' => $upload->getClientOriginalName(),
                'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => (int) $upload->getSize(),
                'contents' => (string) file_get_contents($upload->getRealPath()),
            ];
        }

        return $files;
    }

    /**
     * Partial-failure reporting: one summary notification plus a per-file failure list.
     *
     * @param  list<AdminUploadOutcome>  $outcomes
     */
    private function notifyBatch(array $outcomes): void
    {
        $succeeded = array_values(array_filter($outcomes, fn (AdminUploadOutcome $o): bool => $o->successful));
        $failed = array_values(array_filter($outcomes, fn (AdminUploadOutcome $o): bool => ! $o->successful));

        $okCount = count($succeeded);
        $failCount = count($failed);

        if ($failCount === 0) {
            Notification::make()->title("Uploaded {$okCount} file(s)")->success()->send();

            return;
        }

        $lines = array_map(
            fn (AdminUploadOutcome $o): string => "• {$o->filename}: {$o->errorMessage}",
            $failed,
        );

        Notification::make()
            ->title("Uploaded {$okCount} file(s), {$failCount} failed")
            ->body(implode("\n", $lines))
            ->{$okCount > 0 ? 'warning' : 'danger'}()
            ->send();
    }
}
