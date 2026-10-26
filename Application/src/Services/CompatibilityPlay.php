<?php
declare(strict_types=1);

/** Play's HTML baseline. Interactive engines are loaded only on request. */
trait CompatibilityPlay {
    private static function playTabs(): array {
        return ['games'=>'Games','arcade'=>'Arcade','quiz'=>'Quiz nights','social'=>'Teams & friends','leaderboards'=>'Leaderboards','achievements'=>'Achievements'];
    }
    private static function playAsset(string $file): string {
        return self::$base.'index.php?asset='.rawurlencode('assets/js/'.$file).'&v='.substr(hash_file('sha256',STREAM_ROOT.'/assets/js/'.$file),0,16);
    }
    private static function playConfig(array $config): string {
        return '<script>window.__VC_COMPAT_PLAY__='.json_encode($config+['base'=>self::$base,'user'=>['id'=>self::$viewer['id'],'role'=>self::$viewer['role'],'prefs'=>self::$viewer['prefs'] ?? []]],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><script defer src="'.self::esc(self::playAsset('compatibility-play.js')).'"></script>';
    }
    private static function playPage(array $home,string $view,string $id,int $page,string $query): ?string {
        if(!in_array($view,['play','game','emulator'],true))return null;
        self::allow('games');
        if($view==='game'||$view==='emulator')return self::gamePage($id,$view==='emulator');
        $tab=(string)($_GET['tab'] ?? 'games');if(!isset(self::playTabs()[$tab]))throw new RuntimeException('Play page not found.');
        $html=self::heading('PLAY',self::playTabs()[$tab]);
        if($tab==='games'){
            $sections=array_values(array_filter($home['sections'] ?? [],static function($s){return ($s['type'] ?? '')==='roms';}));
            $sectionId=(string)($_GET['section'] ?? ($sections[0]['id'] ?? ''));$section=null;
            foreach($sections as $s){$html.=self::link($s['label'] ?? 'Games',['view'=>'play','section'=>$s['id']]);if($s['id']===$sectionId)$section=$s;}
            if(!$section)return $html.'<p class="compat-empty">No game library is available. Add a ROM library in library settings.</p>';
            $system=mb_substr((string)($_GET['system'] ?? ''),0,100);
            $data=CompatibilityApi::call('/sections/'.rawurlencode($sectionId).'/items','GET',[],['page'=>$page,'per'=>16,'q'=>$query,'system'=>$system]);
            $html.='<form method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="play"><input type="hidden" name="section" value="'.self::esc($sectionId).'">'.self::field('Search games','q',$query).self::field('Console','system',$system).'<button type="submit">Search</button></form><ul class="compat-cards">';
            foreach($data['items'] ?? [] as $item)if(($item['media_kind'] ?? '')==='rom')$html.='<li><a class="compat-card" href="'.self::esc(self::url(['view'=>'game','id'=>$item['id']])).'">'.self::poster($item).'<strong>'.self::esc($item['title']).'</strong><small>'.self::esc($item['system'] ?? '').'</small></a></li>';
            return $html.'</ul>'.(empty($data['items'])?'<p>No games match these filters.</p>':'').self::pages(['view'=>'play','section'=>$sectionId,'q'=>$query,'system'=>$system],$page,$page*16<(int)($data['total'] ?? 0));
        }
        if(!self::$signed && !in_array($tab,['arcade','quiz','leaderboards'],true))return $html.'<p>Sign in to use this Play section.</p>'.self::link('Sign in',['view'=>'login']);
        $sub=mb_substr((string)($_GET['sub'] ?? ''),0,160);
        // Keep working HTML below the optional interactive view, including with JavaScript disabled.
        if($tab!=='achievements')$html.='<p id="compat-play-message" role="status">Use the simple view below, or open the interactive view for live controls and more options.</p><button type="button" id="compat-play-enhance" style="display:none">Open interactive view</button><div id="compat-play-interactive"></div><div id="compat-play-baseline">';
        if($tab==='arcade')$html.=self::arcadePage($sub);
        elseif($tab==='quiz')$html.=self::quizPage($sub);
        elseif($tab==='social')$html.=self::teamsPage();
        elseif($tab==='leaderboards')$html.=self::boardsPage();
        elseif($tab==='achievements'){
            $data=Achievements::view(self::$viewer);if(empty($data['enabled']))return $html.'<p>Achievements are disabled.</p>';
            $html.='<p>'.(int)$data['summary']['earned'].' of '.(int)$data['summary']['total'].' achievements earned.</p><ul class="compat-list">';
            foreach($data['achievements'] as $row)$html.='<li><strong>'.self::esc($row['name']).'</strong> · '.self::esc($row['tier_label']).($row['earned']?' · Earned':'').'<p>'.self::esc($row['description']).'</p><small>'.self::esc($row['progress_text']).'</small></li>';
            return $html.'</ul>';
        }
        return $html.'</div>'.self::playConfig(['mode'=>'play','tab'=>$tab,'sub'=>$sub,'assets'=>['vc-play.js'=>self::playAsset('vc-play.js'),'vc-games.js'=>self::playAsset('vc-games.js'),'vc-qr.js'=>self::playAsset('vc-qr.js')]]);
    }
    private static function gamePage(string $id,bool $run): string {
        $item=Catalogue::item($id);if(!$item||($item['media_kind'] ?? '')!=='rom')throw new RuntimeException('Game unavailable.');
        $card=Catalogue::publicItem($item,false);$html=self::heading('PLAY · '.($card['system'] ?? 'GAMES'),$card['title']);
        $favourite='';if(self::$signed){$active=isset(self::personal()['favourites']['item:'.$id]);$favourite=self::form(['view'=>'game','id'=>$id],$active?'Remove favourite':'Favourite game','shelf',['key'=>'item:'.$id,'shelf'=>'favourites','enabled'=>$active?'0':'1']);}
        $core=(string)($item['emu_core'] ?? Catalogue::guessEmuCore((string)($item['ext'] ?? ''),(string)($item['rel'] ?? ''),(string)($item['path'] ?? '')));
        $legacySystem=in_array($core,['segaMD','genesis_plus_gx','picodrive'],true)?'genesis':LegacyNintendo::system($core);
        $legacyLabel=['genesis'=>'Genesis','nes'=>'NES','snes'=>'SNES'][$legacySystem ?? ''] ?? '';
        $legacy=$legacySystem?self::link('Legacy '.$legacyLabel.' · no WebAssembly',['view'=>'emulator','id'=>$id,'engine'=>$legacySystem.'-legacy']):'';
        if(!$run)return $html.$favourite.'<div class="compat-detail">'.self::poster($card,'detail').'<div><p>'.self::esc(strip_tags($card['synopsis'] ?? '')).'</p>'.self::link('Play game',['view'=>'emulator','id'=>$id]+($legacySystem?['engine'=>$legacySystem.'-legacy']:[])).($legacySystem?self::link('EmulatorJS player',['view'=>'emulator','id'=>$id,'engine'=>'emulatorjs']):'').'<p>Use the player controls or keyboard to play. Press Start game to try emulation on this device.</p></div></div>';
        // Token issuance is deferred until the user starts the selected engine.
        $engine=(string)($_GET['engine'] ?? ($legacySystem?$legacySystem.'-legacy':'emulatorjs'));
        $legacyRun=in_array($engine,['genesis-legacy','nes-legacy','snes-legacy'],true);
        if($legacyRun&&$engine!==$legacySystem.'-legacy')throw new RuntimeException('The selected legacy player does not support this game’s console.');
        $host=$legacyRun?($legacySystem==='genesis'?'compatibility-genesis.js':'compatibility-nintendo.js'):'compatibility-emulator.js';
        $config=['mode'=>$legacyRun?$engine:'emulator','item'=>['id'=>$id,'title'=>$card['title'],'media_kind'=>'rom','mtime'=>$item['mtime'] ?? 0,'size'=>$item['size'] ?? 0],'dependencies'=>PlayerAssets::manifest(),'extras'=>self::playAsset('home-extras.js'),'emulatorScript'=>self::playAsset($host),'legacyRuntime'=>$legacyRun?self::playAsset('compatibility-legacy-runtime.js'):null,'legacyController'=>$legacyRun?self::playAsset('compatibility-controller.js'):null,'legacySystem'=>$legacySystem,'legacyEngine'=>$legacyRun?($legacySystem==='genesis'?LegacyGenesis::url():LegacyNintendo::url($legacySystem)):null];
        return $html.($legacyRun?'<p>Legacy '.self::esc($legacyLabel).' uses its own local save states. EmulatorJS cloud saves cannot be loaded in this player.</p>':$legacy).'<p id="compat-play-message" role="status">Preparing the emulator page…</p><noscript><p>Enable JavaScript to run the emulator. You can still browse the game library.</p></noscript><button type="button" id="compat-game-start" style="display:none">Start game</button> '.self::link('Back to game',['view'=>'game','id'=>$id]).'<div id="compat-controller-config"></div><div id="compat-emulator-stage" tabindex="-1"></div><div id="compat-game-tools" style="display:none"><button id="compat-game-save" type="button">Quick save to slot 1</button> <button id="compat-game-load" type="button">Load slot 1</button> <button id="compat-game-quit" type="button">Save & quit</button></div><div id="compat-game-saves"></div>'.self::playConfig($config);
    }
    private static function arcadePage(string $sub): string {
        $html='';foreach(Arcade::GAMES as $game=>$gameInfo)if($game!=='quiz')$html.=self::link($gameInfo['name'],['view'=>'play','tab'=>'arcade','sub'=>$game]);
        if(!self::$signed)return $html.'<p>Open the interactive view to play arcade games. Sign in to save scores.</p>';
        if($sub==='word'){
            $d=CompatibilityApi::call('/play/word');$html.='<h3>Daily Five · '.self::esc($d['date']).'</h3><p>Five letters, six guesses. Match labels: correct position, wrong position, absent.</p><ol class="compat-word">';
            foreach($d['guesses'] as $guess){$html.='<li>';for($i=0;$i<5;$i++)$html.='<span class="compat-word-'.$guess['marks'][$i].'">'.self::esc(strtoupper($guess['word'][$i])).'<small>'.['g'=>'correct position','y'=>'wrong position','x'=>'absent'][$guess['marks'][$i]].'</small></span>';$html.='</li>';}
            $html.='</ol>';return $html.($d['done']?'<p>'.($d['won']?'Solved!':'The answer was '.self::esc($d['answer'])).'</p>':self::beginForm(['view'=>'play','tab'=>'arcade','sub'=>'word'],'play-word').self::field('Five-letter guess','guess','','text',5).'<button type="submit">Guess</button></form>');
        }
        if(strpos($sub,'chess')===0)return $html.self::chessPage(substr($sub,6));
        return $html.'<p>Stackfall, Neon Snake, 2048 Merge and Brickbreak run in the interactive view. Daily Five and correspondence chess also work in this simple view.</p>';
    }
    private static function chessPage(string $id): string {
        if($id===''){
            $html='<h3>Correspondence chess</h3>';foreach(CompatibilityApi::call('/play/chess')['games'] as $g)$html.=self::link(($g['your_turn']?'Your turn · ':'').$g['status'].' · '.$g['id'],['view'=>'play','tab'=>'arcade','sub'=>'chess/'.$g['id']]);
            $people=CompatibilityApi::call('/play/friends')['friends'];$choices=[];foreach($people as $p)if(($p['state'] ?? '')==='friend')$choices[$p['id']]=$p['name'];
            if(!$choices)return $html.'<p>Add a friend to start a chess game.</p>'.self::link('Friends',['view'=>'friends']);
            return $html.self::beginForm(['view'=>'play','tab'=>'arcade','sub'=>'chess'],'play-chess-new').self::select('Opponent','opponent',$choices,'').self::select('Colour','colour',['white'=>'White','black'=>'Black','random'=>'Coin toss'],'white').'<button type="submit">Challenge</button></form>';
        }
        $g=CompatibilityApi::call('/play/chess/'.rawurlencode($id))['game'];$s=Chess::parse($g['fen']);$html='<h3>'.($g['your_turn']?'Your turn':self::esc($g['status']==='active'?'Waiting for opponent':$g['status'].' · '.$g['reason'])).'</h3><p>You are '.($g['colour']==='w'?'White':'Black').'.</p><table class="compat-chess" aria-label="Chess board"><tbody>';
        $pieces=['K'=>'♔','Q'=>'♕','R'=>'♖','B'=>'♗','N'=>'♘','P'=>'♙','k'=>'♚','q'=>'♛','r'=>'♜','b'=>'♝','n'=>'♞','p'=>'♟','.'=>''];
        for($r=0;$r<8;$r++){$html.='<tr><th>'.(8-$r).'</th>';for($f=0;$f<8;$f++){$sq=(7-$r)*8+$f;$piece=$s['board'][$sq];$html.='<td class="'.(($r+$f)%2?'dark':'light').'" title="'.chr(97+$f).(8-$r).'">'.$pieces[$piece].'</td>';}$html.='</tr>';}
        $html.='</tbody><tfoot><tr><th></th>';foreach(range('a','h') as $f)$html.='<th>'.$f.'</th>';$html.='</tr></tfoot></table><p>Moves: '.self::esc(implode(' ', $g['san'])).'</p>';
        $params=['view'=>'play','tab'=>'arcade','sub'=>'chess/'.$id];
        if($g['your_turn']){$moves=[];foreach($g['legal'] as $move)$moves[$move]=$move;$html.=self::beginForm($params,'play-chess-move').self::select('Move · from square, to square, optional promotion','uci',$moves,'').'<button type="submit">Move</button></form>';}
        if($g['status']==='active')$html.=self::form($params,'Offer / accept draw','play-chess-draw').self::form($params,'Resign','play-chess-resign');
        return $html.self::link('Refresh game',$params);
    }
    private static function quizPage(string $sub): string {
        if(!self::$signed)return '<p>Open the interactive view to join a quiz as a guest, or sign in to host and use the simple quiz pages.</p>'.self::link('Sign in',['view'=>'login']);
        $html=self::link('Daily quiz',['view'=>'play','tab'=>'quiz','sub'=>'daily']);
        if(preg_match('#^(?:host/)?([A-Za-z]{4})$#D',$sub,$room))return $html.self::quizRoomPage(strtoupper($room[1]));
        if($sub!=='daily'){
            $choices=[];foreach(CompatibilityApi::call('/play/quiz/options')['categories'] as $cat)$choices[$cat['id']]=$cat['name'];
            return $html.'<form method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="play"><input type="hidden" name="tab" value="quiz">'.self::field('Room code','sub','','text',4).'<button type="submit">Open room</button></form>'.self::beginForm(['view'=>'play','tab'=>'quiz'],'play-quiz-create').self::field('Quiz title','title','Quiz night').self::select('Category','category',$choices,'general').self::select('Rounds','rounds',['1'=>'1','3'=>'3','5'=>'5'],'3').self::select('Questions per round','per_round',['2'=>'2','5'=>'5','10'=>'10'],'5').self::select('Seconds per question','timer',['20'=>'20','45'=>'45','90'=>'90'],'45').'<button type="submit">Create quiz room</button></form><p>The simple view uses Refresh room to get new questions and results. Open the interactive view for live updates, practice and more options.</p>';
        }
        $d=CompatibilityApi::call('/play/quiz/daily');$html.='<h3>Daily quiz · '.self::esc($d['date']).'</h3><p>'.(int)$d['correct'].' correct · '.(int)$d['answered'].' of '.(int)$d['total'].' answered.</p>';
        foreach($d['questions'] as $q){if((int)$q['n']>(int)$d['answered'])break;$html.='<div class="compat-panel"><h3>'.self::esc($q['question']).'</h3>';
            if(!empty($q['image']))$html.='<img class="compat-quiz-image" src="'.self::esc(self::$base.'api/play/quiz/daily/image/'.(int)$q['n']).'" alt="Quiz picture">';
            if(isset($q['chosen']))$html.='<p>Your answer: '.self::esc($q['choices'][$q['chosen']] ?? 'Skipped').'. Correct answer: '.self::esc($q['choices'][$q['answer']]).'.</p>';
            else foreach($q['choices'] as $i=>$choice)$html.=self::form(['view'=>'play','tab'=>'quiz','sub'=>'daily'],(string)$choice,'play-quiz-answer',['n'=>$q['n'],'choice'=>$i]);$html.='</div>';
        }return $html;
    }
    private static function quizRoomPage(string $code): string {
        $d=CompatibilityApi::call('/play/quiz/rooms/'.$code);$params=['view'=>'play','tab'=>'quiz','sub'=>!empty($d['is_host'])?'host/'.$code:$code];
        $html='<h3>'.self::esc($d['settings']['title']).' · Room '.self::esc($code).'</h3><p>Status: '.self::esc($d['status']).'. '.(int)$d['total'].' questions. Host: '.self::esc($d['host_name']).'.</p>'.self::link('Refresh room',$params);
        if($d['status']==='closed')return $html.'<p>This room is closed.</p>';
        if(empty($d['me'])||!empty($d['me']['left']))$html.=self::form($params,'Join this quiz','play-quiz-join');
        if(!empty($d['question'])){
            $q=$d['question'];$html.='<div class="compat-panel"><h3>'.self::esc($q['question']).'</h3>';
            if(!empty($q['clue']))$html.='<p>'.self::esc($q['clue']).'</p>';
            if(!empty($q['image']))$html.='<img class="compat-quiz-image" src="'.self::esc(self::$base.'api/play/quiz/rooms/'.$code.'/image/'.(int)$q['n']).'" alt="Quiz picture">';
            if($d['status']==='question'&&!empty($d['me'])&&empty($d['me']['left'])&&$d['me']['answer']===null){
                $html.='<p>Answers close at '.gmdate('H:i:s',(int)$q['ends_at']).' UTC.</p>';
                foreach($q['choices'] as $i=>$choice)$html.=self::form($params,(string)$choice,'play-quiz-room-answer',['choice'=>$i,'question'=>$q['n']]);
            }elseif(isset($q['answer']))$html.='<p>Correct answer: '.self::esc($q['choices'][$q['answer']]).'</p>';
            else $html.='<p>Waiting for the answer reveal. Refresh the room to see the result.</p>';
            $html.='</div>';
        }
        $html.='<ol class="compat-list">';foreach($d['players'] as $player)$html.='<li>'.self::esc($player['name']).' · '.(int)$player['score'].' points</li>';$html.='</ol>';
        if(!empty($d['is_host'])){
            if($d['status']==='lobby')$html.=self::form($params,'Start quiz','play-quiz-host',['host_action'=>'start']);
            elseif(in_array($d['status'],['intro','question','reveal','scores'],true))$html.=self::form($params,$d['status']==='question'?'Reveal answers':'Next question','play-quiz-host',['host_action'=>'next']);
            $html.=self::form($params,'Close room','play-quiz-host',['host_action'=>'close']);
        }
        return $html;
    }
    private static function teamsPage(): string {
        $d=CompatibilityApi::call('/play/teams');$html=self::link('Friends',['view'=>'friends']);
        if($d['team'])$html.='<h3>Your team · '.self::esc($d['team']['name']).'</h3>'.self::form(['view'=>'play','tab'=>'social'],'Leave team','play-team',['team_action'=>'leave']);
        else $html.=self::beginForm(['view'=>'play','tab'=>'social'],'play-team',false,['team_action'=>'create']).self::field('Team name','name').'<button type="submit">Create team</button></form>';
        foreach($d['invites'] as $t)$html.='<div class="compat-panel"><h3>Invitation · '.self::esc($t['name']).'</h3>'.self::form(['view'=>'play','tab'=>'social'],'Accept','play-team',['team_action'=>'accept','team_id'=>$t['id']]).self::form(['view'=>'play','tab'=>'social'],'Decline','play-team',['team_action'=>'decline','team_id'=>$t['id']]).'</div>';
        foreach($d['teams'] as $t)$html.='<div class="compat-panel"><strong>'.self::esc($t['name']).'</strong><p>'.(int)$t['points'].' points</p>'.(!$d['team']?self::form(['view'=>'play','tab'=>'social'],'Request to join','play-team',['team_action'=>'request','team_id'=>$t['id']]):'').'</div>';
        return $html;
    }
    private static function boardsPage(): string {
        $boards=['points'=>'Points','teams'=>'Teams','emu-time'=>'Emulator time','emu-sessions'=>'Emulator sessions']+array_map(static function($g){return $g['name'];},Arcade::GAMES);$board=(string)($_GET['board'] ?? 'points');$period=(string)($_GET['period'] ?? 'week');$scope=(string)($_GET['scope'] ?? 'all');
        $d=CompatibilityApi::call('/play/leaderboard','GET',[],['board'=>$board,'period'=>$period,'scope'=>$scope]);
        $html='<form method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="play"><input type="hidden" name="tab" value="leaderboards">'.self::select('Board','board',$boards,$board).self::select('Period','period',['day'=>'Today','week'=>'Week','month'=>'Month','all'=>'All time'],$period).self::select('Players','scope',['all'=>'Everyone','friends'=>'Friends','team'=>'My team'],$scope).'<button type="submit">Show board</button></form><ol class="compat-list">';
        foreach($d['rows'] as $row)$html.='<li><strong>'.self::esc($board==='teams'?$row['team']['name']:$row['user']['name']).'</strong> · '.self::esc(($d['unit'] ?? '')==='seconds'?floor($row['value']/60).' minutes':$row['value']).'</li>';
        return $html.'</ol>'.(empty($d['rows'])?'<p>'.self::esc($d['note'] ?? 'No entries yet.').'</p>':'');
    }
    private static function playPost(array $body): ?array {
        $action=(string)($body['compat_action'] ?? '');if(strpos($action,'play-')!==0)return null;
        self::allow('games');$dest=['view'=>'play'];
        if($action==='play-word'){CompatibilityApi::call('/play/word','POST',['guess'=>(string)($body['guess'] ?? '')]);return $dest+['tab'=>'arcade','sub'=>'word'];}
        if($action==='play-quiz-answer'){CompatibilityApi::call('/play/quiz/daily/answer','POST',['n'=>(int)($body['n'] ?? -1),'choice'=>(int)($body['choice'] ?? -2)]);return $dest+['tab'=>'quiz','sub'=>'daily'];}
        if($action==='play-quiz-create'){
            $d=CompatibilityApi::call('/play/quiz/rooms','POST',['settings'=>['title'=>(string)($body['title'] ?? ''),'categories'=>[(string)($body['category'] ?? 'general')],'rounds'=>(int)($body['rounds'] ?? 3),'per_round'=>(int)($body['per_round'] ?? 5),'timer'=>(int)($body['timer'] ?? 45),'auto'=>false]]);
            return $dest+['tab'=>'quiz','sub'=>'host/'.$d['code']];
        }
        if(in_array($action,['play-quiz-join','play-quiz-host','play-quiz-room-answer'],true)){
            if(!preg_match('#^(?:host/)?([A-Za-z]{4})$#D',(string)($_GET['sub'] ?? ''),$room))throw new RuntimeException('Use a four-letter room code.');$code=strtoupper($room[1]);
            if($action==='play-quiz-join')CompatibilityApi::call('/play/quiz/rooms/'.$code.'/join','POST',[]);
            elseif($action==='play-quiz-host')CompatibilityApi::call('/play/quiz/rooms/'.$code.'/host','POST',['action'=>(string)($body['host_action'] ?? '')]);
            else {
                $state=CompatibilityApi::call('/play/quiz/rooms/'.$code);if((int)($state['question']['n'] ?? -1)!==(int)($body['question'] ?? -2))throw new RuntimeException('The question changed. Refresh the room before answering.');
                CompatibilityApi::call('/play/quiz/rooms/'.$code.'/answer','POST',['choice'=>(int)($body['choice'] ?? -1),'question'=>(int)($body['question'] ?? -2)]);
            }
            return $dest+['tab'=>'quiz','sub'=>(string)$_GET['sub']];
        }
        if($action==='play-team'){CompatibilityApi::call('/play/teams','POST',['action'=>(string)($body['team_action'] ?? ''),'team_id'=>(string)($body['team_id'] ?? ''),'name'=>(string)($body['name'] ?? '')]);return $dest+['tab'=>'social'];}
        if($action==='play-chess-new'){$d=CompatibilityApi::call('/play/chess','POST',['opponent'=>(string)($body['opponent'] ?? ''),'colour'=>(string)($body['colour'] ?? '')]);return $dest+['tab'=>'arcade','sub'=>'chess/'.$d['game']['id']];}
        if(in_array($action,['play-chess-move','play-chess-draw','play-chess-resign'],true)){$sub=(string)($_GET['sub'] ?? '');$id=substr($sub,6);CompatibilityApi::call('/play/chess/'.rawurlencode($id).'/'.substr($action,11),'POST',['move'=>(string)($body['uci'] ?? '')]);return $dest+['tab'=>'arcade','sub'=>$sub];}
        throw new RuntimeException('Unknown Play action.');
    }
}
