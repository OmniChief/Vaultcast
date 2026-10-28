/* Vaultcast AI → Images */
(function(root){
  'use strict';
  var A, h, api, timer = 0, host = null, busy = false, imageDimensions = null, currentTab = 'images';
  function call(path, opts){ return api(path, opts).then(function(j){ if (!j || !j.ok) throw j || {}; return j; }); }
  function button(label, fn, cls){ return h('button', { type:'button', className:'btn ' + (cls || ''), text:label, onClick:fn }); }
  function statusLabel(job){ return ({queued:'Queued',running:'Generating',completed:'Ready',failed:'Failed',canceled:'Canceled'})[job.status] || job.status; }
  function time(t){ return t ? new Date(Number(t) * 1000).toLocaleString() : ''; }
  function paint(data){
    if (!host || !host.isConnected || currentTab !== 'images') return;
    if(data.points && A.state.user)A.state.user.contribution_points=data.points;
    var jobs = data.jobs || [], active = jobs.some(function(j){ return j.status === 'queued' || j.status === 'running'; });
    var worker = data.workers || { online:0, total:0 };
    var capacity = Number(worker.max_image_pixels || worker.configured_max_image_pixels || 0);
    if (imageDimensions && !imageDimensions.touched && capacity > 0) {
      imageDimensions.width.value = imageDimensions.height.value = capacity < 1048576 ? '512' : '1024';
    }
    var summary = h('div', { className:'ai-summary' }, [
      h('div', { className:'ai-stat' }, [h('strong', { text:String((data.points || {}).balance || 0) }), h('span', { text:'contribution points' })]),
      h('div', { className:'ai-stat' }, [h('strong', { text:String(worker.online || 0) }), h('span', { text:'computers ready' })]),
      h('div', { className:'ai-stat' }, [h('strong', { text:String(data.queue && data.queue.waiting || 0) }), h('span', { text:'images waiting' })]),
      h('div', { className:'ai-stat' }, [h('strong', { text:String(data.queue && data.queue.running || 0) }), h('span', { text:'generating now' })])
    ]);
    if (capacity > 0) summary.appendChild(h('p', { className:'muted ai-capacity-note', text:(worker.max_image_pixels ? 'Online image profiles: ' : 'Configured image profiles: ') + (capacity < 1048576 ? 'up to 512 × 512' : 'up to 1024 × 1024') + '. Contributors only receive jobs within their reported image capacity.' }));
    var cards = jobs.slice(0, 10).map(function(job){
      var body = [h('div', { className:'ai-job-head' }, [h('span', { className:'ai-job-status ai-status-' + job.status, text:statusLabel(job) }), h('time', { className:'muted', text:time(job.created_at) })]),
        h('p', { className:'ai-job-prompt', text:job.prompt })];
      if (job.status === 'queued') body.push(h('p', { className:'muted', text:'Position in queue: ' + (job.queue_position || 'next') }));
      if (job.status === 'running') { var pct = Math.max(1, Math.min(100, Number(job.progress || 1))); body.push(h('div', { className:'ai-progress-wrap' }, [h('div', { className:'ai-progress' + (pct <= 1 ? ' ai-progress-indeterminate' : ''), role:'progressbar', 'aria-valuenow':String(pct), 'aria-valuetext':pct <= 1 ? 'Starting generation' : pct + '%', 'aria-valuemin':'0', 'aria-valuemax':'100' }, [h('span', { style:'width:' + pct + '%' })]), h('small', { text:pct <= 1 ? 'Starting…' : pct + '%' })])); }
      if (job.status === 'failed') body.push(h('p', { className:'bad', text:job.error || 'Generation failed.' }));
      if (job.status === 'queued') body.push(button('Cancel request', function(){ call('/ai/jobs/' + job.id + '/cancel', { method:'POST', body:{} }).then(refresh).catch(showError); }, 'ghost small'));
      if (job.status === 'completed' && job.image_url) body.unshift(h('a', { className:'ai-image-link', href:A.apiUrl(job.image_url), target:'_blank', rel:'noopener' }, [h('img', { src:A.apiUrl(job.image_url), alt:job.prompt, loading:'lazy' })]));
      return h('article', { className:'ai-job ai-job-' + job.status }, body);
    });
    var library = h('section', { className:'hub-panel vc-panel ai-jobs' }, [h('div', { className:'hub-panel-head' }, [h('h2', { text:'Recent generations' }), h('span', { className:'muted', text:Math.min(jobs.length, 10) + ' latest' })]), h('div', { className:'hub-panel-body ai-job-list' }, cards.length ? cards : [h('p', { className:'muted', text:'Your generated images will appear here. You can leave the page while a request waits.' })])]);
    var summaryHost = host.querySelector('.ai-summary-host'), jobsHost = host.querySelector('.ai-jobs-host');
    if (summaryHost) summaryHost.replaceChildren(summary); if (jobsHost) jobsHost.replaceChildren(library);
    if (!document.hidden) schedule(active ? 5000 : 15000); else clearTimeout(timer);
  }
  function showError(e){ if (A && A.toast) A.toast((e && (e.error || e.message)) || 'AI Images could not load.', { kind:'bad' }); }
  function refresh(){ if (!host || !host.isConnected || busy || currentTab !== 'images') return Promise.resolve(); busy = true; return call('/ai/state').then(paint).catch(function(e){ showError(e); }).finally(function(){ busy = false; }); }
  function schedule(ms){ clearTimeout(timer); timer = setTimeout(refresh, ms); }
  function renderLibrary(){
    clearTimeout(timer); host.replaceChildren();
    var query = h('input', { type:'search', placeholder:'Search prompts and image names…', 'aria-label':'Search your generations' });
    var sort = h('select', { 'aria-label':'Sort generations' }, [h('option',{value:'newest',text:'Newest first'}),h('option',{value:'oldest',text:'Oldest first'})]);
    var favorites = h('input', { type:'checkbox' }), selected = {}, offset = 0, total = 0, busyLibrary = false, pendingReload = false;
    var grid = h('div', { className:'ai-library-grid' }), countLabel = h('span',{className:'muted'}), more = button('Load more', function(){offset+=24;load(true);}, 'ghost'), del = button('Delete selected', deleteSelected, 'danger');
    var controls = h('div',{className:'ai-library-controls'},[query,sort,h('label',{className:'ai-check'},[favorites,h('span',{text:'Favorites only'})]),countLabel,del]);
    var panel = h('section',{className:'hub-panel vc-panel'},[h('div',{className:'hub-panel-head'},[h('h2',{text:'Your image library'}),h('span',{className:'muted',text:'Search, favorite, and organize your creations'})]),h('div',{className:'hub-panel-body'},[controls,grid,h('div',{className:'ai-library-more'},[more])])]);
    host.append(h('header',{className:'vc-hero ai-hero'},[h('div',{className:'vc-hero-copy'},[h('span',{className:'eyebrow',text:'AI Studio'}),h('h1',{text:'Library'}),h('p',{className:'muted',text:'A searchable home for your generated images.'})])]),panel);
    var debounce=0;
    function load(append){
      if(busyLibrary){if(!append)pendingReload=true;return;}busyLibrary=true;
      if(!append){offset=0;grid.replaceChildren();selected={};}
      var params='?limit=24&offset='+offset+'&sort='+encodeURIComponent(sort.value)+'&favorites='+(favorites.checked?'1':'0')+'&q='+encodeURIComponent(query.value.trim());
      call('/ai/library'+params).then(function(data){total=data.total||0;countLabel.textContent=total+' image'+(total===1?'':'s');(data.items||[]).forEach(function(item){
        var check=h('input',{type:'checkbox','aria-label':'Select '+item.name});check.checked=!!selected[item.id];check.onchange=function(){if(check.checked)selected[item.id]=true;else delete selected[item.id];};
        var img=h('img',{src:A.apiUrl(item.url),alt:item.prompt||item.name,loading:'lazy'});
        var image=h('a',{className:'ai-library-image',href:A.apiUrl(item.url),target:'_blank',rel:'noopener','aria-label':'Open '+item.name},[img]);
        var star=button(item.favorite?'★':'☆',function(){call('/ai/library/'+item.id+'/favorite',{method:'POST',body:{favorite:!item.favorite}}).then(function(){load(false);}).catch(showError);},'ghost small');star.setAttribute('aria-label',item.favorite?'Remove favorite':'Add favorite');
        var card=h('article',{className:'ai-library-card'},[image,h('div',{className:'ai-library-card-head'},[check,h('time',{className:'muted',text:time(item.created_at)}),star]),h('p',{className:'ai-library-prompt',text:item.prompt||item.name}),h('div',{className:'ai-library-meta'},[h('span',{text:item.width+' × '+item.height}),h('span',{text:'Seed '+(item.seed===null||item.seed===undefined?'random':item.seed)}),h('span',{text:(item.steps||'?')+' steps · '+(item.scheduler||'')})]),h('a',{className:'btn ghost small',href:A.apiUrl(item.url),download:item.name,text:'Download'})]);grid.appendChild(card);
      });if(!append&&!total)grid.appendChild(h('p',{className:'muted',text:'Nothing matches this view. Try clearing your search or favorites filter.'}));more.hidden=!(data.has_more);}).catch(showError).finally(function(){busyLibrary=false;if(pendingReload){pendingReload=false;load(false);}});
    }
    function deleteSelected(){var ids=Object.keys(selected);if(!ids.length)return;A.confirmDialog('Delete '+ids.length+' generated image'+(ids.length===1?'':'s')+'? This also removes them from your picture library.',{okText:'Delete',danger:true}).then(function(ok){if(!ok)return;call('/ai/library/delete',{method:'POST',body:{ids:ids}}).then(function(){load(false);A.toast('Selected images deleted');}).catch(showError);});}
    query.oninput=function(){clearTimeout(debounce);debounce=setTimeout(function(){load(false);},250);};sort.onchange=function(){load(false);};favorites.onchange=function(){load(false);};load(false);
  }
  function renderModeration(){
    if (!A.state.user || A.state.user.role !== 'admin') {
      host.appendChild(h('p', { text:'Administrator access required.' })); return;
    }
    var pageHost = host, offset = 0, loading = false, pending = false, ownersLoaded = false, debounce = 0;
    var search = h('input', { type:'search', placeholder:'Search prompts, names, or users…', 'aria-label':'Search generations' });
    var owner = h('select', { 'aria-label':'Filter by user' }, [h('option', { value:'', text:'All users' })]);
    var sort = h('select', { 'aria-label':'Sort generations' }, [h('option', { value:'newest', text:'Newest first' }), h('option', { value:'oldest', text:'Oldest first' })]);
    var count = h('span', { className:'muted', role:'status' }), grid = h('div', { className:'ai-library-grid' });
    var more = button('Load more', function(){ load(true); }, 'ghost'); more.hidden = true;
    host.append(h('header', { className:'vc-hero ai-hero' }, [h('div', { className:'vc-hero-copy' }, [
      h('span', { className:'eyebrow', text:'AI Studio · Administrator' }), h('h1', { text:'Moderation' }),
      h('p', { className:'muted', text:'Review generated images and their prompts across all accounts, and delete inappropriate images.' })
    ])]), h('section', { className:'hub-panel vc-panel' }, [h('div', { className:'hub-panel-body' }, [
      h('div', { className:'ai-library-controls' }, [search, owner, sort, count]), grid,
      h('div', { className:'ai-library-more' }, [more])
    ])]));
    function load(append){
      if (!pageHost.isConnected) return;
      if (loading) { if (!append) pending = true; return; }
      loading = true; more.disabled = true;
      var nextOffset = append ? offset + 24 : 0;
      if (!append) grid.replaceChildren();
      var params = '?limit=24&offset=' + nextOffset + '&sort=' + sort.value + '&owner_id=' + encodeURIComponent(owner.value) + '&q=' + encodeURIComponent(search.value.trim());
      call('/ai/moderation/library' + params).then(function(data){
        if (!pageHost.isConnected) return;
        offset = nextOffset;
        count.textContent = data.total + ' generation' + (data.total === 1 ? '' : 's');
        if (!ownersLoaded) {
          (data.owners || []).forEach(function(user){ owner.appendChild(h('option', { value:user.id, text:user.name })); });
          ownersLoaded = true;
        }
        (data.items || []).forEach(function(item){
          var url = A.apiUrl(item.url);
          grid.appendChild(h('article', { className:'ai-library-card' }, [
            h('a', { className:'ai-library-image', href:url, target:'_blank', rel:'noopener', 'aria-label':'Open generation by ' + item.owner_name }, [h('img', { src:url, alt:item.prompt || item.name, loading:'lazy' })]),
            h('div', { className:'ai-library-card-head' }, [h('strong', { text:item.owner_name }), h('time', { className:'muted', text:time(item.created_at) })]),
            h('p', { className:'ai-library-prompt', text:item.prompt || item.name }),
            h('details', { className:'ai-review-details' }, [h('summary', { text:'Full prompt and settings' }),
              h('p', { text:item.prompt || '' }), h('p', { text:'Negative prompt: ' + (item.negative_prompt || 'None') }),
              h('p', { className:'muted', text:item.width + ' × ' + item.height + ' · ' + item.steps + ' steps · CFG ' + item.cfg_scale + ' · ' + item.scheduler + ' · Seed ' + (item.seed == null ? 'random' : item.seed) })
            ]),
            button('Delete image', function(){
              A.confirmDialog('Delete this generation by ' + item.owner_name + '? This permanently removes the image from their library and recent generations.', {okText:'Delete image',danger:true}).then(function(ok){
                if (!ok) return;
                call('/ai/moderation/pictures/' + item.id + '/delete', {method:'POST',body:{}}).then(function(){load(false);A.toast('Image deleted');}).catch(showError);
              });
            }, 'danger small')
          ]));
        });
        if (!append && !data.total) grid.appendChild(h('p', { className:'muted', text:'No generations match this view.' }));
        more.hidden = !data.has_more;
      }).catch(showError).finally(function(){
        loading = false; more.disabled = false;
        if (pending) { pending = false; load(false); }
      });
    }
    search.oninput = function(){ clearTimeout(debounce); debounce = setTimeout(function(){ load(false); }, 250); };
    owner.onchange = sort.onchange = function(){ load(false); };
    load(false);
  }
  function render(target, tab){
    host = target; currentTab = tab || 'images'; imageDimensions = null; clearTimeout(timer); host.replaceChildren(); host.classList.add('vc-ai');
    if(tab==='library'){renderLibrary();return;}
    if(tab==='moderation'){renderModeration();return;}
    var prompt = h('textarea', { rows:'4', maxlength:'1200', placeholder:'Describe the image you want to create…', required:true, className:'ai-prompt' });
    var negative = h('textarea', { rows:'2', maxlength:'600', placeholder:'Optional: things to avoid', className:'ai-negative' });
    var width = h('input', { type:'number', min:'512', max:'1536', step:'8', value:'1024', required:true, 'aria-label':'Image width' });
    var height = h('input', { type:'number', min:'512', max:'1536', step:'8', value:'1024', required:true, 'aria-label':'Image height' });
    imageDimensions = { width:width, height:height, touched:false };
    width.oninput = height.oninput = function(){ imageDimensions.touched = true; };
    var steps = h('input', { type:'number', min:'1', max:'100', step:'1', value:'30', required:true, 'aria-label':'Generation steps' });
    var count = h('input', { type:'number', min:'1', max:'3', step:'1', value:'1', required:true, 'aria-label':'Number of images' });
    var cfg = h('input', { type:'number', min:'0', max:'30', step:'0.1', value:'7', required:true, 'aria-label':'CFG scale' });
    var seed = h('input', { type:'number', min:'0', max:'4294967295', step:'1', placeholder:'Random', 'aria-label':'Seed' });
    var scheduler = h('select', { 'aria-label':'Scheduler' }, [
      ['ddim','DDIM'],['ddpm','DDPM'],['deis','DEIS'],['deis_k','DEIS Karras'],['lms','LMS'],['lms_k','LMS Karras'],['pndm','PNDM'],['heun','Heun'],['heun_k','Heun Karras'],
      ['euler','Euler'],['euler_k','Euler Karras'],['euler_a','Euler Ancestral'],['kdpm_2','KDPM 2'],['kdpm_2_k','KDPM 2 Karras'],['kdpm_2_a','KDPM 2 Ancestral'],['kdpm_2_a_k','KDPM 2 Ancestral Karras'],
      ['dpmpp_2s','DPM++ 2S'],['dpmpp_2s_k','DPM++ 2S Karras'],['dpmpp_2m','DPM++ 2M'],['dpmpp_2m_k','DPM++ 2M Karras'],['dpmpp_2m_sde','DPM++ 2M SDE'],['dpmpp_2m_sde_k','DPM++ 2M SDE Karras'],
      ['dpmpp_3m','DPM++ 3M'],['dpmpp_3m_k','DPM++ 3M Karras'],['dpmpp_sde','DPM++ SDE'],['dpmpp_sde_k','DPM++ SDE Karras'],['er_sde','ER-SDE'],['unipc','UniPC'],['unipc_k','UniPC Karras'],['lcm','LCM'],['tcd','TCD']
    ].map(function(v){ return h('option', { value:v[0], text:v[1] }); })); scheduler.value = 'euler_a';
    var hiDiffusion = h('input', { type:'checkbox' });
    var hiRaunet = h('input', { type:'checkbox' }); hiRaunet.checked = true;
    var hiWindowAttn = h('input', { type:'checkbox' }); hiWindowAttn.checked = true;
    var hiT1 = h('input', { type:'number', min:'0', max:'1', step:'0.05', value:'0.4' });
    var hiT2 = h('input', { type:'number', min:'0', max:'1', step:'0.05', value:'0' });
    var fp32 = h('input', { type:'checkbox', checked:true });
    var tiled = h('input', { type:'checkbox', checked:true });
    var seamlessX = h('input', { type:'checkbox' });
    var seamlessY = h('input', { type:'checkbox' });
    function check(label, input){ return h('label', { className:'ai-check' }, [input, h('span', { text:label })]); }
    function number(label, input){ return h('label', { className:'ai-number-field' }, [h('span', { text:label }), input]); }
    var advanced = h('details', { className:'ai-advanced' }, [h('summary', { text:'Advanced generation options' }), h('div', { className:'ai-advanced-grid' }, [
      check('HiDiffusion', hiDiffusion), check('RAUNet', hiRaunet), check('Window attention', hiWindowAttn), number('HiDiffusion T1 ratio', hiT1), number('HiDiffusion T2 ratio', hiT2),
      check('FP32 VAE decode', fp32), check('Tiled VAE decode', tiled), check('Seamless horizontal tiling', seamlessX), check('Seamless vertical tiling', seamlessY)
    ])]);
    var form = h('form', { className:'hub-panel vc-panel ai-form', onSubmit:function(e){
      e.preventDefault(); if (busy) return;
      if (Number(width.value) * Number(height.value) > 1048576) { showError({ error:'Image dimensions must total no more than 1 megapixel.' }); return; }
      var submit=form.querySelector('[type=submit]'); submit.disabled=true;
      call('/ai/jobs', { method:'POST', body:{ prompt:prompt.value, negative_prompt:negative.value, width:Number(width.value), height:Number(height.value), count:Number(count.value), steps:Number(steps.value), cfg_scale:Number(cfg.value), scheduler:scheduler.value, seed:seed.value.trim()==='' ? null : Number(seed.value), hidiffusion:hiDiffusion.checked, hidiffusion_raunet:hiRaunet.checked, hidiffusion_window_attn:hiWindowAttn.checked, hidiffusion_t1_ratio:Number(hiT1.value), hidiffusion_t2_ratio:Number(hiT2.value), fp32:fp32.checked, tiled:tiled.checked, seamless_x:seamlessX.checked, seamless_y:seamlessY.checked } }).then(function(){ prompt.value=''; return refresh(); }).catch(showError).finally(function(){ submit.disabled=false; });
    } }, [
      h('div', { className:'hub-panel-head' }, [h('h2', { text:'Create an image' })]),
      h('div', { className:'hub-panel-body' }, [
        h('label', { className:'vc-field' }, [h('span', { text:'Prompt' }), prompt]),
        h('label', { className:'vc-field' }, [h('span', { text:'Negative prompt' }), negative]),
        h('div', { className:'ai-settings-grid' }, [number('Width', width), number('Height', height), number('Images', count), number('Steps', steps), number('CFG scale', cfg), number('Scheduler', scheduler), number('Seed · blank for random', seed)]),
        advanced,
        h('div', { className:'ai-form-actions' }, [h('p', { className:'muted ai-note', text:'Each image costs 2 contribution points, refunded if it fails or is canceled. Contributors earn 2 points per delivered image and 5 per completed video conversion. Generations are saved to your library and may be reviewed by administrators.' }), h('button', { type:'submit', className:'btn primary', text:'Add to queue · 2 points per image' })])
      ])
    ]);
    var worker = h('details', { className:'hub-panel vc-panel ai-worker-panel' }, [
      h('summary', { className:'hub-panel-head ai-worker-summary' }, [h('h2', { text:'Contribute a computer' }), h('span', { className:'muted', text:'Optional' })]),
      h('div', { className:'hub-panel-body' }, [h('p', { className:'muted', text:'Run Optional/AIWorker/start-worker.cmd on the Windows PC you want to contribute. Choose AI images, video encoding, or both. Encoding can install portable FFmpeg and does not need InvokeAI or a GPU. AI setup checks compatible hardware and can install an engine and model. Pair using your own account to earn contribution points. Encoding requires Library access and only receives videos your account can access.' }), button('Pair a computer', function(){ call('/ai/pair', { method:'POST', body:{} }).then(function(j){ var code = h('code', { className:'ai-pair-code', text:j.pair_code }); var box = h('div', { className:'ai-pair-result' }, [h('p', { text:'Once contributor setup says Contributor ready, enter this one-time code within 10 minutes.' }), code, h('p', { className:'muted', text:'Contributor software runs on that PC. Credentials and capability reports are saved in its Windows AppData folder.' })]); var old = host.querySelector('.ai-pair-result'); if (old) old.replaceWith(box); else host.querySelector('.ai-worker-panel .hub-panel-body').appendChild(box); }).catch(showError); }, 'ghost')])
    ]);
    var summaryHost = h('div', { className:'ai-summary-host' }), jobsHost = h('div', { className:'ai-jobs-host' });
    host.append(h('header', { className:'vc-hero ai-hero' }, [h('div', { className:'vc-hero-copy' }, [h('span', { className:'eyebrow', text:'AI Studio' }), h('h1', { text:'Images' }), h('p', { className:'muted', text:'Create images with the Vaultcast community’s available computers.' })])]), summaryHost, form, jobsHost, worker);
    refresh();
    document.addEventListener('visibilitychange', onVisibility);
  }
  function onVisibility(){ if (document.hidden) clearTimeout(timer); else refresh(); }
  function stop(){ clearTimeout(timer); document.removeEventListener('visibilitychange', onVisibility); host = null; }
  root.VaultcastAI = { init:function(app){ A=app; h=app.h; api=app.api; }, render:render, stop:stop };
})(this);
