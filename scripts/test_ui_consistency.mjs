import {spawn,spawnSync} from 'node:child_process';
import {readFile,writeFile,mkdir,unlink} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';
const delay=ms=>new Promise(r=>setTimeout(r,ms));
const names=['dashboard','reception','registration','waiting','schedule','doctors','laboratory','inventory','purchase','purchases','pos','accounting','expense','reports','setup','reception-role','pharmacy-role'];
names.push('permissions-edit');
names.push(...JSON.parse(await readFile(join(tmpdir(),'tdc-ui-http-snapshots.json'),'utf8')));
if(process.argv.includes('--focused'))names.splice(0,names.length,...names.filter(n=>['inventory','permissions-edit','reports','reception-role','pharmacy-role'].includes(n)));
const widths=[1920,1366,1080,820,480];
const out=join(tmpdir(),'tdc-ui-consistency');await mkdir(out,{recursive:true});
const results=[];const record=(name,ok,detail='')=>{results.push({name,ok,detail});console.log(`${ok?'PASS':'FAIL'} ${name}${detail?' '+detail:''}`);};
for (const name of names) {
 const html=await readFile(join(tmpdir(),`tdc-ui-${name}.html`),'utf8');
 for(const [i,m] of [...html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].entries()){
  const file=join(out,`${name}-${i}.js`);await writeFile(file,m[1]);const r=spawnSync(process.execPath,['--check',file],{encoding:'utf8'});await unlink(file);record(`${name} script ${i}`,r.status===0,r.stderr);
 }
}
const port=9356;
const chrome=spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',['--headless=new','--disable-gpu','--no-first-run',`--remote-debugging-port=${port}`,`--user-data-dir=${join(tmpdir(),'tdc-ui-profile-'+Date.now())}`,'about:blank'],{stdio:'ignore'});
let socket;const pending=new Map();let seq=0;
try{
 for(let i=0;i<50;i++){try{await fetch(`http://127.0.0.1:${port}/json`);break;}catch{await delay(100);}}
 const tab=await fetch(`http://127.0.0.1:${port}/json/new?about:blank`,{method:'PUT'}).then(r=>r.json());socket=new WebSocket(tab.webSocketDebuggerUrl);
 await new Promise((r,j)=>{socket.addEventListener('open',r,{once:true});socket.addEventListener('error',j,{once:true});});
 const errors=[];
 socket.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text);if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}});
 const call=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq;const timer=setTimeout(()=>reject(Error('Timeout '+method)),10000);pending.set(id,{resolve:v=>{clearTimeout(timer);resolve(v);},reject:e=>{clearTimeout(timer);reject(e);}});socket.send(JSON.stringify({id,method,params}));});
 const ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value;};
 const shot=async name=>writeFile(join(out,name+'.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
 await call('Page.enable');await call('Runtime.enable');
 for(const name of names){
  errors.length=0;
  await call('Page.navigate',{url:pathToFileURL(join(tmpdir(),`tdc-ui-${name}.html`)).href});await delay(350);
  for(const width of widths){
   await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});await delay(70);
   const metrics=await ev(`(()=>{const root=document.documentElement;const modal=document.querySelector('.modal-overlay.show .modal-box');return {overflow:root.scrollWidth>innerWidth+2,scroll:root.scrollWidth,width:innerWidth,modal:!modal||(modal.getBoundingClientRect().width<=innerWidth&&modal.getBoundingClientRect().height<=innerHeight),primary:[...document.querySelectorAll('.btn-primary')].filter(x=>x.offsetWidth).every(x=>getComputedStyle(x).backgroundColor!=='rgb(255, 255, 255)'),success:[...document.querySelectorAll('.btn-success')].filter(x=>x.offsetWidth&&!x.disabled).every(x=>getComputedStyle(x).backgroundColor==='rgb(21, 153, 87)'&&[x,...x.querySelectorAll('span,svg')].every(n=>getComputedStyle(n).color==='rgb(255, 255, 255)'))};})()`);
   record(`${name} ${width}px`,!metrics.overflow&&metrics.modal&&metrics.primary&&metrics.success,JSON.stringify(metrics));
   if(width===1366)await shot(name);
  }
  record(`${name} runtime`,errors.length===0,errors.join('; '));
  if(name==='reports')record('KPI icons do not overlap labels',await ev(`[...document.querySelectorAll('.report-kpis .kpi-card')].every(card=>card.querySelector('.kpi-icon').getBoundingClientRect().right<card.querySelector('.kpi-label').getBoundingClientRect().left)`));
  if(name==='permissions-edit'){
   record('Permissions no-change Save disabled',await ev(`document.querySelector('#permission-form .btn-success').disabled`));
   await ev(`document.querySelector('#permission-form input[name="permissions[]"]').click()`);
   record('Permissions dirty Save white text and icon',await ev(`(()=>{const b=document.querySelector('#permission-form .btn-success');return !b.disabled&&[b,b.querySelector('span'),b.querySelector('svg')].every(n=>getComputedStyle(n).color==='rgb(255, 255, 255)');})()`));
   const position=await ev(`(()=>{const b=document.querySelector('#permission-form .btn-success');b.scrollIntoView({block:'center'});const r=b.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()`);
   await call('Input.dispatchMouseEvent',{type:'mouseMoved',...position});
   record('Permissions hover retains white foreground',await ev(`getComputedStyle(document.querySelector('#permission-form .btn-success span')).color==='rgb(255, 255, 255)'`));
   record('Permissions compact mobile search',await ev(`document.querySelector('#permission-search').getBoundingClientRect().height<=48`));
   await shot('permissions-enabled');
   await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab'});
   await ev(`document.querySelector('#permission-form .btn-success').focus()`);
   record('Permissions focus ring',await ev(`getComputedStyle(document.querySelector('#permission-form .btn-success')).outlineStyle!=='none'`));
   await ev(`document.querySelector('#permission-form input[name="permissions[]"]').click()`);
   record('Permissions restored Save disabled',await ev(`document.querySelector('#permission-form .btn-success').disabled`));
  }
  if(['inventory','reception-role','pharmacy-role'].includes(name)){
   await ev(`document.querySelector('#addItemBtn').click()`);
   record(name+' simple medicine form',await ev(`!document.querySelector('#itemForm details')&&document.querySelector('label[for="if_SalesUnit"]').textContent.trim()==='Base Unit *'&&document.querySelector('#medicineSaveLabel').textContent==='Save Medicine'`));
   await ev(`document.querySelector('#itemModalCancelBtn').click();document.querySelector('.edit-item-btn').click()`);
   record(name+' edit medicine fields populated',await ev(`(()=>{const b=document.querySelector('.edit-item-btn');return document.querySelector('#if_SalesUnit').value===b.dataset.unit&&document.querySelector('#if_QuantityInStock').value===b.dataset.stock&&document.querySelector('#if_SellingPrice').value===b.dataset.price&&document.querySelector('#medicineSaveLabel').textContent==='Update Medicine';})()`));
   await ev(`document.querySelector('#itemModalCancelBtn').click()`);
  }
  if(name==='doctors'){
   await ev(`document.querySelector('.profile-trigger').focus()`);
   await call('Input.dispatchKeyEvent',{type:'keyDown',key:'ArrowDown',code:'ArrowDown'});
   record('profile keyboard opens and focuses',await ev(`!document.querySelector('#profile-panel').hidden&&document.querySelector('#profile-panel').contains(document.activeElement)`));
   await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
   record('profile Escape returns focus',await ev(`document.querySelector('#profile-panel').hidden&&document.activeElement===document.querySelector('.profile-trigger')`));
   await ev(`document.querySelector('.profile-trigger').click();document.body.click()`);
   record('profile outside click closes',await ev(`document.querySelector('#profile-panel').hidden`));
   await ev(`window.confirmResult=null;void TDCModal.confirm({message:'Delete test record?',destructive:true}).then(v=>window.confirmResult=v)`);
   record('confirmation starts on Cancel',await ev(`document.activeElement.matches('[data-cancel]')`));
   await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});await delay(30);
   record('confirmation Escape cancels',await ev(`window.confirmResult===false&&!document.querySelector('.confirmation-dialog')`));
   await ev(`TDCModal.confirm({message:'Delete test record?',destructive:true}).then(v=>window.confirmResult=v);document.querySelector('.confirmation-dialog [data-confirm]').click()`);await delay(20);
   record('confirmation accepts',await ev(`window.confirmResult===true`));
  }
  if(name.startsWith('report-')){
   const printed=await call('Page.printToPDF',{printBackground:true});
   record(`${name} browser PDF`,Buffer.from(printed.data,'base64').subarray(0,5).toString()==='%PDF-');
  }
  const openers={registration:'#addPatientBtn',inventory:'#addItemBtn',doctors:'#addDoctorBtn',setup:'#addSetupUserBtn'};
  if(openers[name]){
   const selector=openers[name];const exists=await ev(`!!document.querySelector(${JSON.stringify(selector)})`);
   if(exists){
    await call('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});
    await ev(`document.querySelector(${JSON.stringify(selector)}).focus();document.querySelector(${JSON.stringify(selector)}).click()`);await delay(50);
    record(`${name} modal opens`,await ev(`document.body.classList.contains('modal-open')&&!!document.querySelector('.modal-overlay.show')`));await shot(name+'-modal');
    await call('Emulation.setDeviceMetricsOverride',{width:480,height:900,deviceScaleFactor:1,mobile:false});
    record(`${name} modal mobile`,await ev(`(()=>{const p=document.querySelector('.modal-overlay.show .modal-box');return p&&p.getBoundingClientRect().width<=480&&p.getBoundingClientRect().height<=900;})()`));
    await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});await delay(30);
    record(`${name} modal focus return`,await ev(`document.activeElement===document.querySelector(${JSON.stringify(selector)})&&!document.body.classList.contains('modal-open')`));
    await ev(`document.querySelector(${JSON.stringify(selector)}).click()`);await delay(30);
    record(`${name} modal accessible on reopen`,await ev(`!!document.querySelector('.modal-overlay.show .modal-box:not([aria-hidden="true"])')`));
   }else record(`${name} opener exists`,false,selector);
  }
 }
 // Browser-rendered overview for visual review; all images contain disposable fixtures.
 const gallery=join(out,'overview.html');
 await writeFile(gallery,`<!doctype html><style>body{font:16px Arial;background:#e5e7eb;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin:12px}figure{margin:0;background:white;padding:8px}img{width:100%}figcaption{padding:4px;font-weight:bold}</style>`+['dashboard','registration-modal','waiting','doctors','laboratory','inventory-modal','purchase','pos','accounting','reports','setup','reception-role'].map(n=>`<figure><figcaption>${n}</figcaption><img src="${n}.png"></figure>`).join(''));
 await call('Emulation.setDeviceMetricsOverride',{width:1920,height:2100,deviceScaleFactor:1,mobile:false});await call('Page.navigate',{url:pathToFileURL(gallery).href});await delay(300);await shot('overview');
}finally{socket?.close();chrome.kill();await writeFile(process.argv.includes('--focused')?'scripts/ui-focused-test-results.json':'scripts/ui-consistency-test-results.json',JSON.stringify(results,null,2)+'\n');}
console.log(`${results.length} checks; ${results.filter(r=>!r.ok).length} failed. Screenshots: ${out}`);
if(results.some(r=>!r.ok))process.exitCode=1;
