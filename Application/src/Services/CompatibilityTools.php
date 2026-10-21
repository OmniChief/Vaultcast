<?php
declare(strict_types=1);

trait CompatibilityTools {
    private static function requireCapability(string $permission): void {if(!Permissions::allowed(self::$viewer,$permission))throw new RuntimeException('This action is not available to your role.');}
    private static function resultKey(string $id): string {return 'compat-result:'.self::$viewer['id'].':'.$id;}
    private static function keepResult(string $tool,array $result,array $context=[]): array {
        $id=bin2hex(random_bytes(12));\Vaultcast\Repository::cachePut(self::resultKey($id),['tool'=>$tool,'result'=>$result,'context'=>$context],900);return ['view'=>'tool-result','id'=>$id];
    }
    private static function readResult(string $id): array {
        if(!preg_match('/^[a-f0-9]{24}$/D',$id))throw new RuntimeException('Invalid result.');$data=\Vaultcast\Repository::cacheGet(self::resultKey($id));if(!$data)throw new RuntimeException('These results expired. Run the action again.');return $data;
    }
    private static function toolDefinitions(): array {
        return [
            'rss'=>['RSS search','/search/rss','POST','rss_search',['query'=>['Search','text',''],'kind'=>['Media type','select','auto',['auto'=>'All','movie'=>'Films','tv'=>'TV','anime'=>'Anime']]]],
            'download-status'=>['Download status','/downloads/status','GET','downloads_request',[]],
            'maintenance'=>['Background maintenance','/admin/maintenance','POST','admin',['task'=>['Task','select','browse',Maintenance::TASKS]]],
            'jobs'=>['Maintenance jobs','/admin/maintenance','GET','admin',[]],
            'system'=>['Server status','/admin/system/status','GET','admin',[]],
            'restart-service'=>['Restart a server service','/admin/system/service/restart','POST','admin',['id'=>['Service ID from server status','text','']]],
            'reboot'=>['Reboot server','/admin/system/reboot','POST','admin',['confirm'=>['I want to reboot this server','checkbox',false]]],
            'kill'=>['Stop a server process','/admin/system/kill','POST','admin',['pid'=>['Process ID','number','0'],'force'=>['Force termination','checkbox',false]]],
            'prepare-artwork'=>['Prepare missing artwork and synopsis','/admin/posters/fetch','POST','admin',['kinds'=>['Kinds','csv','shows,movies'],'limit'=>['Batch size','number','4']]],
            'logs'=>['Recent logs','/admin/system/logs','GET','admin',['lines'=>['Lines','number','40']]],
            'processes'=>['Server processes','/admin/system/processes','GET','admin',['limit'=>['Maximum rows','number','40']]],
            'diagnostics'=>['Connection diagnostics','/diagnostics','POST','admin',['id'=>['Check','text','']]],
            'cache'=>['Clear cached browsing data','/admin/system/cache-clear','POST','admin',['targets'=>['Caches','csv','opcache,shm,probe']]],
            'artwork'=>['Artwork integrity checker','/admin/posters/audit','POST','admin',['types'=>['Media types','multi',['movies','tv','anime'],['movies'=>'Films','tv'=>'TV','anime'=>'Anime']],'limit'=>['Batch size','number','16'],'offset'=>['Start at','number','0']]],
            'artwork-status'=>['Artwork coverage','/admin/posters/status','GET','admin',[]],
            'optimise'=>['Artwork optimisation','/admin/posters/optimise/find','GET','admin',['scope'=>['Scope','select','all',['all'=>'All','movies'=>'Films','shows'=>'Shows']],'limit'=>['Batch size','number','16'],'offset'=>['Start at','number','0']]],
            'optimise-queue'=>['Artwork queue','/admin/posters/optimise/queue','GET','admin',[]],
            'conversions'=>['Video conversion queue','/admin/convert','GET','admin',[]],
            'transmission'=>['Download manager','/admin/transmission','GET','admin',[]],
            'import-downloads'=>['Import completed downloads','/admin/downloads/process-completed','POST','admin',[]],
            'reports'=>['Content reports','/admin/reports','GET','admin',['status'=>['Status','select','open',['open'=>'Open','resolved'=>'Resolved','all'=>'All']]]],
            'ingest'=>['Import inbox','/admin/ingest','GET','ingest_view',[]],
            'media'=>['Manage library','/admin/media/browse','GET','admin',['q'=>['Search','text',''],'section'=>['Library ID (optional)','text',''],'per'=>['Results per page','number','16'],'page'=>['Page','number','1']]],
            'files'=>['Browse media files','/admin/media/files','GET','admin',['path'=>['Folder','text','']]],
            'paths'=>['Diagnose media paths','/admin/media/diagnose','POST','admin',['limit'=>['Maximum items','number','100'],'include_orphans'=>['Include untracked files','checkbox',false]]],
            'collections-admin'=>['Manage collections','/admin/collections','GET','admin',[]],
            'collection-search'=>['Find a collection online','/admin/collections/search-online','POST','admin',['query'=>['Collection name','text','']]],
            'discover-collections'=>['Discover library collections','/admin/collections/discover','POST','admin',['action'=>['Action','select','start',['start'=>'Start','step'=>'Continue','cancel'=>'Cancel']]]],
            'updates'=>['Check for updates','/admin/updates','GET','admin',['branch'=>['Branch','text',Updater::currentBranch()]]],
        ];
    }
    private static function toolPermission(string $permission): void {
        if($permission==='admin'){if((self::$viewer['role'] ?? '')!=='admin')throw new RuntimeException('Administrator access required.');}
        else self::requireCapability($permission);
    }
    private static function toolPost(string $view,array $body): ?array {
        $action=(string)($body['compat_action'] ?? '');$id=(string)($_GET['id'] ?? '');
        if($action==='run_tool'){
            $definition=self::toolDefinitions()[$id] ?? null;if(!$definition)throw new RuntimeException('Unknown tool.');self::toolPermission($definition[3]);$payload=[];
            foreach($definition[4] as $name=>$field){$value=$body[$name] ?? '';if($field[1]==='checkbox')$value=!empty($value);elseif($field[1]==='number')$value=(int)$value;elseif($field[1]==='multi'){$value=is_array($value)?$value:explode(',',(string)$value);$value=array_values(array_intersect(array_keys($field[3]),$value));if(!$value)throw new RuntimeException('Choose at least one media type.');}elseif($field[1]==='csv')$value=array_values(array_filter(array_map('trim',explode(',',(string)$value))));elseif($field[1]==='select'&&!isset($field[3][$value]))throw new RuntimeException('Choose a valid option.');$payload[$name]=$value;}
            $result=CompatibilityApi::call($definition[1],$definition[2],$definition[2]==='POST'?$payload:[],$definition[2]==='GET'?$payload:[]);
            return self::keepResult($id,$result,['payload'=>$payload]);
        }
        if($action==='match_search'){
            self::requireCapability('fix_match');self::target($id);$target=self::target($id);$kind=isset($target['show_key'])?((HomeHub::entity($id,self::$viewer)['type'] ?? '')==='anime'?'anime':'tv'):'movie';
            return self::keepResult('match',CompatibilityApi::call('/admin/match/search','POST',['kind'=>$kind,'q'=>(string)($body['q'] ?? '')]),['target'=>$id]);
        }
        if($action==='match_pick'){
            self::requireCapability('fix_match');$saved=self::readResult($id);$key=(string)($saved['context']['target'] ?? '');$target=self::target($key);$match=$saved['result']['results'][(int)($body['index'] ?? -1)] ?? null;if(!$match)throw new RuntimeException('Choose a search result.');
            $payload=['match'=>$match,'title'=>$match['title'] ?? $match['name'] ?? '','year'=>$match['year'] ?? null,'image'=>$match['image'] ?? null];
            if(isset($target['show_key'])){$path='/admin/match/show/'.$target['show_key'].'/pin';$payload['tvmaze_id']=($match['source'] ?? '')==='tvmaze'?(int)$match['id']:0;}
            else $path='/admin/match/movie/'.$target['item_id'];
            return self::keepResult('match-applied',CompatibilityApi::call($path,'POST',$payload));
        }
        if($action==='metadata'){
            self::requireCapability('metadata_edit');$target=self::target($id);$payload=['id'=>$target['item_id'] ?? '','show_key'=>$target['show_key'] ?? '','title'=>(string)($body['title'] ?? ''),'year'=>$body['year'] ?? null,'synopsis'=>(string)($body['synopsis'] ?? '')];
            $file=self::uploaded('poster',8388608);if($file){$info=@getimagesize($file['tmp_name']);if(!$info||!in_array($info['mime'],['image/jpeg','image/png','image/webp'],true))throw new RuntimeException('Choose a JPEG, PNG or WebP poster.');$payload['poster_data']='data:'.$info['mime'].';base64,'.base64_encode((string)file_get_contents($file['tmp_name']));}
            return self::keepResult('metadata',CompatibilityApi::call('/admin/media/edit','POST',$payload));
        }
        if($action==='library_remove'){
            self::toolPermission('admin');$target=self::target($id);return self::keepResult('removed',CompatibilityApi::call('/admin/media/edit','POST',['id'=>$target['item_id'] ?? '','show_key'=>$target['show_key'] ?? '','remove'=>true,'confirm'=>(string)($body['confirm'] ?? '')]));
        }
        if($action==='download_pick'){
            self::requireCapability('downloads_request');$saved=self::readResult($id);$rows=$saved['result']['results'] ?? $saved['result']['items'] ?? [];$row=$rows[(int)($body['index'] ?? -1)] ?? null;if(!$row)throw new RuntimeException('Search again to refresh this result.');return self::keepResult('download',CompatibilityApi::call('/search/get','POST',array_intersect_key($row,array_flip(['title','magnet','link','enclosure']))));
        }
        if($action==='update_apply'){
            self::toolPermission('admin');$saved=self::readResult($id);if($saved['tool']!=='updates')throw new RuntimeException('Check for updates first.');$latest=$saved['result']['latest'];return self::keepResult('updated',CompatibilityApi::call('/admin/updates/apply','POST',['tree'=>$latest['tree'],'branch'=>$latest['branch']]));
        }
        if($action==='artwork-selected'||$action==='artwork-continue'){
            self::toolPermission('admin');$saved=self::readResult($id);
            if($action==='artwork-selected'){$pending=[];foreach($saved['result']['items'] ?? [] as $index=>$row)if(!empty($body['all'])||!empty($body['selected'][$index]))$pending[]=$row['key'];$done=[];}
            else {$pending=$saved['context']['pending'] ?? [];$done=$saved['result']['completed'] ?? [];}
            if(!$pending)throw new RuntimeException('Select artwork to repair.');$key=array_shift($pending);
            try{$repair=CompatibilityApi::call('/admin/posters/audit/repair','POST',['key'=>$key]);$done[]=['key'=>$key,'message'=>$repair['message'] ?? 'Checked'];}catch(RuntimeException $error){$done[]=['key'=>$key,'error'=>$error->getMessage()];}
            return self::keepResult('artwork-batch',['ok'=>true,'completed'=>$done,'remaining'=>count($pending)],['pending'=>$pending]);
        }
        if($action==='result_action')return self::resultAction($id,$body);
        return null;
    }
    private static function resultAction(string $id,array $body): array {
        self::toolPermission('admin');$saved=self::readResult($id);$action=(string)($body['change'] ?? '');$index=(int)($body['index'] ?? -1);$data=$saved['result'];
        if($action==='cancel-job'){$row=$data['jobs'][$index] ?? null;if(!$row)throw new RuntimeException('Job no longer listed.');$path='/admin/maintenance/cancel';$payload=['id'=>$row['id']];}
        elseif($action==='resolve-report'){$row=$data['reports'][$index] ?? null;if(!$row)throw new RuntimeException('Report no longer listed.');$path='/admin/reports/'.$row['id'];$payload=['status'=>'resolved'];}
        elseif($action==='repair-artwork'){$row=$data['items'][$index] ?? null;if(!$row)throw new RuntimeException('Artwork no longer listed.');$path='/admin/posters/audit/repair';$payload=['key'=>$row['key']];}
        elseif($action==='optimise-artwork'){$row=($data['files'] ?? $data['items'] ?? [])[$index] ?? null;if(!$row)throw new RuntimeException('Artwork no longer listed.');$path='/admin/posters/optimise';$payload=['files'=>[is_array($row)?($row['file'] ?? $row['stem'] ?? ''):$row],'budget'=>2];}
        else throw new RuntimeException('Unknown result action.');
        return self::keepResult($saved['tool'],CompatibilityApi::call($path,'POST',$payload));
    }
    private static function toolPage(string $view,string $id): ?string {
        if($view==='tools'){
            $html=self::heading('YOUR TOOLS','Tools and settings','Actions run only when selected.');
            foreach(self::toolDefinitions() as $key=>$definition){if($definition[3]==='admin'?(self::$viewer['role'] ?? '')!=='admin':!Permissions::allowed(self::$viewer,$definition[3]))continue;$html.=self::link($definition[0],['view'=>'tool','id'=>$key]);}
            if((self::$viewer['role'] ?? '')==='admin')$html.=self::link('Application settings',['view'=>'configuration']).self::link('Live settings',['view'=>'live-admin']).self::link('Create movie collection',['view'=>'manage-collection']).self::link('Users and roles',['view'=>'users']);return $html;
        }
        if($view==='tool'){
            $definition=self::toolDefinitions()[$id] ?? null;if(!$definition)throw new RuntimeException('Unknown tool.');self::toolPermission($definition[3]);$html=self::heading('TOOLS',$definition[0]).self::beginForm(['view'=>'tool','id'=>$id],'run_tool');
            foreach($definition[4] as $name=>$field){if($field[1]==='multi'){foreach($field[3] as $value=>$label)$html.='<label class="compat-check"><input type="checkbox" name="'.$name.'[]" value="'.self::esc($value).'"'.(in_array($value,$field[2],true)?' checked="checked"':'').'> '.self::esc($label).'</label>';}elseif($field[1]==='select')$html.=self::select($field[0],$name,$field[3],(string)$field[2]);elseif($field[1]==='checkbox')$html.='<label class="compat-check"><input type="checkbox" name="'.$name.'" value="1"'.($field[2]?' checked="checked"':'').'> '.self::esc($field[0]).'</label>';else $html.=self::field($field[0],$name,(string)($_GET[$name] ?? $field[2]),$field[1]==='number'?'number':'text',500);}
            return $html.'<button type="submit">'.$definition[0].'</button></form>';
        }
        if($view==='match'||$view==='metadata'||$view==='remove-library'){
            $target=self::target($id);$card=HomeHub::entity($id,self::$viewer);$html=self::heading('LIBRARY',(string)$card['title']);
            if($view==='match'){self::requireCapability('fix_match');return $html.self::beginForm(['view'=>'match','id'=>$id],'match_search').self::field('Search metadata','q',(string)$card['title']).'<button type="submit">Search for a match</button></form>';}
            if($view==='remove-library'){self::toolPermission('admin');return $html.self::beginForm(['view'=>'remove-library','id'=>$id],'library_remove').'<p>Remove this entry from the library. Media files are kept.</p>'.self::field('Type REMOVE to confirm','confirm').'<button type="submit">Remove library entry</button></form>';}
            self::requireCapability('metadata_edit');$summary=$card['synopsis'] ?? '';if(isset($target['item_id']))$summary=Catalogue::itemDetail($target['item_id'])['item']['synopsis'] ?? $summary;else $summary=Catalogue::show($target['show_key'])['show']['synopsis'] ?? $summary;
            return $html.self::beginForm(['view'=>'metadata','id'=>$id],'metadata',true).self::field('Title','title',(string)$card['title'],'text',500).self::field('Year','year',(string)($card['year'] ?? ''),'number',4).'<label>Synopsis<textarea name="synopsis" rows="8" maxlength="20000">'.self::esc($summary).'</textarea></label><label>Poster · JPEG, PNG or WebP up to 8 MiB<input type="file" name="poster" accept="image/jpeg,image/png,image/webp"></label><button type="submit">Save metadata</button></form>';
        }
        if($view==='tool-result')return self::toolResult($id);
        return null;
    }
    private static function readable($data,int $depth=0): string {
        if($depth>5)return '';if(!is_array($data))return '<span>'.self::esc(is_bool($data)?($data?'Yes':'No'):(string)$data).'</span>';
        $html='<dl class="compat-result">';$n=0;foreach($data as $key=>$value){if(++$n>40){$html.='<dt>More items</dt><dd>Use a narrower filter or the next page.</dd>';break;}if(preg_match('/password|secret|api_key|authorization|token|^_files$/i',(string)$key))continue;$html.='<dt>'.self::esc(is_int($key)?(string)($key+1):ucwords(str_replace('_',' ',(string)$key))).'</dt><dd>'.self::readable($value,$depth+1).'</dd>';}
        return $html.'</dl>';
    }
    private static function toolResult(string $id): string {
        $saved=self::readResult($id);$tool=$saved['tool'];$data=$saved['result'];$html=self::heading('TOOLS','Results').self::link('Tools',['view'=>'tools']);
        if($tool==='match'){
            foreach($data['results'] ?? [] as $index=>$row)$html.='<div class="compat-panel"><strong>'.self::esc($row['title'] ?? $row['name'] ?? 'Match').'</strong><p>'.self::esc($row['year'] ?? '').' · '.self::esc($row['source'] ?? '').'</p>'.self::form(['view'=>'tool-result','id'=>$id],'Use this match','match_pick',['index'=>$index]).'</div>';
        }elseif($tool==='subtitles'){
            foreach($data['results'] ?? [] as $index=>$row)$html.='<div class="compat-panel"><strong>'.self::esc($row['name'] ?? $row['release'] ?? $row['language'] ?? 'Subtitles').'</strong>'.self::form(['view'=>'subtitles','id'=>$saved['context']['item_id']],'Download subtitles','subtitle-download',['result'=>$id,'index'=>$index]).'</div>';
        }elseif($tool==='rss'){
            foreach($data['results'] ?? $data['items'] ?? [] as $index=>$row)$html.='<div class="compat-panel"><strong>'.self::esc($row['title'] ?? 'Result').'</strong>'.self::readable(array_intersect_key($row,array_flip(['size','seeds','source','quality']))).(Permissions::allowed(self::$viewer,'downloads_request')?self::form(['view'=>'tool-result','id'=>$id],'Request download','download_pick',['index'=>$index]):'').'</div>';
        }else $html.=self::readable($data);
        if($tool==='artwork'&&!empty($data['items'])){
            $html.=self::beginForm(['view'=>'tool-result','id'=>$id],'artwork-selected').'<h3>Repair selected artwork</h3><label class="compat-check"><input type="checkbox" name="all" value="1"> Select all results on this page</label>';
            foreach($data['items'] as $index=>$row)$html.='<label class="compat-check"><input type="checkbox" name="selected['.$index.']" value="1"> '.self::esc($row['title'] ?? $row['key']).'</label>';
            $html.='<button type="submit">Repair selected</button></form>';
        }
        if($tool==='artwork-batch'&&!empty($saved['context']['pending']))$html.=str_replace('<form ','<form id="compat-batch-continue" ',self::beginForm(['view'=>'tool-result','id'=>$id],'artwork-continue')).'<p>Repair continues one item at a time. Leave this page to stop.</p><button type="submit">Continue repairs</button></form>';
        if($tool==='artwork'&&!empty($data['more']))$html.=self::beginForm(['view'=>'tool','id'=>'artwork'],'run_tool',false,['offset'=>$data['offset'],'types'=>implode(',',(array)($saved['context']['payload']['types'] ?? ['movies','tv','anime'])),'limit'=>16]).'<button type="submit">Check next batch</button></form>';
        if($tool==='media')foreach(array_merge($data['items'] ?? [],$data['shows'] ?? []) as $card){$key=(self::isShow($card)?'show:':'item:').($card['show_key'] ?? $card['id']);if(($card['media_kind'] ?? '')==='rom')continue;$html.=self::link('Edit '.($card['title'] ?? 'Title'),['view'=>'metadata','id'=>$key]).self::link('Fix match',['view'=>'match','id'=>$key]);}
        if($tool==='files')foreach($data['files'] ?? [] as $file)if(!empty($file['path']))$html.=self::link($file['name'] ?? $file['path'],['view'=>'tool','id'=>'files','path'=>$file['path']]);
        if($tool==='collections-admin')foreach($data['collections'] ?? [] as $collection)$html.=self::link('Edit '.($collection['name'] ?? 'Collection'),['view'=>'manage-collection','id'=>$collection['id']]);
        if($tool==='updates'&&!empty($data['update_available']))$html.=self::form(['view'=>'tool-result','id'=>$id],'Apply checked update','update_apply');
        if((self::$viewer['role'] ?? '')==='admin'){
            $actions=['jobs'=>['jobs','cancel-job','Cancel job'],'reports'=>['reports','resolve-report','Resolve report'],'artwork'=>['items','repair-artwork','Repair artwork'],'optimise'=>[isset($data['files'])?'files':'items','optimise-artwork','Optimise artwork']];
            if(isset($actions[$tool])){[$field,$action,$label]=$actions[$tool];foreach(array_slice(array_values($data[$field] ?? []),0,40) as $index=>$row)$html.=self::form(['view'=>'tool-result','id'=>$id],$label.' · '.(is_array($row)?(string)($row['title'] ?? $row['name'] ?? $row['id'] ?? $row['key'] ?? ($index+1)):(string)$row),'result_action',['change'=>$action,'index'=>$index]);}
        }
        if(isset(self::toolDefinitions()[$tool]))$html.=self::link('Run again',['view'=>'tool','id'=>$tool]);return $html;
    }
}
