/* Vaultcast AI → Images */
(function(root){
  'use strict';
  var A, h, api, timer = 0, host = null, busy = false;
  function call(path, opts){ return api(path, opts).then(function(j){ if (!j || !j.ok) throw j || {}; return j; }); }
  function button(label, fn, cls){ return h('button', { type:'button', className:'btn ' + (cls || ''), text:label, onClick:fn }); }
  function statusLabel(job){ return ({queued:'Queued',running:'Generating',completed:'Ready',failed:'Failed',canceled:'Canceled'})[job.status] || job.status; }
  function time(t){ return t ? new Date(Number(t) * 1000).toLocaleString() : ''; }
  function paint(data){
    if (!host || !host.isConnected) return;
    var jobs = data.jobs || [], active = jobs.some(function(j){ return j.status === 'queued' || j.status === 'running'; });
    var worker = data.workers || { online:0, total:0 };
    var summary = h('div', { className:'ai-summary' }, [
      h('div', { className:'ai-stat' }, [h('strong', { text:String(worker.online || 0) }), h('span', { text:'computers ready' })]),
      h('div', { className:'ai-stat' }, [h('strong', { text:String(data.queue && data.queue.waiting || 0) }), h('span', { text:'images waiting' })]),
      h('div', { className:'ai-stat' }, [h('strong', { text:String(data.queue && data.queue.running || 0) }), h('span', { text:'generating now' })])
    ]);
    var cards = jobs.map(function(job){
      var body = [h('div', { className:'ai-job-head' }, [h('span', { className:'ai-job-status ai-status-' + job.status, text:statusLabel(job) }), h('time', { className:'muted', text:time(job.created_at) })]),
        h('p', { className:'ai-job-prompt', text:job.prompt })];
      if (job.status === 'queued') body.push(h('p', { className:'muted', text:'Position in queue: ' + (job.queue_position || 'next') }));
      if (job.status === 'running') { var pct = Math.max(1, Math.min(100, Number(job.progress || 1))); body.push(h('div', { className:'ai-progress-wrap' }, [h('div', { className:'ai-progress' + (pct <= 1 ? ' ai-progress-indeterminate' : ''), role:'progressbar', 'aria-valuenow':String(pct), 'aria-valuetext':pct <= 1 ? 'Starting generation' : pct + '%', 'aria-valuemin':'0', 'aria-valuemax':'100' }, [h('span', { style:'width:' + pct + '%' })]), h('small', { text:pct <= 1 ? 'Starting…' : pct + '%' })])); }
      if (job.status === 'failed') body.push(h('p', { className:'bad', text:job.error || 'Generation failed.' }));
      if (job.status === 'queued') body.push(button('Cancel request', function(){ call('/ai/jobs/' + job.id + '/cancel', { method:'POST', body:{} }).then(refresh).catch(showError); }, 'ghost small'));
      if (job.status === 'completed' && job.image_url) body.unshift(h('a', { className:'ai-image-link', href:A.apiUrl(job.image_url), target:'_blank', rel:'noopener' }, [h('img', { src:A.apiUrl(job.image_url), alt:job.prompt, loading:'lazy' })]));
      return h('article', { className:'ai-job ai-job-' + job.status }, body);
    });
    var library = h('section', { className:'hub-panel vc-panel ai-jobs' }, [h('div', { className:'hub-panel-head' }, [h('h2', { text:'Your generations' }), h('span', { className:'muted', text:jobs.length + ' recent' })]), h('div', { className:'hub-panel-body ai-job-list' }, cards.length ? cards : [h('p', { className:'muted', text:'Your generated images will appear here. You can leave the page while a request waits.' })])]);
    var summaryHost = host.querySelector('.ai-summary-host'), jobsHost = host.querySelector('.ai-jobs-host');
    if (summaryHost) summaryHost.replaceChildren(summary); if (jobsHost) jobsHost.replaceChildren(library);
    if (active && !document.hidden) schedule(5000); else clearTimeout(timer);
  }
  function showError(e){ if (A && A.toast) A.toast((e && (e.error || e.message)) || 'AI Images could not load.', { kind:'bad' }); }
  function refresh(){ if (!host || !host.isConnected || busy) return Promise.resolve(); busy = true; return call('/ai/state').then(paint).catch(function(e){ showError(e); }).finally(function(){ busy = false; }); }
  function schedule(ms){ clearTimeout(timer); timer = setTimeout(refresh, ms); }
  function render(target){
    host = target; clearTimeout(timer); host.replaceChildren(); host.classList.add('vc-ai');
    var prompt = h('textarea', { rows:'4', maxlength:'1200', placeholder:'Describe the image you want to create…', required:true, className:'ai-prompt' });
    var negative = h('textarea', { rows:'2', maxlength:'600', placeholder:'Optional: things to avoid', className:'ai-negative' });
    var size = h('select', { className:'ai-size', 'aria-label':'Image size' }, [['512','512 × 512'],['768','768 × 768'],['1024','1024 × 1024']].map(function(v){ return h('option', { value:v[0], text:v[1] }); })); size.value = '768';
    var form = h('form', { className:'hub-panel vc-panel ai-form', onSubmit:function(e){ e.preventDefault(); if (busy) return; var submit=form.querySelector('[type=submit]'); submit.disabled=true; call('/ai/jobs', { method:'POST', body:{ prompt:prompt.value, negative_prompt:negative.value, width:Number(size.value), height:Number(size.value) } }).then(function(){ prompt.value=''; return refresh(); }).catch(showError).finally(function(){ submit.disabled=false; }); } }, [
      h('div', { className:'hub-panel-head' }, [h('h2', { text:'Create an image' })]),
      h('div', { className:'hub-panel-body' }, [h('label', { className:'vc-field' }, [h('span', { text:'Prompt' }), prompt]), h('label', { className:'vc-field' }, [h('span', { text:'Negative prompt' }), negative]), h('div', { className:'ai-form-actions' }, [h('label', { className:'ai-size-field' }, [h('span', { text:'Size' }), size]), h('button', { type:'submit', className:'btn primary', text:'Add to queue' })]), h('p', { className:'muted ai-note', text:'Requests are processed by available contributor computers. Completed images are saved to your picture library.' })])
    ]);
    var worker = h('section', { className:'hub-panel vc-panel ai-worker-panel' }, [h('div', { className:'hub-panel-head' }, [h('h2', { text:'Contribute a computer' }), h('span', { className:'muted', text:'Optional' })]), h('div', { className:'hub-panel-body' }, [h('p', { className:'muted', text:'Connect a Windows PC running InvokeAI 6 with an installed SDXL model. The lightweight worker runs separately from Vaultcast.' }), button('Pair a computer', function(){ call('/ai/pair', { method:'POST', body:{} }).then(function(j){ var code = h('code', { className:'ai-pair-code', text:j.pair_code }); var box = h('div', { className:'ai-pair-result' }, [h('p', { text:'Enter this one-time code in Optional/AIWorker/start-worker.cmd within 10 minutes.' }), code, h('p', { className:'muted', text:'The worker connects to InvokeAI at http://127.0.0.1:9090 and stores its credential in your Windows AppData folder.' })]); var old = host.querySelector('.ai-pair-result'); if (old) old.replaceWith(box); else host.querySelector('.ai-worker-panel .hub-panel-body').appendChild(box); }).catch(showError); }, 'ghost')])]);
    var summaryHost = h('div', { className:'ai-summary-host' }), jobsHost = h('div', { className:'ai-jobs-host' });
    host.append(h('header', { className:'vc-hero ai-hero' }, [h('div', { className:'vc-hero-copy' }, [h('span', { className:'eyebrow', text:'AI Studio' }), h('h1', { text:'Images' }), h('p', { className:'muted', text:'Create images with the Vaultcast community’s available computers.' })])]), summaryHost, form, worker, jobsHost);
    refresh();
    document.addEventListener('visibilitychange', onVisibility);
  }
  function onVisibility(){ if (document.hidden) clearTimeout(timer); else refresh(); }
  function stop(){ clearTimeout(timer); document.removeEventListener('visibilitychange', onVisibility); host = null; }
  root.VaultcastAI = { init:function(app){ A=app; h=app.h; api=app.api; }, render:render, stop:stop };
})(this);
