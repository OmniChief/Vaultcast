/* Shared ES3 shims for separately downloaded JavaScript-only emulator engines. */
(function(){
  if(typeof window.globalThis==='undefined')window.globalThis=window;
  // Small ES3 shims for the downloaded engine; no browser capability gate.
  if(!window.Promise){
    var LegacyPromise=function(executor){
      var self=this;self.state=0;self.value=null;self.queue=[];
      function settle(state,value){if(self.state)return;if(state===1&&value===self){settle(2,new TypeError('Promise cycle'));return;}
        if(state===1&&value&&(typeof value==='object'||typeof value==='function')){var then;try{then=value.then;}catch(error){settle(2,error);return;}if(typeof then==='function'){var done=false;try{then.call(value,function(v){if(!done){done=true;settle(1,v);}},function(e){if(!done){done=true;settle(2,e);}});}catch(error2){if(!done){done=true;settle(2,error2);}}return;}}
        self.state=state;self.value=value;flush();
      }
      function flush(){if(!self.state)return;setTimeout(function(){var rows=self.queue;self.queue=[];for(var i=0;i<rows.length;i++)rows[i]();},0);}
      self.then=function(success,failure){return new LegacyPromise(function(resolve,reject){self.queue.push(function(){var fn=self.state===1?success:failure;if(typeof fn!=='function'){(self.state===1?resolve:reject)(self.value);return;}try{resolve(fn(self.value));}catch(error){reject(error);}});flush();});};
      self['catch']=function(fn){return self.then(null,fn);};
      var called=false;try{executor(function(value){if(!called){called=true;settle(1,value);}},function(error){if(!called){called=true;settle(2,error);}});}catch(error){if(!called){called=true;settle(2,error);}}
    };
    LegacyPromise.resolve=function(value){return new LegacyPromise(function(resolve){resolve(value);});};
    LegacyPromise.reject=function(error){return new LegacyPromise(function(resolve,reject){reject(error);});};
    window.Promise=LegacyPromise;
  }
  if(!Object.assign)Object.assign=function(target){if(target==null)throw new TypeError('Object.assign target');target=Object(target);for(var i=1;i<arguments.length;i++){var source=arguments[i];if(source!=null)for(var key in source)if(Object.prototype.hasOwnProperty.call(source,key))target[key]=source[key];}return target;};
  if(!Array.from)Array.from=function(source){
    var result=[],iterator=source&&source.next?source:null,method=source&&(typeof window.Symbol==='function'&&source[window.Symbol.iterator]||source['@@iterator']),step,i;
    if(!iterator&&typeof method==='function')iterator=method.call(source);
    if(iterator){while(!(step=iterator.next()).done)result.push(step.value);}else for(i=0;i<source.length;i++)result.push(source[i]);
    return result;
  };
  if(!Array.prototype.includes)Array.prototype.includes=function(value,from){var length=this.length>>>0,i=Number(from)||0;if(i<0)i=Math.max(0,length+i);for(;i<length;i++)if(this[i]===value||(this[i]!==this[i]&&value!==value))return true;return false;};
  if(!Array.prototype.fill)Array.prototype.fill=function(value,start,end){var length=this.length>>>0,i=Number(start)||0,until=end===undefined?length:Number(end)||0;i=i<0?Math.max(0,length+i):Math.min(length,i);until=until<0?Math.max(0,length+until):Math.min(length,until);for(;i<until;i++)this[i]=value;return this;};
  if(!String.prototype.startsWith)String.prototype.startsWith=function(value,start){start=Math.max(0,Number(start)||0);return this.substr(start,String(value).length)===String(value);};
  if(!String.prototype.endsWith)String.prototype.endsWith=function(value,end){value=String(value);end=end===undefined?this.length:Math.min(this.length,Math.max(0,Number(end)||0));return end>=value.length&&this.substring(end-value.length,end)===value;};
  if(!String.prototype.includes)String.prototype.includes=function(value,start){return this.indexOf(String(value),Math.max(0,Number(start)||0))>=0;};
  if(!Object.entries)Object.entries=function(source){var rows=[];for(var key in source)if(Object.prototype.hasOwnProperty.call(source,key))rows.push([key,source[key]]);return rows;};
  if(!Array.prototype.entries)Array.prototype.entries=function(){var list=this,index=0,iterator={next:function(){return index<list.length?{value:[index,list[index++]],done:false}:{value:undefined,done:true};}};iterator['@@iterator']=function(){return iterator;};if(typeof window.Symbol==='function'&&window.Symbol.iterator)iterator[window.Symbol.iterator]=iterator['@@iterator'];return iterator;};
  if(!String.prototype.codePointAt)String.prototype.codePointAt=function(index){var s=String(this),i=Number(index)||0;if(i<0||i>=s.length)return undefined;var first=s.charCodeAt(i),second=s.charCodeAt(i+1);return first>=55296&&first<=56319&&second>=56320&&second<=57343?65536+((first-55296)<<10)+(second-56320):first;};
  // The emulator uses this only for its callback table, retained for the page lifetime.
  if(!window.WeakMap){
    window.WeakMap=function(){this.keys=[];this.values=[];};
    window.WeakMap.prototype.get=function(key){for(var i=0;i<this.keys.length;i++)if(this.keys[i]===key)return this.values[i];return undefined;};
    window.WeakMap.prototype.has=function(key){for(var i=0;i<this.keys.length;i++)if(this.keys[i]===key)return true;return false;};
    window.WeakMap.prototype.set=function(key,value){for(var i=0;i<this.keys.length;i++)if(this.keys[i]===key){this.values[i]=value;return this;}this.keys.push(key);this.values.push(value);return this;};
    window.WeakMap.prototype['delete']=function(key){for(var i=0;i<this.keys.length;i++)if(this.keys[i]===key){this.keys.splice(i,1);this.values.splice(i,1);return true;}return false;};
  }
  // wasm2js memory operations use TypedArray methods absent on older TV engines.
  function relativeIndex(value,length,fallback){var n=value===undefined?fallback:Number(value)||0;n=n<0?Math.ceil(n):Math.floor(n);return n<0?Math.max(0,length+n):Math.min(length,n);}
  var typedArrays=['Int8Array','Uint8Array','Uint8ClampedArray','Int16Array','Uint16Array','Int32Array','Uint32Array','Float32Array','Float64Array'];
  for(var ta=0;ta<typedArrays.length;ta++){
    var constructor=window[typedArrays[ta]];if(!constructor)continue;
    if(!constructor.prototype.fill)constructor.prototype.fill=function(value,start,end){var i=relativeIndex(start,this.length,0),until=relativeIndex(end,this.length,this.length);for(;i<until;i++)this[i]=value;return this;};
    if(!constructor.prototype.copyWithin)constructor.prototype.copyWithin=function(target,start,end){var to=relativeIndex(target,this.length,0),from=relativeIndex(start,this.length,0),count=Math.min(relativeIndex(end,this.length,this.length)-from,this.length-to),direction=1;if(from<to&&to<from+count){direction=-1;from+=count-1;to+=count-1;}while(count>0){this[to]=this[from];to+=direction;from+=direction;count--;}return this;};
    if(!constructor.prototype.slice)constructor.prototype.slice=function(start,end){var from=relativeIndex(start,this.length,0),until=relativeIndex(end,this.length,this.length),count=Math.max(0,until-from),result=new this.constructor(count);for(var i=0;i<count;i++)result[i]=this[from+i];return result;};
  }
  if(!Math.imul)Math.imul=function(a,b){var ah=(a>>>16)&65535,al=a&65535,bh=(b>>>16)&65535,bl=b&65535;return (al*bl+((ah*bl+al*bh)<<16))|0;};
  if(!Math.fround)Math.fround=function(n){var value=new Float32Array(1);value[0]=n;return value[0];};
  if(!Math.clz32)Math.clz32=function(n){n=n>>>0;if(!n)return 32;var count=0;while(!(n&2147483648)){n=n<<1;count++;}return count;};
  if(!window.performance)window.performance={};if(!window.performance.now)window.performance.now=function(){return new Date().getTime();};
  if(!window.requestAnimationFrame)window.requestAnimationFrame=function(fn){return setTimeout(function(){fn(window.performance.now());},16);};
  if(!window.cancelAnimationFrame)window.cancelAnimationFrame=function(id){clearTimeout(id);};
  if(!window.TextEncoder){
    window.TextEncoder=function(){};
    window.TextEncoder.prototype.encode=function(value){
      value=String(value);var bytes=[],i,c,next;
      for(i=0;i<value.length;i++){
        c=value.charCodeAt(i);
        if(c>=55296&&c<=56319){next=value.charCodeAt(i+1);if(next>=56320&&next<=57343){c=65536+((c-55296)<<10)+(next-56320);i++;}else c=65533;}else if(c>=56320&&c<=57343)c=65533;
        if(c<128)bytes.push(c);else if(c<2048)bytes.push(192|(c>>6),128|(c&63));else if(c<65536)bytes.push(224|(c>>12),128|((c>>6)&63),128|(c&63));else bytes.push(240|(c>>18),128|((c>>12)&63),128|((c>>6)&63),128|(c&63));
      }
      return new Uint8Array(bytes);
    };
  }
})();
