<?php
declare(strict_types=1);

final class PerformanceSetup {
    public static function schedule(?string $runtime = null): void {
        // Compatibility for requests already in flight while app files are replaced.
        if (!BrowseIndex::ready()) MaintenanceRequirements::needed('views', 'The prepared browsing index is missing. Prepare browsing pages; no folder scan is needed.');
    }
}
