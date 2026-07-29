<?php

namespace App\Domains\Certification\Http\Controllers\Api\V1;

use App\Domains\Certification\Actions\EnsureCertificatePdfAction;
use App\Domains\Certification\Actions\ShareCertificateAction;
use App\Domains\Certification\Exceptions\CertificateRevokedException;
use App\Domains\Certification\Http\Resources\CertificateResource;
use App\Domains\Certification\Models\Certificate;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

class CertificateController extends Controller
{
    public function show(Certificate $certificate): JsonResponse
    {
        Gate::authorize('view', $certificate);

        return ApiResponse::success(new CertificateResource($certificate->load('course')));
    }

    public function download(Certificate $certificate, EnsureCertificatePdfAction $ensure): JsonResponse
    {
        Gate::authorize('view', $certificate);

        if (! $certificate->isValid()) {
            throw new CertificateRevokedException;
        }

        $ensure->execute($certificate);

        // Return a short-lived signed URL to the stream route — never the storage path. M1: the
        // holder's id is bound into the signature (owner) and re-checked when the file is streamed,
        // so a signed URL can only ever serve the record it was minted for.
        $url = URL::temporarySignedRoute(
            'certificates.file',
            now()->addMinutes((int) config('certification.pdf.download_ttl_minutes', 15)),
            ['certificate' => $certificate->public_id, 'owner' => $certificate->user_id],
        );

        return ApiResponse::success(['download_url' => $url], 'Download link ready.');
    }

    public function share(Certificate $certificate, ShareCertificateAction $action): JsonResponse
    {
        Gate::authorize('view', $certificate);

        return ApiResponse::success($action->execute($certificate), 'Shareable link ready.');
    }
}
