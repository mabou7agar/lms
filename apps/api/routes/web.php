<?php

use Illuminate\Support\Facades\Route;

// Scaffold: the app is API-first. The admin panel (Filament) mounts at /admin
// once installed. No web pages are defined at this stage.
Route::get('/', fn () => response()->json(['service' => 'helbaron-api', 'docs' => '/api/v1/health']));

// NOTE: Public media delivery (GET /media/public/{publicId}) is intentionally registered in
// bootstrap/app.php's withRouting(then:) closure, NOT here — so it gets only global middleware and
// never the `web` session/cookie stack. See the comment there for the full rationale (stateless,
// immutably cacheable, and avoids the session-serialised concurrency 503 under FrankenPHP/Octane).
