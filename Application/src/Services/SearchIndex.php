<?php
declare(strict_types=1);

/** Compatibility entry point for the existing search maintenance action. */
final class SearchIndex {
    public static function warm(): void { BrowseIndex::warm(); }
    public static function suggest(string $query,array $viewer,bool $gamesOnly): ?array {
        return BrowseIndex::search($query,5,1,$viewer,$gamesOnly,true)['catalogue'];
    }
}
