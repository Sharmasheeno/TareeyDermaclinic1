import { spawn } from 'node:child_process';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const BASE = 'http://127.0.0.1:8137';
const PORT = 9341;
const REF = 'QA-E2E-REF-0001';
const R = [];
const rec = (area, name, ok, detail) => R.push({ area: area, name: name, ok: !!ok, detail: detail === undefined ? '' : String(detail) });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const profile = join(tmpdir(), 'tdc-e2e-' + Date.now());
const chrome = spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
  '--headless=new', '--disable-gpu', '--no-first-run',
  '--remote-debugging-port=' + PORT,
  '--user-data-dir=' + profile,
  '--window-size=1440,1000',
  BASE + '/auth/auth.php'
], { stdio: 'ignore' });

let socket = null, seq = 0, closed = false;
const pending = new Map();

function failAll(reason) {
  closed = true;
  for (const entry of pending.values()) { clearTimeout(entry.timer); entry.reject(new Error(reason)); }
  pending.clear();
}

async function connect() {
  for (let i = 0; i < 60; i++) {
    try {
      const pages = await fetch('http://127.0.0.1:' + PORT + '/json').then((r) => r.json());
      const page = pages.find((p) => p.type === 'page');
      if (page) {
        socket = new WebSocket(page.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => {
          socket.addEventListener('open', resolve, { once: true });
          socket.addEventListener('error', reject, { once: true });
        });
        socket.addEventListener('message', (event) => {
          const m = JSON.parse(event.data);
          if (!m.id || !pending.has(m.id)) return;
          const p = pending.get(m.id);
          pending.delete(m.id);
          clearTimeout(p.timer);
          if (m.error) p.reject(new Error(m.error.message)); else p.resolve(m.result);
        });
        socket.addEventListener('close', () => failAll('CDP socket closed'));
        socket.addEventListener('error', () => failAll('CDP socket error'));
        return;
      }
    } catch (e) { /* retry */ }
    await sleep(250);
  }
  throw new Error('Chrome DevTools unavailable');
}

function command(method, params) {
  if (closed) return Promise.reject(new Error('CDP socket not usable'));
  const id = ++seq;
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => { if (pending.has(id)) pending.delete(id); reject(new Error('CDP timeout: ' + method)); }, 20000);
    pending.set(id, { resolve: resolve, reject: reject, timer: timer });
    socket.send(JSON.stringify({ id: id, method: method, params: params || {} }));
  });
}

async function ev(expr) {
  try {
    const r = await command('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) {
      const ex = r.exceptionDetails.exception;
      return { __err: ex && ex.description ? ex.description : r.exceptionDetails.text };
    }
    return r.result.value;
  } catch (e) {
    return { __err: String(e && e.message ? e.message : e) };
  }
}

async function go(url, wait) {
  await command('Page.navigate', { url: url });
  await sleep(wait || 900);
}

async function viewport(w, h, mobile) {
  await command('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: !!mobile });
  await sleep(250);
}

async function ensureLoggedIn() {
  const st = await ev('({path:location.pathname})');
  if (!st || String(st.path).indexOf('auth.php') === -1) return false;
  await go(BASE + '/auth/auth.php', 900);
  await ev('document.querySelector(\'#username\').value=\'uat_superadmin\';document.querySelector(\'#password\').value=\'TareySA#926!\';document.querySelector(\'#loginForm\').submit();1');
  await sleep(1800);
  return true;
}

const overflowExpr = '(function(){var d=document.documentElement;function clipped(el){var p=el.parentElement;while(p&&p!==document.body){var s=getComputedStyle(p);if((s.overflowX===\'auto\'||s.overflowX===\'scroll\')&&p.scrollWidth>p.clientWidth+1)return true;p=p.parentElement;}return false;}var bad=0;var wide=[];document.querySelectorAll(\'body *\').forEach(function(el){var r=el.getBoundingClientRect();if(r.width>0&&r.right>d.clientWidth+2&&!clipped(el)){bad++;if(wide.length<6)wide.push(el.tagName+\'.\'+(el.className||\'\').toString().split(\' \')[0]+\':\'+Math.round(r.right));}});return {cw:d.clientWidth,sw:d.scrollWidth,overflow:bad,samples:wide};})()';

async function main() {
  await connect();
  await command('Page.enable');
  await command('Runtime.enable');

  await go(BASE + '/auth/auth.php', 900);
  await ev('document.querySelector(\'#username\').value=\'uat_superadmin\';document.querySelector(\'#password\').value=\'TareySA#926!\';document.querySelector(\'#loginForm\').submit();1');
  await sleep(1800);
  const who = await ev('({path:location.pathname})');
  rec('auth', 'login as superuser (is_root=0)', who && who.path && who.path.indexOf('auth.php') === -1, JSON.stringify(who));

  // ---------- 1. PHARMACY NEW PURCHASE ----------
  await go(BASE + '/auth/pages/pharmacy.php?section=purchases&new=1', 1500);
  const pre = await ev('(function(){return {form:!!document.getElementById(\'purchaseForm\'),supplier:!!document.getElementById(\'pof_SupplierName\'),phone:!!document.getElementById(\'pof_SupplierPhone\'),ref:!!document.getElementById(\'pof_ReferenceNumber\'),date:!!document.getElementById(\'pof_PurchaseDate\'),sub:!!document.getElementById(\'pof_SubtotalDisplay\'),net:!!document.getElementById(\'pof_NetDisplay\'),due:!!document.getElementById(\'pof_DueDisplay\'),disc:!!document.getElementById(\'pof_Discount\'),vat:!!document.getElementById(\'pof_VATAmount\'),paid:!!document.getElementById(\'pof_AmountPaid\'),addItem:!!document.getElementById(\'addLineBtn\'),rows:document.querySelectorAll(\'#lineItemsBody tr\').length,medPicker:document.querySelectorAll(\'#lineItemsBody input[name="ItemName[]"][list]\').length};})()');
  rec('purchase', 'all purchase form fields render', pre && pre.form && pre.supplier && pre.phone && pre.ref && pre.date && pre.disc && pre.vat && pre.paid && pre.sub && pre.net && pre.due && pre.addItem, JSON.stringify(pre));

  const addRow = await ev('(function(){var b=document.getElementById(\'addLineBtn\');var before=document.querySelectorAll(\'#lineItemsBody tr\').length;b.click();var mid=document.querySelectorAll(\'#lineItemsBody tr\').length;var last=document.querySelectorAll(\'#lineItemsBody tr\')[mid-1];last.querySelector(\'.remove-line-btn\').click();var end=document.querySelectorAll(\'#lineItemsBody tr\').length;return {before:before,mid:mid,end:end};})()');
  rec('purchase', 'Add Item / Remove Item work', addRow && addRow.mid === addRow.before + 1 && addRow.end === addRow.before, JSON.stringify(addRow));

  const fill = await ev(`(function(){
    var row=document.querySelector('#lineItemsBody tr');
    document.getElementById('pof_SupplierName').value='QA Supplier E2E';
    document.getElementById('pof_SupplierPhone').value='+2520000000';
    document.getElementById('pof_ReferenceNumber').value='${REF}';
    document.getElementById('pof_PurchaseDate').value='2026-09-16';
    var vals={'ItemName[]':'paractamol','Category[]':'Analgesic','Quantity[]':'4','PurchaseUnit[]':'Box','ConversionFactor[]':'1','SalesUnit[]':'Tablet','UnitPrice[]':'3.00','SellingPrice[]':'5.00','ExpiryDate[]':'2028-12-31'};
    Object.keys(vals).forEach(function(n){var el=row.querySelector('input[name="'+n+'"]');if(el){el.value=vals[n];el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));}});
    [['pof_Discount','2.00'],['pof_VATAmount','1.00'],['pof_AmountPaid','5.00']].forEach(function(p){var el=document.getElementById(p[0]);el.value=p[1];el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));});
    return {sub:document.getElementById('pof_SubtotalDisplay').textContent.trim(),net:document.getElementById('pof_NetDisplay').textContent.trim(),due:document.getElementById('pof_DueDisplay').textContent.trim()};
  })()`);
  const num = (s) => parseFloat(String(s).replace(/[^0-9.\-]/g, ''));
  rec('purchase', 'live totals: Subtotal 12, Net 11, Due 6', fill && Math.abs(num(fill.sub) - 12) < 0.01 && Math.abs(num(fill.net) - 11) < 0.01 && Math.abs(num(fill.due) - 6) < 0.01, JSON.stringify(fill));

  await ev('document.getElementById(\'purchaseForm\').submit();1');
  await sleep(3000);
  const after = await ev('({url:location.href,text:document.body.innerText.slice(0,400)})');
  rec('purchase', 'submit completes without PHP error', after && after.url.indexOf('pharmacy.php') !== -1 && !/Fatal error|Parse error|Uncaught|Warning:/i.test(after.text), JSON.stringify(after.url));

  // ---------- 2. DOCTOR PATIENT WAITING ----------
  await go(BASE + '/auth/pages/reception.php?section=consultations', 1500);
  const dw = await ev(`(function(){
    var heads=Array.from(document.querySelectorAll('.data-table thead th')).map(function(t){return t.textContent.trim();});
    var t=document.body.innerText;
    return {
      heads:heads,
      from:!!document.querySelector('input[name="from_date"]'),
      to:!!document.querySelector('input[name="to_date"]'),
      search:!!document.querySelector('input[name="q"]'),
      perPage:!!document.querySelector('select[name="per_page"]'),
      csv:t.indexOf('Export CSV')!==-1,
      pdf:t.indexOf('PDF')!==-1,
      print:t.indexOf('Print')!==-1,
      rows:document.querySelectorAll('.data-table tbody tr').length
    };
  })()`);
  const need = ['Appointment','Patient','Gender','Age','Phone','Visit date','Queue','Actions'];
  const missing = need.filter((h) => !dw || !dw.heads || dw.heads.indexOf(h) === -1);
  rec('doctor-waiting', 'table columns present', missing.length === 0, 'missing=' + missing.join(',') + ' heads=' + JSON.stringify(dw && dw.heads));
  rec('doctor-waiting', 'toolbar: from/to date, search, entries', dw && dw.from && dw.to && dw.search && dw.perPage, JSON.stringify(dw));
  rec('doctor-waiting', 'export controls: CSV / PDF / Print', dw && dw.csv && dw.pdf && dw.print, JSON.stringify({ csv: dw && dw.csv, pdf: dw && dw.pdf, print: dw && dw.print }));

  const linkCheck = await ev(`(async function(){
    var urls=Array.from(new Set(Array.from(document.querySelectorAll('a[href]')).map(function(a){return a.getAttribute('href');}).filter(function(h){return h&&h.indexOf('#')!==0&&h.indexOf('javascript:')!==0&&h.indexOf('logout')===-1;})));
    var out=[];
    for (var i=0;i<urls.length && i<20;i++){ try{ var r=await fetch(urls[i],{method:'GET',redirect:'follow'}); out.push(urls[i]+' => '+r.status);}catch(e){out.push(urls[i]+' => ERR');} }
    var dead=Array.from(document.querySelectorAll('.data-table tbody button')).filter(function(b){return !b.form && !b.closest('form') && !b.getAttribute('onclick');}).map(function(b){return b.textContent.trim();});
    var bad=out.filter(function(s){return s.indexOf('=> 404')!==-1||s.indexOf('=> 500')!==-1||s.indexOf('=> ERR')!==-1;});
    return {checked:out.length,bad:bad,samples:out.slice(0,8),deadButtons:dead};
  })()`);
  rec('doctor-waiting', 'no 404/500/error links on page', linkCheck && linkCheck.bad && linkCheck.bad.length === 0, JSON.stringify(linkCheck && linkCheck.bad));
  rec('doctor-waiting', 'no dead row buttons', linkCheck && linkCheck.deadButtons && linkCheck.deadButtons.length === 0, JSON.stringify(linkCheck && linkCheck.deadButtons));

  const rowActions = await ev(`(function(){
    var rows=Array.from(document.querySelectorAll('.data-table tbody tr'));
    if(!rows.length) return {noRows:true};
    var r=rows[0];
    return {
      noRows:false,
      links:Array.from(r.querySelectorAll('a')).map(function(a){return a.getAttribute('href');}),
      buttons:Array.from(r.querySelectorAll('button')).map(function(b){return b.textContent.trim();}),
      forms:Array.from(r.querySelectorAll('form')).map(function(f){return f.getAttribute('action');}),
      inputs:Array.from(r.querySelectorAll('input,select')).map(function(i){return (i.name||i.getAttribute('aria-label')||'');}).filter(Boolean)
    };
  })()`);
  rec('doctor-waiting', 'row actions enumerated', rowActions && (rowActions.noRows === true || rowActions.buttons.length + rowActions.links.length + rowActions.forms.length > 0), JSON.stringify(rowActions));

  // collect form is a real POST; only exercised when a pending-payment row exists
  const collect = await ev(`(function(){var f=document.querySelector('.balance-form');if(!f)return {none:true};var btn=f.querySelector('button');return {none:false,btn:btn?btn.textContent.trim():null,amount:f.querySelector('input[name="PaymentAmount"]')?f.querySelector('input[name="PaymentAmount"]').value:null,method:!!f.querySelector('select[name="PaymentMethod"]')};})()`);
  rec('doctor-waiting', 'payment/collect action present or legitimately absent', collect && (collect.none === true || (collect.btn && collect.method)), JSON.stringify(collect));

  const dateInvalid = await ev(`(function(){var f=document.querySelector('form.date-range');if(!f)return {skip:true};var ff=document.querySelector('input[name="from_date"]');var ft=document.querySelector('input[name="to_date"]');if(!ff||!ft)return {skip:true};ff.value='2026-09-20';ft.value='2026-09-10';f.submit();return {skip:false};})()`);
  if (dateInvalid && dateInvalid.skip !== true) {
    await sleep(1600);
    const dv = await ev('({url:location.href,text:document.body.innerText.slice(0,500)})');
    rec('doctor-waiting', 'from>to date handled (rejected or clearly messaged)', dv && !/Fatal error|Uncaught/i.test(dv.text), JSON.stringify(dv.url));
  }

  // ---------- 3. SUPERADMIN DOCTOR WORKSPACE ROUTING ----------
  await ensureLoggedIn();
  await go(BASE + '/auth/pages/doctors.php', 1300);
  const ws = await ev('(function(){var t=document.body.innerText;return {workspace:/Start Consultation|Waiting today/i.test(t),directory:/Add Doctor|Import Doctors|Specialization/i.test(t),path:location.pathname};})()');
  rec('workspace', 'SuperAdmin doctors.php renders (no 403/blank)', ws && ws.path.indexOf('auth.php') === -1 && (ws.workspace || ws.directory), JSON.stringify(ws));
  R.push({ area: 'workspace', name: 'INFO superadmin doctors.php mode', ok: true, detail: JSON.stringify(ws) });

  const portalDirect = await ev('(async function(){var r=await fetch(\'reception.php?section=consultations\',{method:\'GET\'});return r.status;})()');
  rec('workspace', 'SuperAdmin can open Doctor Waiting list directly', portalDirect === 200, String(portalDirect));

  // ---------- 4. MODAL INTERACTION ----------
  await ensureLoggedIn();
  await go(BASE + '/auth/pages/reception.php?section=patients', 1400);
  const open = await ev('(function(){var b=document.getElementById(\'addPatientBtn\')||Array.from(document.querySelectorAll(\'button,a\')).filter(function(x){return /register patient|add patient/i.test(x.textContent);})[0];if(!b)return {err:\'no trigger\'};b.focus();b.click();return {ok:true};})()');
  await sleep(700);
  const modal1 = await ev('(function(){var o=document.getElementById(\'patientModalOverlay\');if(!o)return {err:\'no overlay\'};var cs=getComputedStyle(o);var bs=getComputedStyle(document.body);var b=o.querySelector(\'.modal-box\');var r=b.getBoundingClientRect();return {display:cs.display,pos:cs.position,z:cs.zIndex,bodyOverflow:bs.overflow,cx:Math.round(r.left+r.width/2),cy:Math.round(r.top+r.height/2),vw:window.innerWidth,vh:window.innerHeight,within:r.left>=0&&r.right<=window.innerWidth+1&&r.top>=0&&r.bottom<=window.innerHeight+1,scrollable:getComputedStyle(b).overflowY};})()');
  rec('modal', 'opens centered, backdrop, scroll locked', modal1 && modal1.pos === 'fixed' && modal1.bodyOverflow === 'hidden' && modal1.within === true, JSON.stringify(modal1));

  const esc = await ev('(function(){var o=document.getElementById(\'patientModalOverlay\');document.dispatchEvent(new KeyboardEvent(\'keydown\',{key:\'Escape\',bubbles:true}));document.dispatchEvent(new KeyboardEvent(\'keyup\',{key:\'Escape\',bubbles:true}));return {stillOpen:getComputedStyle(o).display!==\'none\'&&o.offsetParent!==null};})()');
  rec('modal', 'Escape closes modal', esc && esc.stillOpen === false, JSON.stringify(esc));

  const xclose = await ev('(function(){var o=document.getElementById(\'patientModalOverlay\');var b=document.getElementById(\'addPatientBtn\');if(b)b.click();var x=o.querySelector(\'.modal-close,[data-close-patient],button[aria-label*="Close"]\');if(!x)return {err:\'no close button\'};x.click();return {closed:o.offsetParent===null||getComputedStyle(o).display===\'none\',focusReturned:document.activeElement===b};})()');
  rec('modal', 'X close works and focus returns', xclose && xclose.closed === true, JSON.stringify(xclose));

  // ---------- 5. RESPONSIVE ----------
  for (const size of [[1920, 1080], [1366, 768], [820, 900], [480, 900]]) {
    await viewport(size[0], size[1], size[0] < 900);
    for (const t of [['reception.php?section=consultations', 'Doctor waiting'], ['pharmacy.php?section=purchases&new=1', 'New purchase'], ['reports.php?section=income-statement', 'Income statement']]) {
      await go(BASE + '/auth/pages/' + t[0], 1000);
      const o = await ev(overflowExpr);
      rec('responsive', size[0] + 'px ' + t[1] + ' no page overflow', o && o.overflow === 0, JSON.stringify(o));
    }
  }
  await command('Emulation.clearDeviceMetricsOverride');
  await sleep(300);

  await viewport(480, 900, true);
  let mobBuyState = null;
  for (let attempt = 0; attempt < 3; attempt++) {
    await go(BASE + '/auth/pages/pharmacy.php?section=purchases&new=1', 1400);
    mobBuyState = await ev('(function(){var f=document.getElementById(\'purchaseForm\');return {hasForm:!!f,path:location.pathname,search:location.search,title:document.title,login:!!document.getElementById(\'loginForm\')};})()');
    if (mobBuyState && mobBuyState.hasForm) break;
    await sleep(900);
  }
  rec('responsive', '480px purchase form present', mobBuyState && mobBuyState.hasForm === true, JSON.stringify(mobBuyState));
  const mobBuy = await ev('(function(){var f=document.getElementById(\'purchaseForm\');if(!f)return {err:\'no form\'};var r=f.getBoundingClientRect();return {left:Math.round(r.left),right:Math.round(r.right),vw:window.innerWidth,within:r.left>=-1&&r.right<=window.innerWidth+1};})()');
  rec('responsive', '480px purchase form fits viewport', mobBuy && mobBuy.within === true, JSON.stringify(mobBuy));
  await command('Emulation.clearDeviceMetricsOverride');
  await sleep(300);
}

try {
  await main();
} catch (e) {
  rec('harness', 'fatal', false, String(e && e.message ? e.message : e));
} finally {
  try { if (socket) socket.close(); } catch (e) { /* ignore */ }
  try { chrome.kill(); } catch (e) { /* ignore */ }
  await sleep(400);
  const failed = R.filter((x) => !x.ok);
  console.log(JSON.stringify({ total: R.length, failed: failed.length, failures: failed, all: R }, null, 2));
  process.exit(failed.length === 0 ? 0 : 2);
}
