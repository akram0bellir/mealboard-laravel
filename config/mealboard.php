<?php

return [
    // A sign-in with this password makes the person choose their own first.
    'default_password' => env('MEALBOARD_DEFAULT_PASSWORD', '123456789'),

    // Photos (reports, profiles): uploads/<table>/<row id>/<file>. Outside public/, served by /files/...
    'upload_dir' => env('MEALBOARD_UPLOAD_DIR', storage_path('app/uploads')),
    'max_upload_bytes' => 5 * 1024 * 1024,

    // Backups (database dump every run, photos zip once a day). Never inside public/.
    'backup_dir' => env('MEALBOARD_BACKUP_DIR', base_path('backups')),

    // GET /api/cron-run?key=... (cron-job.org or the host's cron). Empty = that address is switched off.
    'cron_key' => env('MEALBOARD_CRON_KEY', ''),

    // How long a door / API login token stays valid; refresh pushes it forward.
    'token_days' => (int) env('MEALBOARD_TOKEN_DAYS', 14),

    // Fixed sign-in for the control panel (/control), besides the super admin accounts. Empty = switched off.
    // Use a long password: this panel can change every table.
    'control_user' => env('MEALBOARD_CONTROL_USER', ''),
    'control_password' => env('MEALBOARD_CONTROL_PASSWORD', ''),

    // Master switch for real e-mail sending. Turn it off on a test copy of the database that holds the
    // company's real SMTP login, so nothing can reach real people by mistake.
    'send_mail' => (bool) env('MEALBOARD_SEND_MAIL', true),
];
