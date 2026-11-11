(function () {
  'use strict';

  window.VaultcastLinkedServers = function (app) {
    var h = app.h;
    var settingsPath = '/linked-servers/settings';
    var activePlayer;

    function request(path, options) {
      return app.api(path, options).then(function (reply) {
        if (!reply.ok) throw new Error(reply.error || 'The linked server request failed.');
        return reply;
      });
    }

    function button(label, click, style) {
      return h('button', { type:'button', className:'btn' + (style ? ' ' + style : ''), text:label, onClick:click });
    }

    function status(text) {
      return h('p', { className:'muted', role:'status', text:text || '' });
    }

    function title(item) {
      var episode = item.season == null || item.episode == null ? '' : 'S' + item.season + ' E' + item.episode;
      return [item.show, episode, item.title, !item.show && item.year ? String(item.year) : ''].filter(Boolean).join(' · ');
    }

    function linkPath(link, action) {
      return '/linked-servers/' + encodeURIComponent(link.id) + '/' + action;
    }

    function player(label) {
      return h('video', { controls:true, playsinline:true, preload:'metadata', crossorigin:'anonymous',
        'aria-label':label, style:'display:block;width:100%;max-height:65vh;background:#000' });
    }

    function play(link, item) {
      if (activePlayer) activePlayer();
      app.stopPlayback();
      app.modal(title(item), function (body, close) {
        activePlayer = close;
        var message = status('Opening video from ' + link.label + '…');
        body.appendChild(message);
        var disposed = false;
        var cleanup = function () {};
        request(linkPath(link, 'play'), { method:'POST', body:{ item:item.id } }).then(function (reply) {
          if (disposed) return;
          var video = player(title(item));
          body.replaceChildren(video, message);
          message.textContent = 'Playing from ' + link.label;
          var ready = false;
          var savedPosition = Number(reply.position) || 0;
          var lastSaved = savedPosition;
          var pending = Promise.resolve();

          function save(force) {
            if (!ready || video.seeking || !Number.isFinite(video.currentTime)) return;
            var position = video.ended ? 0 : video.currentTime;
            if (position === lastSaved || (!force && Math.abs(position - lastSaved) < 15)) return;
            lastSaved = position;
            // Keep writes in order when pause, end and close fire close together.
            pending = pending.then(function () {
              return request(linkPath(link, 'progress'), {
                method:'POST', keepalive:true,
                body:{ item:item.id, position:position, until:reply.until, key:reply.progress_key }
              });
            }).catch(function () {
              if (lastSaved === position) lastSaved = -1;
            });
          }

          function onPageHide() { save(true); }
          video.addEventListener('loadedmetadata', function () {
            if (savedPosition > 0 && savedPosition < video.duration - 1) video.currentTime = savedPosition;
            ready = true;
          }, { once:true });
          video.addEventListener('timeupdate', function () { if (!video.paused) save(false); });
          video.addEventListener('pause', function () { save(true); });
          video.addEventListener('ended', function () { save(true); });
          video.addEventListener('error', function () {
            message.textContent = 'Could not play this video. The share may be unavailable, or the browser may need a DirectPlay MP4 copy.';
          });
          window.addEventListener('pagehide', onPageHide);
          cleanup = function () {
            window.removeEventListener('pagehide', onPageHide);
            save(true);
            ready = false;
            video.pause();
            video.removeAttribute('src');
            video.load();
          };
          video.src = reply.url;
          video.play().catch(function () {});
        }).catch(function (error) {
          if (!disposed) message.textContent = error.message;
        });
        return function () {
          disposed = true;
          activePlayer = null;
          cleanup();
        };
      });
    }

    function queueCopy(link, item, control) {
      control.disabled = true;
      request(linkPath(link, 'download'), { method:'POST', body:{ item:item.id } }).then(function () {
        control.replaceWith(button('See Saved copies', showCopies));
        app.toast('Download started on this server.');
      }).catch(function (error) {
        control.disabled = false;
        app.toast(error.message, { kind:'bad' });
      });
    }

    function catalogue(host, link, query) {
      var page = 1;
      function load(target) {
        host.replaceChildren(status('Loading ' + link.label + '…'));
        var path = linkPath(link, 'catalogue') + '?q=' + encodeURIComponent(query) + '&page=' + target;
        request(path).then(function (reply) {
          if (!host.isConnected) return;
          page = target;
          host.replaceChildren();
          host.appendChild(h('h3', { text:link.label }));
          if (!reply.items.length) host.appendChild(status(query ? 'No matching titles.' : 'No titles on this page.'));
          reply.items.forEach(function (item) {
            host.appendChild(h('div', { className:'row-actions' }, [
              h('span', { text:title(item) }),
              button('Play', function () { play(link, item); }, 'ghost'),
              reply.downloads && app.isAdmin() ? button('Save on this server', function (event) {
                queueCopy(link, item, event.currentTarget);
              }) : null
            ]));
          });
          if (page > 1 || reply.more) {
            host.appendChild(h('div', { className:'row-actions' }, [
              page > 1 ? button('Previous', function () { load(page - 1); }) : null,
              h('span', { className:'muted', text:'Page ' + page }),
              reply.more ? button('Next', function () { load(page + 1); }) : null
            ]));
          }
        }).catch(function (error) {
          if (!host.isConnected) return;
          host.replaceChildren(status(link.label + ' · ' + error.message), button('Retry', function () { load(target); }));
        });
      }
      load(1);
    }

    function browse(body) {
      var message = status('Loading linked libraries…');
      var actions = h('div', { className:'row-actions' }, [button('Saved copies', showCopies)]);
      if (app.isAdmin()) actions.appendChild(button('Set up linked servers', app.openSettings));
      body.replaceChildren(actions, message);
      request('/linked-servers').then(function (reply) {
        if (!body.isConnected) return;
        if (!reply.links.length) {
          message.textContent = 'There are no linked libraries yet.';
          return;
        }
        var source = h('select', {}, reply.links.map(function (link) {
          return h('option', { value:link.id, text:link.label });
        }));
        var query = h('input', { type:'search', maxlength:120, placeholder:'Film or show title' });
        var results = h('div');
        function load() {
          var link = reply.links.find(function (entry) { return entry.id === source.value; });
          var rows = h('div', { className:'stack' });
          results.replaceChildren(rows);
          if (link) catalogue(rows, link, query.value.trim());
        }
        var form = h('form', { className:'stack', onSubmit:function (event) { event.preventDefault(); load(); } }, [
          h('label', {}, ['Library', source]),
          h('label', {}, ['Search this library', query]),
          h('button', { className:'btn', type:'submit', text:'Search' })
        ]);
        source.addEventListener('change', load);
        body.replaceChildren(actions, form, results);
        load();
      }).catch(function (error) { message.textContent = error.message; });
    }

    function savedPath(copy, action) {
      return '/linked-servers/copies/' + encodeURIComponent(copy.id) + '/' + action;
    }

    function playSaved(copy) {
      if (activePlayer) activePlayer();
      app.stopPlayback();
      app.modal(copy.title, function (body, close) {
        activePlayer = close;
        var video = player(copy.title);
        var message = status('Playing the copy on this server.');
        body.append(video, message);
        video.addEventListener('error', function () { message.textContent = 'This browser could not play the saved format. Use Download file and open it in your media player.'; });
        video.src = app.base + 'api' + savedPath(copy, 'file');
        video.play().catch(function () {});
        return function () {
          activePlayer = null;
          video.pause();
          video.removeAttribute('src');
          video.load();
        };
      });
    }

    function showCopies() {
      app.modal('Saved copies', function (body, close) {
        var message = status('Loading saved copies…');
        var rows = h('div', { className:'stack' });
        body.append(message, rows);
        var timer;
        var disposed = false;
        var revision = 0;
        var last = '';

        function manage(copy, action, control) {
          if (action === 'remove' && !window.confirm('Delete this copy from this server?')) return;
          clearTimeout(timer);
          revision++;
          control.disabled = true;
          request(savedPath(copy, action), { method:'POST', body:{} }).then(refresh).catch(function (error) {
            message.textContent = error.message;
          }).then(function () { control.disabled = false; });
        }

        function refresh() {
          var serial = ++revision;
          return request('/linked-servers/copies').then(function (reply) {
            if (disposed || serial !== revision) return;
            message.textContent = reply.copies.length ? 'Completed copies can play even when the sharing server is offline.' : 'No copies are saved on this server.';
            var snapshot = JSON.stringify(reply.copies);
            if (snapshot !== last) {
              last = snapshot;
              rows.replaceChildren();
              reply.copies.forEach(function (copy) {
                var ready = copy.state === 'ready';
                var running = copy.state === 'queued' || copy.state === 'downloading';
                var size = Math.round(Number(copy.bytes) / (1024 * 1024)).toLocaleString() + ' MB';
                var label = ready ? 'Saved · ' + size : (running
                  ? (copy.state === 'queued' ? 'Queued' : Math.floor(100 * copy.received / copy.bytes) + '% of ' + size)
                  : copy.error || 'Download interrupted.');
                rows.appendChild(h('article', { className:'panel stack' }, [
                  h('strong', { text:copy.title }),
                  status(label),
                  h('div', { className:'row-actions' }, [
                    ready ? button('Play local copy', function () { close(); playSaved(copy); }, 'primary') : null,
                    ready ? h('a', { className:'btn', text:'Download file', href:app.base + 'api' + savedPath(copy, 'file') + '?download=1' }) : null,
                    !ready && !running && app.isAdmin() ? button('Retry', function (e) { manage(copy, 'retry', e.currentTarget); }) : null,
                    !running && app.isAdmin() ? button('Remove', function (e) { manage(copy, 'remove', e.currentTarget); }, 'danger') : null
                  ])
                ]));
              });
            }
            if (reply.copies.some(function (copy) { return copy.state === 'queued' || copy.state === 'downloading'; })) {
              timer = setTimeout(refresh, 3000);
            }
          }).catch(function (error) {
            if (!disposed && serial === revision) message.textContent = error.message;
          });
        }

        refresh();
        return function () { disposed = true; clearTimeout(timer); };
      });
    }

    function search(host, query) {
      request('/linked-servers').then(function (reply) {
        if (!host.isConnected || !reply.links.length) return;
        host.appendChild(h('div', { className:'row-head' }, [h('h2', { text:'Linked libraries' })]));
        reply.links.forEach(function (link) {
          var source = h('div', { className:'panel stack' });
          host.appendChild(source);
          catalogue(source, link, query);
        });
      }).catch(function () { /* Linked libraries may be disabled for this profile. */ });
    }

    function settings(host) {
      var message = status('Loading linked servers…');
      var content = h('div', { className:'stack' });
      var inviteResult = h('div');
      host.replaceChildren(message, inviteResult, content);

      function submit(body, control, done) {
        control.disabled = true;
        message.textContent = body.action === 'connect' ? 'Contacting the sharing server…' : 'Saving…';
        request(settingsPath, { method:'POST', body:body }).then(function (reply) {
          if (!host.isConnected) return;
          done(reply);
          return refresh();
        }).catch(function (error) {
          message.textContent = error.message;
        }).then(function () { control.disabled = false; });
      }

      function refresh() {
        return request(settingsPath).then(function (reply) {
          if (!host.isConnected) return;
          message.textContent = '';
          var publicUrl = reply.public_url || new URL(app.base, location.origin).href;
          var section = h('select', { required:true }, reply.sections.map(function (library) {
            return h('option', { value:library.id, text:library.label });
          }));
          var shareLabel = h('input', { type:'text', maxlength:120, placeholder:'Defaults to library name' });
          var downloads = h('input', { type:'checkbox' });
          var inviteButton = h('button', { type:'submit', className:'btn primary', text:'Create invite', disabled:!reply.sections.length });
          var shareForm = h('form', { className:'stack settings-card', onSubmit:function (event) {
            event.preventDefault();
            submit({ action:'invite', section:section.value, label:shareLabel.value, downloads:downloads.checked }, inviteButton, function (result) {
              var code = h('input', { readonly:true, type:'text', value:result.invite });
              inviteResult.replaceChildren(h('div', { className:'settings-card stack' }, [
                h('h3', { text:'Your invite' }),
                h('p', { text:'Send the sharing server address and this code privately. The code works once and expires after 24 hours.' }),
                h('label', {}, ['Sharing server address', h('input', { readonly:true, type:'text', value:publicUrl })]),
                h('p', { className:'muted', text:'If both servers are on your home network, use this server’s private IPv4 address and port instead.' }),
                h('label', {}, ['Invite code', code]),
                button('Copy address and code', function () {
                  app.copy(publicUrl + '\n' + result.invite).then(function () {
                    message.textContent = 'Address and invite code copied.';
                  }).catch(function () { code.focus(); code.select(); });
                })
              ]));
            });
          } }, [
            h('h3', { text:'Share a library' }),
            h('p', { text:'An invite grants read-only browsing and playback for one film or TV library. Create a separate invite for each server.' }),
            h('label', {}, ['Library to share', section]),
            h('label', {}, ['Share name (optional)', shareLabel]),
            h('label', { className:'check-label' }, [downloads, ' Allow the receiving server to save its own copies']),
            inviteButton
          ]);

          var url = h('input', { type:'url', required:true, maxlength:255, placeholder:'https://vaultcast.example.com' });
          var code = h('input', { type:'password', required:true, maxlength:97, autocomplete:'off' });
          var lan = h('input', { type:'checkbox' });
          var linkLabel = h('input', { type:'text', maxlength:120, placeholder:'Defaults to the share name' });
          var connectButton = h('button', { type:'submit', className:'btn primary', text:'Link library' });
          function pasteInvite(event) {
            var fields = (event.clipboardData ? event.clipboardData.getData('text') : '').trim().split(/\r?\n/);
            if (fields.length !== 2 || !/^https?:\/\//i.test(fields[0]) || !/^[a-f0-9]{32}\.[a-f0-9]{64}$/i.test(fields[1])) return;
            event.preventDefault();
            url.value = fields[0];
            code.value = fields[1];
          }
          url.addEventListener('paste', pasteInvite);
          code.addEventListener('paste', pasteInvite);
          var connectForm = h('form', { className:'stack settings-card', onSubmit:function (event) {
            event.preventDefault();
            submit({ action:'connect', url:url.value, invite:code.value, label:linkLabel.value, lan:lan.checked }, connectButton, function () {});
          } }, [
            h('h3', { text:'Link a shared library' }),
            h('p', { text:'For a remote server, use its public HTTPS address. For a secondary box on your own network, use its private IPv4 address and port, such as http://192.168.1.25:8080.' }),
            h('p', { text:'Signed-in users with Library access can browse and play linked titles. Profiles with parental controls cannot access linked libraries.' }),
            h('label', {}, ['Sharing server address', url]),
            h('label', {}, ['Invite code', code]),
            h('label', {}, ['Display name (optional)', linkLabel]),
            h('label', { className:'check-label' }, [lan, ' Use the home network and relay playback through this server']),
            h('p', { className:'muted', text:'Home network links use HTTP between the two boxes. Use this option on a trusted network. Outside viewers only need this server’s public address.' }),
            connectButton
          ]);
          var shares = h('section', { className:'settings-card stack' }, [h('h3', { text:'Libraries shared from this server' })]);
          var links = h('section', { className:'settings-card stack' }, [h('h3', { text:'Libraries linked to this server' })]);
          function addEntry(list, entry, detail, action, verb, warning) {
            var control = button(verb, function () {
              if (!window.confirm(warning)) return;
              submit({ action:action, id:entry.id }, control, function () {
                if (action === 'outgoing') inviteResult.replaceChildren();
              });
            }, 'danger');
            list.appendChild(h('div', { className:'row-actions' }, [
              h('strong', { text:entry.label }), h('span', { className:'muted', text:detail }), control
            ]));
          }
          reply.outgoing.forEach(function (entry) {
            var detail = Number(entry.claimed) ? 'Linked' : (entry.invite_until * 1000 > Date.now() ? 'Waiting for invite' : 'Invite expired');
            if (Number(entry.allow_downloads)) detail += ' · Copies allowed';
            addEntry(shares, entry, detail, 'outgoing', 'Revoke', 'Revoke access to this library share?');
          });
          reply.incoming.forEach(function (entry) {
            var detail = entry.base_url + (Number(entry.connected) ? '' : ' · Connection incomplete. Retry with the same invite.');
            addEntry(links, entry, detail, 'incoming', 'Remove', 'Remove this linked library and its saved playback progress?');
          });
          if (!reply.outgoing.length) shares.appendChild(status('You have not shared any libraries.'));
          if (!reply.incoming.length) links.appendChild(status('No libraries are linked.'));
          var folder = h('input', { type:'text', value:reply.download_storage || '', placeholder:'Vaultcast’s private data folder' });
          var storageButton = h('button', { className:'btn', type:'submit', text:'Save storage folder' });
          var storageForm = h('form', { className:'stack settings-card', onSubmit:function (event) {
            event.preventDefault();
            submit({ action:'storage', path:folder.value }, storageButton, function () { app.toast('Storage folder saved.'); });
          } }, [
            h('h3', { text:'Saved copy storage' }),
            h('p', { text:'Choose an existing folder on this server. Use a different drive from the source library if you want the copies to survive a source drive failure.' }),
            h('p', { className:'muted', text:'A new folder applies to future downloads. Existing copies stay where they were saved.' }),
            h('label', {}, ['Storage folder (optional)', folder]), storageButton
          ]);
          content.replaceChildren(shareForm, connectForm, storageForm, shares, links);
        });
      }

      refresh().catch(function (error) { message.textContent = error.message; });
    }

    return { browse:browse, search:search, settings:settings, close:function () { if (activePlayer) activePlayer(); } };
  };
})();
