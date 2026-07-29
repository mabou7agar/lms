<?php

namespace App\Domains\Certification\Http\Controllers\Api\V1;

use App\Domains\Certification\Actions\EnsureCertificatePdfAction;
use App\Domains\Certification\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams the certificate PDF. Reached only via a signed URL (no auth guard) — the signature
 * authorizes access and the storage path is never revealed. M1: the signature additionally binds
 * the holder's id (owner), re-checked here, so a signed URL only ever serves its own certificate.
 */
class CertificateFileController extends Controller
{
    public function __invoke(Request $request, string $certificate, EnsureCertificatePdfAction $ensure): Response
    {
        $model = Certificate::where('public_id', $certificate)->first();

        if ($model === null || ! $model->isValid()) {
            throw new NotFoundHttpException('Certificate not available.');
        }

        // M1 — ownership as well as signature: the owner bound at mint time must match the resolved
        // certificate, so a leaked/replayed signed URL cannot be repointed at another holder's PDF.
        if ((int) $request->query('owner') !== (int) $model->user_id) {
            throw new AccessDeniedHttpException('You are not authorized to access this certificate.');
        }

        $ensure->execute($model);
        $disk = Storage::disk((string) config('certification.pdf.disk', 'local'));

        return response($disk->get($model->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$model->number.'.pdf"',
        ]);
    }
}
