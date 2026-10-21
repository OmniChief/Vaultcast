(function(){
  'use strict';
  var activeDance=null;
  var titles={dark:'Neon circuit',light:'Little memory garden','retro-scifi':'Terminal codebreaker',steampunk:'Boiler room','vintage-tv':'Tune the transmission',anime:'Starlight constellation','manly-man':'Iron mountain'};
  function el(tag,attrs,kids){var n=document.createElement(tag);Object.keys(attrs||{}).forEach(function(k){if(k==='text')n.textContent=attrs[k];else if(k==='className')n.className=attrs[k];else n.setAttribute(k,attrs[k]);});(kids||[]).forEach(function(c){n.appendChild(c);});return n;}
  function button(text,fn,cls){var b=el('button',{type:'button',className:cls||'theme-game-cell',text:text});b.onclick=fn;return b;}
  function shuffle(a){for(var i=a.length-1;i>0;i--){var j=Math.floor(Math.random()*(i+1)),v=a[i];a[i]=a[j];a[j]=v;}return a;}
  function open(theme){
    if(!window.VaultcastHub)return;
    window.VaultcastHub.modal(titles[theme],function(body){
      var status=el('p',{className:'theme-game-status',role:'status','aria-live':'polite'}),board=el('div',{className:'theme-game-board'}),help=el('p',{className:'muted'}),moves=0,finished=false;
      body.appendChild(help);body.appendChild(status);body.appendChild(board);
      var reset=button('New round',start,'btn');body.appendChild(reset);
      function win(text){finished=true;status.textContent=text;reset.focus();}
      function start(){moves=0;finished=false;board.replaceChildren();board.className='theme-game-board';
        if(theme==='dark'){
          help.textContent='Light every node. Each switch flips itself and its neighbours. Every circuit can be solved.';
          var lights=Array(9).fill(true),cells=[];
          function flip(i){[i,i%3?i-1:-1,i%3<2?i+1:-1,i-3,i+3].forEach(function(n){if(n>=0&&n<9)lights[n]=!lights[n];});}
          shuffle([0,1,2,3,4,5,6,7,8]).slice(0,5).forEach(flip);
          if(lights.every(Boolean))flip(4);
          function paint(){cells.forEach(function(b,i){b.classList.toggle('on',lights[i]);b.setAttribute('aria-pressed',String(lights[i]));b.setAttribute('aria-label','Node '+(i+1)+(lights[i]?' lit':' dark'));});status.textContent=moves+' switches · '+lights.filter(Boolean).length+'/9 online';if(lights.every(Boolean))win('Circuit online! '+moves+' switches.');}
          for(var i=0;i<9;i++)(function(i){var b=button('◈',function(){if(finished)return;flip(i);moves++;paint();});cells.push(b);board.appendChild(b);})(i);paint();
        }else if(theme==='light'){
          help.textContent='Find the six matching pairs. Turn two cards, then tap a card to continue. No timer.';board.classList.add('memory');
          var deck=shuffle(['✿','☀','❀','☘','☁','✦','✿','☀','❀','☘','☁','✦']),opened=[],found=[],cells=[];
          deck.forEach(function(symbol,i){var b=button('?',function(){if(finished||found.indexOf(i)>=0||opened.indexOf(i)>=0)return;
            if(opened.length===2){opened.forEach(function(n){cells[n].textContent='?';cells[n].classList.remove('on');cells[n].setAttribute('aria-label','Hidden card '+(n+1));});opened=[];}
            opened.push(i);b.textContent=symbol;b.classList.add('on');b.setAttribute('aria-label','Card '+(i+1)+' '+symbol);
            if(opened.length===2){moves++;if(deck[opened[0]]===deck[opened[1]]){found=found.concat(opened);opened.forEach(function(n){cells[n].classList.add('matched');cells[n].setAttribute('aria-disabled','true');});opened=[];}}
            status.textContent=(found.length/2)+'/6 pairs · '+moves+' turns';if(found.length===12)win('Garden complete! '+moves+' turns.');
          });b.setAttribute('aria-label','Hidden card '+(i+1));cells.push(b);board.appendChild(b);});status.textContent='Find your first pair.';
        }else if(theme==='retro-scifi'){
          help.textContent='Crack a three-digit code (digits 1–6; repeats allowed). Feedback gives exact positions and correct digits in the wrong position.';board.classList.add('terminal');
          var code=[1,2,3].map(function(){return 1+Math.floor(Math.random()*6);}),inputs=[],form=el('form',{className:'theme-code-input'}),history=el('ol',{className:'theme-code-history'});
          for(var d=0;d<3;d++){var select=el('select',{'aria-label':'Code digit '+(d+1)});for(var n=1;n<=6;n++)select.appendChild(el('option',{value:String(n),text:String(n)}));inputs.push(select);form.appendChild(select);}
          var submit=el('button',{type:'submit',className:'btn',text:'Decode'});form.appendChild(submit);board.appendChild(form);board.appendChild(history);
          form.onsubmit=function(e){e.preventDefault();if(finished)return;var guess=inputs.map(function(n){return +n.value;}),exact=0,remaining=[],others=[];guess.forEach(function(n,i){if(n===code[i])exact++;else{remaining.push(code[i]);others.push(n);}});var misplaced=0;others.forEach(function(n){var at=remaining.indexOf(n);if(at>=0){misplaced++;remaining.splice(at,1);}});moves++;history.prepend(el('li',{text:guess.join(' ')+' → '+exact+' exact · '+misplaced+' misplaced'}));while(history.children.length>12)history.lastChild.remove();status.textContent='Attempt '+moves+': '+exact+' exact, '+misplaced+' misplaced.';if(exact===3){submit.disabled=true;win('ACCESS GRANTED · '+moves+' attempts.');}};status.textContent='TERMINAL READY';
        }else if(theme==='steampunk'){
          help.textContent='Balance the linked valves until all three gauges read 5. A valve also turns the next gauge. Values wrap from 9 to 0.';board.classList.add('gauges');
          var values=[5,5,5],dial=[];for(var a=0;a<7;a++){var at=Math.floor(Math.random()*3);values[at]=(values[at]+1)%10;values[(at+1)%3]=(values[(at+1)%3]+1)%10;}
          if(values.every(function(v){return v===5;}))values=[6,6,5];
          function paint(){dial.forEach(function(n,i){n.textContent=String(values[i]);});status.textContent='Target pressure 5 · 5 · 5 — '+moves+' valve turns';if(values.every(function(v){return v===5;}))win('Steam engine balanced! '+moves+' turns.');}
          for(var i=0;i<3;i++)(function(i){var g=el('div',{className:'theme-gauge'}),n=el('strong');dial.push(n);g.appendChild(el('small',{text:'Gauge '+(i+1)}));g.appendChild(n);[-1,1].forEach(function(delta){g.appendChild(button(delta<0?'↶':'↷',function(){if(finished)return;values[i]=(values[i]+delta+10)%10;values[(i+1)%3]=(values[(i+1)%3]+delta+10)%10;moves++;paint();},'theme-game-cell'));});board.appendChild(g);})(i);paint();
        }else if(theme==='vintage-tv'){
          help.textContent='Find the hidden station frequency and fine-tune the aerial. The signal meter tells you how close you are.';board.classList.add('tuner');
          var station=1+Math.floor(Math.random()*20),angle=Math.floor(Math.random()*7)-3,frequency=el('input',{type:'range',min:'1',max:'20',value:'10','aria-label':'Channel frequency'}),aerial=el('input',{type:'range',min:'-3',max:'3',value:'0','aria-label':'Aerial alignment'}),meter=el('progress',{max:'100',value:'0','aria-label':'Signal strength'}),label=el('strong');
          if(station===10&&angle===0)station=11;board.appendChild(label);board.appendChild(meter);board.appendChild(el('label',{text:'Frequency'},[frequency]));board.appendChild(el('label',{text:'Aerial alignment'},[aerial]));
          function tune(){if(finished)return;var signal=Math.max(0,100-Math.abs(+frequency.value-station)*6-Math.abs(+aerial.value-angle)*9);meter.value=signal;label.textContent='CH '+frequency.value+' · Aerial '+aerial.value;status.textContent='Signal '+signal+'%'+(signal<40?' · static':signal<80?' · getting warmer':' · nearly there');if(signal===100){frequency.disabled=aerial.disabled=true;win('Broadcast restored. A perfectly tuned picture!');}}frequency.oninput=aerial.oninput=tune;tune();
        }else if(theme==='anime'){
          help.textContent='Connect the stars from 1 to 9 to reveal your constellation. Take your time.';var order=shuffle([1,2,3,4,5,6,7,8,9]),next=1,svg=svgEl('svg',{viewBox:'0 0 300 300','aria-hidden':'true'}),line=svgEl('polyline',{fill:'none',stroke:'currentColor','stroke-width':'2'}),points=[];svg.appendChild(line);board.classList.add('constellation');board.appendChild(svg);
          order.forEach(function(n,i){var b=button('✦ '+n,function(){if(finished)return;if(n!==next){status.textContent='Look for star '+next+'.';return;}points.push((i%3*100+50)+','+(Math.floor(i/3)*100+50));line.setAttribute('points',points.join(' '));b.classList.add('on');b.setAttribute('aria-pressed','true');next++;status.textContent=(next-1)+'/9 stars connected';if(next===10)win('Your constellation is shining!');});b.setAttribute('aria-label','Connect star '+n);board.appendChild(b);});status.textContent='Start with star 1.';
        }else{
          help.textContent='Reach the summit: exactly 100 power with five lifts. Build a combination using five different weights. No speed tapping.';board.classList.add('strength');var power=0,lifts=0,bar=el('progress',{max:'100',value:'0','aria-label':'Mountain power'}),readout=el('strong'),weights=shuffle([8,12,16,24,28,32,36,40]),history=el('p',{className:'muted'}),used=[];board.appendChild(readout);board.appendChild(bar);board.appendChild(history);var rack=el('div',{className:'theme-weight-rack'});board.appendChild(rack);
          function paint(){bar.value=power;readout.textContent=power+' / 100 POWER';history.textContent=used.length?used.join(' + '):'The mountain awaits.';status.textContent=lifts+'/5 lifts';if(lifts===5){rack.querySelectorAll('button').forEach(function(b){b.disabled=true;});if(power===100)win('SUMMIT CONQUERED. Legendary strength.');else status.textContent='Finished at '+power+'. Try another combination to reach exactly 100.';}}
          weights.forEach(function(weight){var b=button('▰ '+weight,function(){if(finished||lifts>=5)return;b.disabled=true;power+=weight;lifts++;used.push(weight);paint();});rack.appendChild(b);});paint();
        }
      }
      start();
    });
  }
  function svgEl(tag,attrs){var n=document.createElementNS('http://www.w3.org/2000/svg',tag);Object.keys(attrs||{}).forEach(function(k){n.setAttribute(k,attrs[k]);});return n;}
  // Two-bone inverse kinematics keeps limbs the same length throughout every pose.
  function joint(a,b,l1,l2,side){var dx=b[0]-a[0],dy=b[1]-a[1],d=Math.max(.001,Math.sqrt(dx*dx+dy*dy)),reach=Math.min(d,l1+l2-.001),along=(l1*l1-l2*l2+reach*reach)/(2*reach),height=Math.sqrt(Math.max(0,l1*l1-along*along)),ux=dx/d,uy=dy/d;return[a[0]+ux*along-uy*height*side,a[1]+uy*along+ux*height*side];}
  function path(points){return points.map(function(p,i){return(i?'L':'M')+p[0].toFixed(2)+' '+p[1].toFixed(2);}).join(' ');}
  function dancer(host){
    var b=button('',dance,'theme-dancer'),svg=svgEl('svg',{viewBox:'0 0 200 320','aria-hidden':'true'}),parts={},routine=0;
    var names=['Pole walk & pivot','Step-around turn','Fan-leg sweep','Arabesque line','Side lean & recovery'];
    b.title='Dance · five routines';b.setAttribute('data-routine','Tap to dance · 5 routines');b.setAttribute('aria-label','Start dancer: '+names[0]);
    function part(name,attrs,tag){var n=svgEl(tag||'path',attrs);parts[name]=n;svg.appendChild(n);return n;}
    part('pole',{d:'M100 6 L100 307',fill:'none',stroke:'#e7c6c1','stroke-width':'3.5'});
    part('shadow',{cx:'126',cy:'300',rx:'38',ry:'5',fill:'#0005'},'ellipse');
    part('backLeg',{fill:'none',stroke:'#c99491','stroke-width':'10','stroke-linecap':'round','stroke-linejoin':'round'});
    part('backArm',{fill:'none',stroke:'#c99491','stroke-width':'8','stroke-linecap':'round','stroke-linejoin':'round'});
    part('neck',{fill:'none',stroke:'#efbdb4','stroke-width':'10','stroke-linecap':'round'});
    part('torso',{fill:'#773151',stroke:'#efb6c0','stroke-width':'1.5'});
    part('frontLeg',{fill:'none',stroke:'#efbdb4','stroke-width':'11','stroke-linecap':'round','stroke-linejoin':'round'});
    part('skirt',{fill:'#50213f',stroke:'#e2a1b8','stroke-width':'1'});
    part('frontArm',{fill:'none',stroke:'#f1c2b7','stroke-width':'8','stroke-linecap':'round','stroke-linejoin':'round'});
    part('backShoe',{fill:'none',stroke:'#32172e','stroke-width':'8','stroke-linecap':'round'});
    part('frontShoe',{fill:'none',stroke:'#32172e','stroke-width':'8','stroke-linecap':'round'});
    part('hair',{fill:'#30182e'});part('head',{r:'11',fill:'#efbdb4'},'circle');part('fringe',{fill:'#30182e'});
    b.appendChild(svg);host.appendChild(b);host.classList.add('has-theme-dancer');
    // hip x/y, torso angle, left hand x/y, right hand x/y, left ankle x/y, right ankle x/y.
    var rest=[132,177,-6,100,87,157,161,122,290,145,284];
    var routines=[
      [rest,[138,174,-10,100,87,168,134,132,291,157,279],[140,170,-3,100,87,173,110,144,288,126,271],[126,175,8,100,87,153,146,126,285,145,291],rest],
      [rest,[142,175,-12,100,87,170,133,145,291,125,279],[96,177,7,100,87,78,121,87,291,111,282],[80,180,12,100,87,59,143,67,291,101,282],rest],
      [rest,[133,173,-8,100,87,163,130,123,291,170,255],[128,168,-13,100,87,159,112,124,291,190,210],[134,172,-9,100,87,165,132,128,291,178,250],rest],
      [rest,[136,172,-17,100,87,174,131,130,291,173,248],[143,171,-28,100,87,172,104,140,290,194,224],[138,173,-18,100,87,170,126,132,291,174,252],rest],
      [rest,[150,181,-25,100,87,173,144,140,291,159,284],[158,187,-34,100,87,180,140,147,291,164,287],[146,179,-18,100,87,174,126,135,291,158,281],rest]
    ];
    function pose(v){var hip=[v[0],v[1]],a=v[2]*Math.PI/180,shoulder=[hip[0]+Math.sin(a)*74,hip[1]-Math.cos(a)*74],head=[shoulder[0]+Math.sin(a)*26,shoulder[1]-Math.cos(a)*26],hands=[[v[3],v[4]],[v[5],v[6]]],feet=[[v[7],v[8]],[v[9],v[10]]];
      function limb(name,start,end,l1,l2,side){var dx=end[0]-start[0],dy=end[1]-start[1],dist=Math.sqrt(dx*dx+dy*dy),max=l1+l2-1;if(dist>max){end=[start[0]+dx*max/dist,start[1]+dy*max/dist];}parts[name].setAttribute('d',path([start,joint(start,end,l1,l2,side),end]));return end;}
      var foot1=limb('backLeg',[hip[0]-7,hip[1]],feet[0],59,58,1),foot2=limb('frontLeg',[hip[0]+7,hip[1]],feet[1],59,58,-1);
      limb('backArm',[shoulder[0]-7,shoulder[1]],hands[0],43,43,1);limb('frontArm',[shoulder[0]+7,shoulder[1]],hands[1],43,43,-1);
      parts.neck.setAttribute('d',path([shoulder,head]));parts.torso.setAttribute('d','M'+(shoulder[0]-12)+' '+shoulder[1]+' Q'+(hip[0]-6)+' '+(hip[1]-26)+' '+(hip[0]-15)+' '+hip[1]+' Q'+hip[0]+' '+(hip[1]+12)+' '+(hip[0]+15)+' '+hip[1]+' Q'+(hip[0]+5)+' '+(hip[1]-25)+' '+(shoulder[0]+12)+' '+shoulder[1]+' Z');
      parts.skirt.setAttribute('d','M'+(hip[0]-15)+' '+(hip[1]-6)+' Q'+hip[0]+' '+(hip[1]+1)+' '+(hip[0]+15)+' '+(hip[1]-6)+' L'+(hip[0]+22)+' '+(hip[1]+18)+' Q'+hip[0]+' '+(hip[1]+25)+' '+(hip[0]-22)+' '+(hip[1]+18)+' Z');
      [foot1,foot2].forEach(function(f,i){parts[i?'frontShoe':'backShoe'].setAttribute('d',path([[f[0],f[1]-5],[f[0],f[1]+2],[f[0]+11,f[1]+4]]));});
      parts.head.setAttribute('cx',head[0]);parts.head.setAttribute('cy',head[1]);var sway=Math.sin(a)*12;parts.hair.setAttribute('d','M'+(head[0]-13)+' '+head[1]+' Q'+(head[0]-18)+' '+(head[1]-24)+' '+(head[0]+10)+' '+(head[1]-14)+' Q'+(head[0]+25+sway)+' '+(head[1]+5)+' '+(head[0]+14+sway)+' '+(head[1]+34)+' L'+(head[0]+4)+' '+(head[1]+16)+' Z');parts.fringe.setAttribute('d','M'+(head[0]-13)+' '+head[1]+' Q'+(head[0]-16)+' '+(head[1]-20)+' '+(head[0]+9)+' '+(head[1]-11)+' L'+(head[0]+11)+' '+(head[1]-1)+' Q'+head[0]+' '+(head[1]-12)+' '+(head[0]-13)+' '+head[1]);
    }
    function dance(){if(activeDance){activeDance();activeDance=null;}var chosen=routine++%5,frames=routines[chosen],raf=0,start=0,duration=12000;
      b.setAttribute('aria-label',names[chosen]+'. Click for the next dance.');b.title=names[chosen]+' · click for next routine';b.setAttribute('data-routine',names[chosen]+' · '+(chosen+1)+'/5');
      if(document.body.getAttribute('data-motion')==='reduced'){pose(frames[2]);b.title=names[chosen]+' · still pose (reduced motion)';return;}
      function stop(){cancelAnimationFrame(raf);pose(rest);b.classList.remove('dancing');if(activeDance===stop)activeDance=null;}
      activeDance=stop;b.classList.add('dancing');
      function tick(now){if(!b.isConnected||document.hidden||document.body.getAttribute('data-theme')!=='after-hours'||document.body.getAttribute('data-motion')==='reduced'){stop();return;}if(!start)start=now;var progress=(now-start)/duration;if(progress>=1){stop();return;}var pos=progress*(frames.length-1),index=Math.floor(pos),t=pos-index;t=t*t*(3-2*t);pose(frames[index].map(function(v,i){return v+(frames[index+1][i]-v)*t;}));raf=requestAnimationFrame(tick);}
      raf=requestAnimationFrame(tick);
    }
    pose(rest);
  }
  function enhance(root){
    if(activeDance){activeDance();activeDance=null;}if(!root)return;
    root.querySelectorAll('.theme-play-open').forEach(function(n){n.remove();});root.querySelectorAll('.theme-dancer').forEach(function(n){n.remove();});root.querySelectorAll('.interactive-dance-art').forEach(function(n){n.classList.remove('interactive-dance-art');n.setAttribute('aria-hidden','true');if(n.parentElement){n.parentElement.setAttribute('aria-hidden','true');n.parentElement.classList.remove('interactive-dance-stage');}});root.querySelectorAll('.has-theme-dancer').forEach(function(n){n.classList.remove('has-theme-dancer');});
    var theme=document.body.getAttribute('data-theme'),clock=root.querySelector('.hero-clock-face');
    if(theme==='after-hours'){
      root.querySelectorAll('.hero-art-clock').forEach(function(art){var host=art.children[1];if(!host)return;art.classList.add('interactive-dance-art');art.removeAttribute('aria-hidden');if(art.parentElement){art.parentElement.removeAttribute('aria-hidden');art.parentElement.classList.add('interactive-dance-stage');}dancer(host);});
    }else if(clock&&titles[theme])clock.appendChild(button('✦ '+titles[theme],function(){open(theme);},'btn ghost theme-play-open'));
  }
  document.addEventListener('visibilitychange',function(){if(document.hidden&&activeDance)activeDance();});
  window.VaultcastThemePlay={enhance:enhance};
})();
