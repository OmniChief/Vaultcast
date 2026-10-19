<?php
declare(strict_types=1);

trait CompatibilityAdministration {
    private static function configNodes(array $data,string $prefix='',int $depth=0): array {
        if($depth>6)return [];$out=[];
        foreach($data as $key=>$value){$part=str_replace(['~','.'],['~0','~1'],(string)$key);$path=$prefix===''?$part:$prefix.'.'.$part;if(preg_match('/(^|[._])(emu|emulator|bios|netplay|game|games)([._]|$)/i',$path))continue;
            if(is_array($value))$out+=self::configNodes($value,$path,$depth+1);elseif(is_scalar($value)||$value===null)$out[$path]=$value;
        }return $out;
    }
    private static function putConfig(array &$data,string $path,$value): void {
        $parts=explode('.',$path);$cursor=&$data;foreach($parts as $index=>$part){$part=str_replace(['~1','~0'],['.','~'],$part);if($index===count($parts)-1){$cursor[$part]=$value;return;}if(!isset($cursor[$part])||!is_array($cursor[$part]))$cursor[$part]=[];$cursor=&$cursor[$part];}
    }
    private static function administrationPost(string $view,array $body): ?array {
        $action=(string)($body['compat_action'] ?? '');if(!in_array($action,['configuration','new-library','user-create','user-permissions','user-access','user-delete','role-save','factory-reset','collection-save','live-channel','iptv-feed'],true))return null;self::toolPermission('admin');$id=(string)($_GET['id'] ?? '');
        if($action==='collection-save'){
            $existing=$id!==''?Collections::get($id):null;$known=[];foreach($existing['parts'] ?? [] as $part)$known[mb_strtolower((string)($part['title'] ?? '')).'|'.(string)($part['year'] ?? '')]=$part;
            $parts=[];foreach(preg_split('/\r?\n/',(string)($body['parts'] ?? '')) as $line){$line=trim($line);if($line==='')continue;$bits=explode('|',$line,2);$title=trim($bits[0]);$year=isset($bits[1])&&trim($bits[1])!==''?(int)trim($bits[1]):null;$parts[]=array_replace($known[mb_strtolower($title).'|'.(string)$year] ?? [],['title'=>$title,'year'=>$year]);if(count($parts)>300)throw new RuntimeException('Up to 300 titles per collection.');}
            $payload=['name'=>(string)($body['name'] ?? ''),'description'=>(string)($body['description'] ?? ''),'type'=>'movies','parts'=>$parts];if(trim($payload['name'])==='')throw new RuntimeException('Give the collection a name.');
            return self::keepResult('collections-admin',CompatibilityApi::call($id===''?'/admin/collections':'/admin/collections/'.rawurlencode($id),'POST',$payload));
        }
        if($action==='live-channel'){
            require_once STREAM_ROOT.'/src/Live/tvstudio.php';tvs_apply_channel_edits([['id'=>$id,'name'=>(string)($body['name'] ?? ''),'number'=>(int)($body['number'] ?? 1),'enabled'=>!empty($body['enabled'])]]);
            $studio=tvs_load();$channel=$studio['channels'][$id] ?? tvs_default_channel();$channel['mode']=($body['mode'] ?? '')==='calendar'?'calendar':'overlay';$channel['timeshift']=!empty($body['timeshift']);$channel['group']=(string)($body['group'] ?? '');$visibility=(string)($body['visibility'] ?? $channel['access']['visibility']);if(!in_array($visibility,['everyone','signed-in','admins','users'],true))throw new RuntimeException('Choose a valid channel audience.');$channel['access']['visibility']=$visibility;$studio['channels'][$id]=$channel;tvs_save($studio);return ['view'=>'live-admin','id'=>$id];
        }
        if($action==='iptv-feed'){
            require_once STREAM_ROOT.'/src/Live/iptv.php';$state=iptv_load();$feeds=$state['feeds'] ?? [];$feeds[]=['name'=>(string)($body['name'] ?? ''),'url'=>(string)($body['url'] ?? ''),'enabled'=>true];$result=iptv_update_meta(['feeds'=>$feeds]);return self::keepResult('iptv',$result);
        }
        if($action==='configuration'){
            $settings=CompatibilityApi::call('/admin/settings')['settings'];$nodes=self::configNodes($settings);$payload=[];$group=(string)($body['group'] ?? '');
            foreach($nodes as $path=>$old){if(explode('.',$path)[0]!==$group)continue;$name=hash('sha256',$path);$value=$body['values'][$name] ?? '';if(is_bool($old))$value=!empty($value);elseif(is_int($old)){$value=filter_var($value,FILTER_VALIDATE_INT);if($value===false)throw new RuntimeException('Use a whole number for '.$path);}elseif(is_float($old)){if(!is_numeric($value))throw new RuntimeException('Use a number for '.$path);$value=(float)$value;}else $value=(string)$value;
                if(preg_match('/password|secret|token|api_key/i',$path) && $value==='')continue;
                self::putConfig($payload,$path,$value);
            }
            return self::keepResult('settings',CompatibilityApi::call('/admin/settings','POST',$payload));
        }
        if($action==='new-library'){
            $settings=CompatibilityApi::call('/admin/settings')['settings'];$sections=$settings['sections'] ?? [];$sections[]=['id'=>'library_'.bin2hex(random_bytes(5)),'type'=>(string)($body['type'] ?? 'movies'),'label'=>(string)($body['label'] ?? ''),'path'=>(string)($body['path'] ?? ''),'enabled'=>true];
            return self::keepResult('settings',CompatibilityApi::call('/admin/settings','POST',['sections'=>$sections]));
        }
        if($action==='user-create')return self::keepResult('users',CompatibilityApi::call('/admin/users','POST',['username'=>(string)($body['username'] ?? ''),'display_name'=>(string)($body['display_name'] ?? ''),'password'=>(string)($body['password'] ?? ''),'role'=>(string)($body['role'] ?? 'user'),'access'=>(string)($body['access'] ?? 'pending')]));
        if($action==='user-permissions'){
            $permissions=[];foreach(Permissions::CAPABILITIES as $key=>$label)if(isset($body['permissions'][$key])&&$body['permissions'][$key]!=='inherit')$permissions[$key]=$body['permissions'][$key]==='1';
            $areas=[];foreach(AreaAccess::AREAS as $area)$areas[$area]=!empty($body['areas'][$area]);return self::keepResult('users',CompatibilityApi::call('/admin/users/'.rawurlencode($id).'/permissions','POST',['role'=>(string)($body['role'] ?? 'user'),'permissions'=>$permissions,'areas'=>$areas]));
        }
        if($action==='user-access')return self::keepResult('users',CompatibilityApi::call('/admin/users/'.rawurlencode($id).'/access','POST',['period'=>(string)($body['period'] ?? 'pending'),'date'=>(string)($body['date'] ?? ''),'mode'=>(string)($body['mode'] ?? 'set')]));
        if($action==='user-delete'){if(($body['confirm'] ?? '')!=='DELETE')throw new RuntimeException('Type DELETE to confirm.');return self::keepResult('users',CompatibilityApi::call('/admin/users/'.rawurlencode($id),'DELETE'));}
        if($action==='role-save'){$permissions=[];foreach(Permissions::CAPABILITIES as $key=>$label)$permissions[$key]=!empty($body['permissions'][$key]);return self::keepResult('roles',CompatibilityApi::call('/admin/roles','POST',['id'=>$id,'name'=>(string)($body['name'] ?? ''),'permissions'=>$permissions,'delete'=>!empty($body['delete']),'replacement_role'=>(string)($body['replacement_role'] ?? '')]));}
        if($action==='factory-reset'){CompatibilityApi::call('/admin/factory-reset','POST',['confirmation'=>(string)($body['confirmation'] ?? ''),'password'=>(string)($body['password'] ?? '')]);return ['view'=>'home','overview'=>'1'];}
        return null;
    }
    private static function administrationPage(string $view,string $id): ?string {
        if(!in_array($view,['configuration','users','edit-user','roles','edit-role','factory-reset','manage-collection','live-admin'],true))return null;self::toolPermission('admin');
        if($view==='manage-collection'){
            $collection=$id!==''?Collections::get($id):['name'=>'','description'=>'','parts'=>[]];if(!$collection)throw new RuntimeException('Collection not found.');$lines=[];foreach($collection['parts'] ?? [] as $part)$lines[]=($part['title'] ?? '').(!empty($part['year'])?' | '.$part['year']:'');
            return self::heading('ADMINISTRATION','Edit movie collection').self::beginForm(['view'=>'manage-collection','id'=>$id],'collection-save').self::field('Name','name',(string)$collection['name']).'<label>Description<textarea name="description" rows="3">'.self::esc($collection['description'] ?? '').'</textarea></label><label>Titles in playback order · one per line, optional year after |<textarea name="parts" rows="12">'.self::esc(implode("\n",$lines)).'</textarea></label><button type="submit">Save collection</button></form>';
        }
        if($view==='live-admin'){
            require_once STREAM_ROOT.'/src/Live/tvstudio.php';require_once STREAM_ROOT.'/src/Live/iptv.php';$station=relay_load_station();$html=self::heading('ADMINISTRATION','Live settings');$selected=null;
            foreach($station['channels'] ?? [] as $channel){$html.=self::link($channel['name'],['view'=>'live-admin','id'=>$channel['id']]);if($channel['id']===$id)$selected=$channel;}
            if($selected){$studio=tvs_load();$channel=$studio['channels'][$id] ?? tvs_default_channel();$html.=self::beginForm(['view'=>'live-admin','id'=>$id],'live-channel').self::field('Name','name',$selected['name']).self::field('Channel number','number',(string)$selected['number'],'number',4).'<label class="compat-check"><input type="checkbox" name="enabled" value="1"'.(!empty($selected['enabled'])?' checked="checked"':'').'> Enabled</label>'.self::select('Schedule mode','mode',['overlay'=>'Relay cycle with scheduled overrides','calendar'=>'Scheduled programmes and filler'],$channel['mode']).self::field('Group','group',$channel['group']).self::select('Visibility','visibility',['everyone'=>'Everyone with Live access','signed-in'=>'Signed-in accounts','admins'=>'Administrators only','users'=>'Existing named-user audience'],$channel['access']['visibility']).'<label class="compat-check"><input type="checkbox" name="timeshift" value="1"'.(!empty($channel['timeshift'])?' checked="checked"':'').'> Allow time shifting</label><button type="submit">Save channel</button></form>';}
            return $html.self::beginForm(['view'=>'live-admin'],'iptv-feed').'<h3>Add IPTV source</h3>'.self::field('Name','name').self::field('Playlist URL','url','','url',2000).'<button type="submit">Add IPTV source</button></form>';
        }
        if($view==='configuration'){
            $settings=CompatibilityApi::call('/admin/settings')['settings'];$html=self::heading('ADMINISTRATION','Application settings');$groups=[];foreach(self::configNodes($settings) as $path=>$value)$groups[explode('.',$path)[0]]=true;$group=(string)($_GET['group'] ?? '');
            foreach($groups as $key=>$unused)$html.=self::link(ucwords(str_replace('_',' ',$key)),['view'=>'configuration','group'=>$key]);
            if(isset($groups[$group])){$html.=self::beginForm(['view'=>'configuration','group'=>$group],'configuration',false,['group'=>$group]).'<h3>'.self::esc(ucwords(str_replace('_',' ',$group))).'</h3>';
                foreach(self::configNodes($settings) as $path=>$value){if(explode('.',$path)[0]!==$group)continue;$name='values['.hash('sha256',$path).']';$label=ucwords(str_replace(['_','.'],[' ',' / '],$path));
                    if(is_bool($value))$html.='<label class="compat-check"><input type="checkbox" name="'.$name.'" value="1"'.($value?' checked="checked"':'').'> '.self::esc($label).'</label>';
                    else {$secret=(bool)preg_match('/password|secret|token|api_key/i',$path);$html.=self::field($label.($secret?' · blank keeps current value':''),$name,$secret?'':(string)$value,$secret?'password':(is_int($value)||is_float($value)?'number':'text'),4000);}
                }$html.='<button type="submit">Save settings</button></form>';
            }
            $html.=self::beginForm(['view'=>'configuration'],'new-library').'<h3>Add a library</h3>'.self::field('Name','label').self::field('Media folder','path','','text',1000).self::select('Type','type',['movies'=>'Films','tv'=>'TV','anime'=>'Anime','music'=>'Music'],'movies').'<button type="submit">Add library</button></form>';
            return $html.self::link('Here be dragons · reset application',['view'=>'factory-reset']);
        }
        if($view==='factory-reset')return self::heading('HERE BE DRAGONS','Reset Vaultcast','Erases accounts, lists, settings and the database catalogue. Media files remain untouched.').self::beginForm(['view'=>'factory-reset'],'factory-reset').self::field('Type RESET VAULTCAST','confirmation').self::field('Your administrator password','password','','password',72).'<button type="submit">Reset application</button></form>';
        $roles=['user'=>'User'];foreach(Permissions::roles() as $key=>$role)$roles[$key]=$role['name'];
        if($view==='users'){
            $data=CompatibilityApi::call('/admin/users');$html=self::heading('ADMINISTRATION','Users and roles').self::link('Configure roles',['view'=>'roles']);foreach($data['users'] as $user)$html.='<div class="compat-panel"><strong>'.self::esc($user['display_name'] ?? $user['username']).'</strong><p>'.self::esc($user['role']).' · '.self::esc($user['access_state']['state']).'</p>'.self::link('Edit user',['view'=>'edit-user','id'=>$user['id']]).'</div>';
            return $html.self::beginForm(['view'=>'users'],'user-create').'<h3>Create user</h3>'.self::field('Username','username').self::field('Display name','display_name').self::field('Password · 12–72 bytes','password','','password',72).self::select('Role','role',$roles+['admin'=>'Administrator'],'user').self::select('Access','access',['pending'=>'Awaiting approval','unlimited'=>'Unlimited','1w'=>'1 week','1m'=>'1 month','3m'=>'3 months','1y'=>'1 year'],'pending').'<button type="submit">Create user</button></form>';
        }
        if($view==='edit-user'){
            $data=CompatibilityApi::call('/admin/users');$target=null;foreach($data['users'] as $user)if($user['id']===$id)$target=$user;if(!$target)throw new RuntimeException('User not found.');$html=self::heading('ADMINISTRATION',(string)$target['username']);if($target['role']==='admin')return $html.'<p>Administrators have full access.</p>';
            $params=['view'=>'edit-user','id'=>$id];$html.=self::beginForm($params,'user-permissions').self::select('Role','role',$roles,$target['role']);
            foreach(AreaAccess::AREAS as $area)$html.='<label class="compat-check"><input type="checkbox" name="areas['.$area.']" value="1"'.(AreaAccess::allowed($target,$area)?' checked="checked"':'').'> '.self::esc(ucwords($area)).'</label>';
            foreach(Permissions::CAPABILITIES as $key=>$label)$html.=self::select($label,'permissions['.$key.']',['inherit'=>'Use role setting','1'=>'Allow','0'=>'Deny'],isset($target['permissions'][$key])?($target['permissions'][$key]?'1':'0'):'inherit');
            $html.='<button type="submit">Save role and permissions</button></form>'.self::beginForm($params,'user-access').'<h3>Access period</h3>'.self::select('Period','period',['revoke'=>'Revoke access','unlimited'=>'Unlimited','1w'=>'1 week','1m'=>'1 month','3m'=>'3 months','1y'=>'1 year','custom'=>'Until date'],'unlimited').self::field('Date (YYYY-MM-DD)','date').self::select('Mode','mode',['set'=>'Replace access period','extend'=>'Extend access period'],'set').'<button type="submit">Save access</button></form>';
            return $html.self::beginForm($params,'user-delete').'<h3>Delete user</h3>'.self::field('Type DELETE','confirm').'<button type="submit">Delete user</button></form>';
        }
        if($view==='roles'){$html=self::heading('ADMINISTRATION','Roles').self::link('Create role',['view'=>'edit-role']);foreach(Permissions::roles() as $key=>$role)$html.=self::link($role['name'],['view'=>'edit-role','id'=>$key]);return $html;}
        $role=Permissions::roles()[$id] ?? ['name'=>'','permissions'=>[]];$html=self::heading('ADMINISTRATION',$id?'Edit role':'Create role').self::beginForm(['view'=>'edit-role','id'=>$id],'role-save').self::field('Name','name',$role['name']);foreach(Permissions::CAPABILITIES as $key=>$label)$html.='<label class="compat-check"><input type="checkbox" name="permissions['.$key.']" value="1"'.(!empty($role['permissions'][$key])?' checked="checked"':'').'> '.self::esc($label).'</label>';
        $html.='<button type="submit">Save role</button></form>';if($id){$replacements=['user'=>'Standard User'];foreach(Permissions::roles() as $key=>$candidate)if($key!==$id)$replacements[$key]=$candidate['name'];$html.=self::beginForm(['view'=>'edit-role','id'=>$id],'role-save',false,['delete'=>'1']).'<p>Deleting this role moves assigned users to the selected role and clears individual permission overrides. Media access and expiry stay unchanged.</p>'.self::select('Replacement role','replacement_role',$replacements,'user').'<button type="submit">Delete role and reassign users</button></form>';}return $html;
    }
}
