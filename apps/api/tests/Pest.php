<?php

use App\Contexts\Commerce\EInvoicing\Data\EInvoiceLine;
use App\Contexts\Commerce\EInvoicing\Data\EInvoicePayload;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Authoring\Services\ContentVersioningService;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\SocialAuth\Jwt\Der;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

// Bind the Laravel TestCase so config()/response() helpers work in both suites.
uses(TestCase::class)->in('Feature', 'Unit');

// Shared Authoring content-version test helpers. Defined here — in Pest's bootstrap, which every
// process (including each ParaTest worker) loads — rather than only inside ContentVersionSnapshotTest,
// so they resolve under `php artisan test --parallel`, where ParaTest distributes test files across
// workers and a worker may run a using-file (ContentVersionApi/Integrity/Operations) without the
// defining file. The in-file definitions keep their own function_exists guards, so nothing redeclares.
if (! function_exists('courseWithLessons')) {
    function courseWithLessons(int $lessons = 2): Course
    {
        $course = Course::factory()->create();
        $section = Section::factory()->create(['course_id' => $course->id]);
        for ($i = 0; $i < $lessons; $i++) {
            Lesson::factory()->create(['section_id' => $section->id, 'position' => $i, 'title' => "Lesson {$i}"]);
        }

        return $course;
    }
}

if (! function_exists('versioning')) {
    function versioning(): ContentVersioningService
    {
        return app(ContentVersioningService::class);
    }
}

// Shared SSO/JWT crypto test helpers (Sprint 0.5.2b). Generate real keys and sign real tokens so the
// native-openssl JwtVerifier / OIDC adapters are exercised without any network — mirroring how the
// gateway tests fake the vendor API. Defined in the bootstrap so cross-file usage is parallel-safe.
if (! function_exists('ssoB64u')) {
    function ssoB64u(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

if (! function_exists('ssoB64uDecode')) {
    function ssoB64uDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        return (string) base64_decode($padded, true);
    }
}

if (! function_exists('ssoRsaKey')) {
    /**
     * A fresh RSA keypair as [JWK (public), signer(claims): jwt].
     *
     * @return array{0: array<string, mixed>, 1: Closure(array<string, mixed>): string}
     */
    function ssoRsaKey(string $kid = 'rsa-test'): array
    {
        $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $details = openssl_pkey_get_details($resource);

        $jwk = [
            'kty' => 'RSA', 'kid' => $kid, 'alg' => 'RS256',
            'n' => ssoB64u($details['rsa']['n']),
            'e' => ssoB64u($details['rsa']['e']),
        ];

        $sign = function (array $claims) use ($resource, $kid): string {
            $head = ssoB64u((string) json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT']));
            $body = ssoB64u((string) json_encode($claims));
            $signature = '';
            openssl_sign($head.'.'.$body, $signature, $resource, OPENSSL_ALGO_SHA256);

            return $head.'.'.$body.'.'.ssoB64u($signature);
        };

        return [$jwk, $sign];
    }
}

if (! function_exists('ssoEcKey')) {
    /**
     * A fresh P-256 keypair as [JWK (public), signer(claims): jwt, private-key resource], or
     * [null, null, null] when the environment cannot generate EC keys (test should skip).
     *
     * @return array{0: array<string, mixed>|null, 1: Closure(array<string, mixed>): string|null, 2: mixed}
     */
    function ssoEcKey(string $kid = 'ec-test'): array
    {
        $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($resource === false) {
            return [null, null, null];
        }

        $details = openssl_pkey_get_details($resource);
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'kid' => $kid, 'alg' => 'ES256', 'x' => ssoB64u($x), 'y' => ssoB64u($y)];

        $sign = function (array $claims) use ($resource, $kid): string {
            $head = ssoB64u((string) json_encode(['alg' => 'ES256', 'kid' => $kid, 'typ' => 'JWT']));
            $body = ssoB64u((string) json_encode($claims));
            $der = '';
            openssl_sign($head.'.'.$body, $der, $resource, OPENSSL_ALGO_SHA256);

            return $head.'.'.$body.'.'.ssoB64u(Der::ecSignatureFromDer($der));
        };

        return [$jwk, $sign, $resource];
    }
}

// Shared e-invoicing test helper (Sprint 0.6b): a minimal canonical invoice payload.
if (! function_exists('einvoicePayload')) {
    function einvoicePayload(string $number = 'INV-1'): EInvoicePayload
    {
        return new EInvoicePayload(
            $number,
            '2026-08-07T10:00:00Z',
            'SAR',
            'Seller LLC',
            '300000000000003',
            'Buyer Name',
            null,
            [new EInvoiceLine('Course enrolment', 1, 10000, 15.0, 1500, 11500)],
            10000,
            1500,
            11500,
        );
    }
}

/*
 | Shared media-upload test helpers. The container's PHP has no GD extension, so
 | UploadedFile::fake()->image() (which calls imagecreatetruecolor) is unavailable — and a
 | ->create() fake carries a REPORTED size but no bytes, which the MediaPicker correctly refuses
 | as unreadable. These build a real, minimal PNG of exact dimensions with pure PHP + zlib, so the
 | upload path can be exercised for real (including Filament's `dimensions:ratio` gate, which reads
 | the header via getimagesize()).
 */
if (! function_exists('rawPngBytes')) {
    function rawPngBytes(int $width, int $height): string
    {
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            // Each scanline: a zero filter byte, then $width RGB triplets.
            $raw .= chr(0).str_repeat(chr(200).chr(120).chr(60), $width);
        }

        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data))
            .$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('N2C5', $width, $height, 8, 2, 0, 0, 0)) // 8-bit truecolour RGB
            .$chunk('IDAT', (string) gzcompress($raw, 6))
            .$chunk('IEND', '');
    }
}

if (! function_exists('fakePngUpload')) {
    function fakePngUpload(string $name, int $width, int $height): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, rawPngBytes($width, $height));
    }
}
