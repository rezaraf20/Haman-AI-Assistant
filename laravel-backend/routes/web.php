<?php
// Intentionally minimal. The Filament admin panel (app/Providers/Filament/AdminPanelProvider.php)
// registers its own routes onto the 'web' middleware group programmatically — this file's job
// is only to make bootstrap/app.php's withRouting(web: ...) activate the standard session/CSRF
// middleware stack that Filament (and any future browser-facing page) needs.
