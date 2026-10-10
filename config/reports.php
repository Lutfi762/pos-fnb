<?php

declare(strict_types=1);

return [

    // More rows than this and an export is built by the queue instead of
    // while the manager waits. A month of bills in a busy cafe is about this.
    'queue_threshold' => (int) env('REPORT_QUEUE_THRESHOLD', 2000),

    // How long a queued export stays downloadable.
    'export_hours' => (int) env('REPORT_EXPORT_HOURS', 24),

];
