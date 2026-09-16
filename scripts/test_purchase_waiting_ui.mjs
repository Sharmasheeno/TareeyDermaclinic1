import {spawn, spawnSync} from 'node:child_process';
import {readFile,writeFile,unlink} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';
const delay=ms=>new Promise(r=>setTimeout(r,ms));
for (const name of ['purchase','waiting']) {
    const html=await readFile(join(tmpdir(),`tdc-${name}.html`),'utf8');
    for (const [index,match] of [...html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].entries()) {
        const file=join(tmpdir(),`tdc-${name}-${index}.js`);
        await writeFile(file,match[1]);
        const result=spawnSync(process.execPath,['--check',file],{encoding:'utf8'});
        await unlink(file);
        if(result.status) throw Error(result.stderr);
    }
}
const port=9352;
const chrome=spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',['--headless=new','--disable-gpu','--no-first-run',`--remote-debugging-port=${port}`,`--user-data-dir=${join(tmpdir(),'tdc-purchase-ui-'+Date.now())}`,'about:blank'],{stdio:'ignore'});
let socket;const pending=new Map();let seq=0;
try {
    let tabs;
    for(let i=0;i<50;i++){try{tabs=await fetch(`http://127.0.0.1:${port}/json`).then(r=>r.json());break;}catch{await delay(100);}}
    const tab=await fetch(`http://127.0.0.1:${port}/json/new?about:blank`,{method:'PUT'}).then(r=>r.json());
    socket=new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise(r=>socket.addEventListener('open',r,{once:true}));
    socket.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.id){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}});
    socket.addEventListener('close',()=>{for(const p of pending.values())p.reject(Error('Chrome target closed'));pending.clear();});
    const call=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq;const timer=setTimeout(()=>reject(Error('Timed out: '+method)),10000);pending.set(id,{resolve:value=>{clearTimeout(timer);resolve(value);},reject:error=>{clearTimeout(timer);reject(error);}});socket.send(JSON.stringify({id,method,params}));});
    const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value;};
    const check=async(name,expr)=>{if(!await evaluate(expr))throw Error(name);console.log('PASS '+name);};
    await call('Page.enable');
    await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await call('Page.navigate',{url:pathToFileURL(join(tmpdir(),'tdc-purchase.html')).href});await delay(1000);
    await check('Shared purchase modal opens with scroll lock',`document.querySelector('#purchaseModal').classList.contains('show') && document.body.classList.contains('modal-open')`);
    await check('Purchase units are labelled dropdowns', `document.querySelector('select[name="PurchaseUnit[]"]')?.closest('label').textContent.includes('Purchase unit') && document.querySelector('select[name="SalesUnit[]"]')?.closest('label').textContent.includes('Unit sold / dispensed') && !document.querySelector('input[name="SalesUnit[]"]')`);
    await evaluate(`document.querySelector('select[name="PurchaseUnit[]"]').value='Box';document.querySelector('select[name="SalesUnit[]"]').value='Tablet'`);
    await check('Desktop modal width',`document.querySelector('.purchase-modal').getBoundingClientRect().width > 1000`);
    await writeFile(join(tmpdir(),'tdc-purchase-desktop.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
    await evaluate(`(()=>{const row=document.querySelector('.line-item-row');const set=(n,v)=>row.querySelector('[name="'+n+'[]"]').value=v;set('Quantity',2);set('UnitPrice',10);document.querySelector('#pof_Discount').value=2;document.querySelector('#pof_VATAmount').value=1;document.querySelector('#pof_AmountPaid').value=5;document.querySelector('#pof_AmountPaid').dispatchEvent(new Event('input'));})()`);
    await check('Live net and due calculation',`document.querySelector('#pof_NetDisplay').textContent==='19.00' && document.querySelector('#pof_DueDisplay').textContent==='14.00'`);
    await evaluate(`document.querySelector('#addLineBtn').click()`);
    await check('New line resets unit selections', `[...document.querySelectorAll('.line-item-row')][1].querySelector('select[name="PurchaseUnit[]"]').value==='' && [...document.querySelectorAll('.line-item-row')][1].querySelector('select[name="SalesUnit[]"]').value===''`);
    await check('Add item',`document.querySelectorAll('.line-item-row').length===2`);
    await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
    await check('Escape closes and returns focus',`!document.body.classList.contains('modal-open') && document.activeElement.id==='openPurchaseModal'`);
    await evaluate(`document.querySelector('#openPurchaseModal').click()`);
    await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    await check('Mobile item fields stack',`getComputedStyle(document.querySelector('.purchase-modal td')).display==='block' && document.querySelector('.purchase-modal').getBoundingClientRect().width<=390`);
    await writeFile(join(tmpdir(),'tdc-purchase-mobile.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
    await call('Page.navigate',{url:pathToFileURL(join(tmpdir(),'tdc-waiting.html')).href});await delay(600);
    await check('Waiting toolbar and real actions',`!!document.querySelector('.waiting-toolbar input[name="q"]') && !!document.querySelector('.waiting-open[aria-label]') && document.querySelectorAll('.waiting-screen th').length===9`);
    console.log('PASS Rendered inline JavaScript syntax (both pages)');
} finally {socket?.close();chrome.kill();}
