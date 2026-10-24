<?php
declare(strict_types=1);

/** Each continuation resumes one saved stage; completed stages never restart. */
final class MetadataWarmup
{
    public static function run(?callable $progress = null, float $remoteBudget = 120, float $artworkBudget = 120, bool $resume = false, ?array $scope = null, string $cursor = ''): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Metadata warmup requires the maintenance worker.');
        $stateName=$scope!==null?'metadata-scoped-cycle:'.$cursor:'metadata-cycle-v2';
        $revision = $scope!==null?$cursor:'game-metadata-v2|' . \Vaultcast\Repository::revision('catalogue') . '|' . \Vaultcast\Repository::revision('settings') . '|' . \Vaultcast\Repository::revision('match_overrides');
        $state = \Vaultcast\Repository::document($stateName, []);
        if (($state['revision'] ?? '') !== $revision || (!$resume && ($state['phase'] ?? '') === 'complete')) {
            $state = ['revision'=>$revision, 'phase'=>'metadata', 'offset'=>0];
            if($scope===null)\Vaultcast\Repository::putDocument('sidecar-export-cursor', ['offset'=>0, 'revision'=>-1]);
        }
        $out = ['ok'=>true, 'processed'=>0, 'failed'=>0, 'deferred'=>0, 'artwork_prepared'=>0, 'artwork_failed'=>0, 'artwork_deferred'=>0];
        $lastSave = microtime(true);
        $save = function (bool $force = false) use (&$state, &$lastSave, $stateName) {
            if (!$force && microtime(true) - $lastSave < 5) return;
            \Vaultcast\Repository::putDocument($stateName, $state);
            $lastSave = microtime(true);
        };
        try {
            if ($state['phase'] === 'metadata') {
                $targets = $scope!==null?ArtworkLibrary::scopedTargets($scope):ArtworkLibrary::targets(); $keys = array_keys($targets);
                if($scope!==null)$state['offset']=max(0,min((int)$state['offset'],count($keys)));
                $attempts = $scope!==null?[]:\Vaultcast\Repository::document('metadata-attempts-v1', []);
                if($scope!==null)foreach($keys as $key)$attempts[$key]=\Vaultcast\Repository::document('metadata-attempt:'.$key,[]);
                $attempts = is_array($attempts) ? ($scope!==null?$attempts:array_intersect_key($attempts, $targets)) : [];
                $pins = class_exists('Matching') ? Matching::overrides() : [];
                $remote = Posters::enabled(); $started = microtime(true);
                try {
                    for ($i = (int)$state['offset']; $i < count($keys); $i++) {
                        Maintenance::checkpoint();
                        $key = $keys[$i]; $target = $targets[$key]; $it = $target['item'];
                        $signature = sha1(json_encode([$target, $pins['shows'][$target['key'] ?? ''] ?? null]));
                        if($target['kind']==='games')$signature=sha1($signature.'|game-metadata-v'.GameMetadata::VERSION);
                        $recent = ($attempts[$key]['signature'] ?? '') === $signature && ($attempts[$key]['at'] ?? 0) > time() - 86400;
                        if ($remote && !$recent && microtime(true) - $started >= max(0, min(120, $remoteBudget))) break;
                        if ($progress) $progress('Artwork and synopses', $i, count($keys), (string)($it['show'] ?? $it['title'] ?? $key));
                        $fetch = $remote && !$recent;
                        Posters::beginRemoteBudget($fetch ? 5 : 0.4);
                        try {
                            ArtworkLibrary::prepare($target, $fetch);
                            if($fetch && $target['kind']==='games'){Posters::beginRemoteBudget(5);Posters::gameSynopsis($it);}
                            if ($fetch && $target['kind'] === 'movies') {
                                Posters::beginRemoteBudget(5);
                                Posters::movieSynopsis((string)$it['id'], (string)$it['title'], isset($it['year']) ? (int)$it['year'] : null);
                            }
                        } catch (Throwable $e) { $out['failed']++; error_log('Vaultcast metadata ' . $key . ': ' . $e->getMessage()); }
                        finally { Posters::endRemoteBudget(); }
                        if ($fetch) $attempts[$key] = ['signature'=>$signature, 'at'=>time()];
                        $out['processed']++; $state['offset'] = $i + 1; $save();
                    }
                } finally {
                    if($scope===null)\Vaultcast\Repository::putDocument('metadata-attempts-v1', $attempts);
                    else foreach($attempts as $key=>$attempt)if($attempt)\Vaultcast\Repository::putDocument('metadata-attempt:'.$key,$attempt);
                }
                $out['metadata_done'] = $state['offset']; $out['metadata_total'] = count($keys);
                $out['deferred'] = count($keys) - $state['offset'];
                if ($out['deferred']) return $out + ['stage'=>'metadata'];
                $state['phase'] = 'encoding'; $state['offset'] = 0; $state['files'] = [];
                if($scope===null)$state['files']=PosterOptimise::listOriginals();
                else foreach(ArtworkLibrary::scopedTargets($scope) as $target){
                    $stem=pathinfo($target['path'],PATHINFO_FILENAME);
                    foreach(['jpg','jpeg','png'] as $ext)if(is_file(Posters::dir().'/'.$stem.'.'.$ext))$state['files'][]=$stem.'.'.$ext;
                } $save(true);
            }
            if ($state['phase'] === 'encoding') {
                $files = $state['files'] ?? [];
                $failures = $scope!==null?[]:\Vaultcast\Repository::document('metadata-artwork-failures-v1', []);
                if($scope!==null)foreach($files as $file)$failures[$file]=\Vaultcast\Repository::document('metadata-artwork-failure:'.sha1($file),[]);
                $failures = is_array($failures) ? ($scope!==null?$failures:array_intersect_key($failures, array_flip($files))) : [];
                $started = microtime(true);
                try {
                    for ($i = (int)$state['offset']; $i < count($files); $i++) {
                        Maintenance::checkpoint();
                        if (microtime(true) - $started >= max(0, min(120, $artworkBudget))) break;
                        $file = $files[$i]; $path = Posters::dir() . '/' . $file;
                        if ($progress) $progress('Optimising artwork', $i, count($files), $file);
                        $signature = is_file($path) ? filesize($path) . ':' . filemtime($path) : 'missing';
                        try {
                            $status = PosterOptimise::statusForFile($file, false, false);
                            if (!empty($status['needs'])) {
                                if (($failures[$file]['signature'] ?? '') === $signature && ($failures[$file]['at'] ?? 0) > time() - 86400) $out['artwork_failed']++;
                                else {
                                    $result = PosterOptimise::optimiseOne($file, ['overwrite'=>false]);
                                    if (empty($result['ok'])) throw new RuntimeException($result['error'] ?? 'Conversion failed');
                                    $out['artwork_prepared']++;
                                }
                            }
                        } catch (Throwable $e) {
                            $out['artwork_failed']++; $failures[$file] = ['signature'=>$signature, 'at'=>time()];
                            error_log('Vaultcast artwork ' . $file . ': ' . $e->getMessage());
                        }
                        $state['offset'] = $i + 1; $save();
                    }
                } finally {
                    if($scope===null)\Vaultcast\Repository::putDocument('metadata-artwork-failures-v1', $failures);
                    else foreach($failures as $file=>$failure)if($failure)\Vaultcast\Repository::putDocument('metadata-artwork-failure:'.sha1($file),$failure);
                }
                $out['artwork_done'] = $state['offset']; $out['artwork_total'] = count($files);
                $out['artwork_deferred'] = count($files) - $state['offset'];
                if ($out['artwork_deferred']) return $out + ['stage'=>'encoding'];
                $state['phase'] = 'sidecars'; $state['offset'] = 0; unset($state['files']); $save(true);
            }
            if ($state['phase'] === 'sidecars') {
                $out['sidecars'] = MetadataSidecars::run($progress, 120, true, $scope, $cursor);
                if ($out['sidecars']['failed']) $out['warning'] = 'Some metadata backups could not be written. Check library write permissions and free space.';
                if ($out['sidecars']['deferred']) return $out + ['stage'=>'sidecars'];
                $state['phase'] = 'views'; $save(true);
            }
            if ($state['phase'] === 'views') {
                if ($progress) $progress($scope!==null?'Refreshing changed metadata':'Preparing library pages', 0, 1, '');
                // Invalidate only once, after the cycle has finished changing metadata.
                Catalogue::invalidateDerivedCaches();
                if($scope!==null){
                    $shows=[];foreach(ArtworkLibrary::scopedTargets($scope) as $target)if(!empty($target['key']))$shows[$target['key']]=true;
                    foreach(array_keys($shows) as $key)BrowseIndex::dirty($key);
                    $state['phase']='complete';return $out+['stage'=>'complete','scoped'=>true];
                }
                Catalogue::warmBrowseViews();
                Catalogue::homeLibrary(); Catalogue::showPosterHint('');
                if (class_exists('Collections')) Collections::publicList();
                if (class_exists('LiveGuideCache')) LiveGuideCache::warm();
                $state['phase'] = 'complete';
                if ($progress) $progress('Complete', 1, 1, '');
            }
            return $out + ['stage'=>'complete'];
        } finally { $save(true); }
    }
}
