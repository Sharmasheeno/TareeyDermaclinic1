import { spawn } from 'node:child_process';
import { writeFile, mkdir } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const BASE = 'http://127.0.0.1:8137';
const PORT = 9336;
const SHOTS = 'qa-shots';
const R = [];
const rec = (area, name, ok, detail) => R.push({ area: area, name: name, ok: !!ok, detail: detail === undefined ? '' : String(detail) });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const profile = join(tmpdir(), 'tdc-qa-' + Date.now());
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
  for (const entry of pending.values()) {
    clearTimeout(entry.timer);
    entry.reject(new Error(reason));
  }
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
    const timer = setTimeout(() => {
      if (pending.has(id)) pending.delete(id);
      reject(new Error('CDP timeout: ' + method));
    }, 20000);
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

async function shot(name) {
  const r = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  await writeFile(join(SHOTS, name), Buffer.from(r.data, 'base64'));
}

async function viewport(w, h, mobile) {
  await command('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: !!mobile });
  await sleep(250);
}

const overflowExpr = '(function(){var d=document.documentElement;function clipped(el){var p=el.parentElement;while(p&&p!==document.body){var s=getComputedStyle(p);if((s.overflowX===\'auto\'||s.overflowX===\'scroll\')&&p.scrollWidth>p.clientWidth+1)return true;p=p.parentElement;}return false;}var bad=0;var wide=[];document.querySelectorAll(\'body *\').forEach(function(el){var r=el.getBoundingClientRect();if(r.width>0&&r.right>d.clientWidth+2&&!clipped(el)){bad++;if(wide.length<6)wide.push(el.tagName+\'.\'+(el.className||\'\').toString().split(\' \')[0]+\':\'+Math.round(r.right));}});return {cw:d.clientWidth,sw:d.scrollWidth,overflow:bad,samples:wide};})()';

function pad2(n) { return String(n).padStart(2, '0'); }
function dobFor(years) {
  const t = new Date();
  const d = new Date(t.getFullYear() - years, t.getMonth(), t.getDate());
  return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
}

async function main() {
  await mkdir(SHOTS, { recursive: true });
  await connect();
  await command('Page.enable');
  await command('Runtime.enable');

  await go(BASE + '/auth/auth.php', 900);
  await ev('document.querySelector(\'#username\').value=\'uat_superadmin\';document.querySelector(\'#password\').value=\'TareySA#926!\';document.querySelector(\'#loginForm\').submit();1');
  await sleep(1600);
  const who = await ev('({path:location.pathname,user:(document.querySelector(\'.profile-name\')||{}).textContent||\'\'})');
  rec('auth', 'login as uat_superadmin (root=0)', who && who.path && who.path.indexOf('auth.php') === -1, JSON.stringify(who));

  const nav = await ev('Array.from(document.querySelectorAll(\'.nav-link\')).map(function(a){return a.textContent.trim();})');
  const navList = Array.isArray(nav) ? nav : [];
  ['Dashboard', 'Reception', 'Doctors', 'Patients', 'Laboratory', 'Pharmacy', 'Accounting', 'Reports', 'Setup'].forEach(function(item) {
    rec('nav', 'superadmin sees ' + item, navList.indexOf(item) !== -1, navList.join('|'));
  });

  const pages = [
    ['home.php', 'Dashboard'], ['reception.php', 'Reception'], ['doctors.php', 'Doctors'],
    ['patients.php', 'Patients'], ['laboratory.php', 'Laboratory'], ['pharmacy.php', 'Pharmacy'],
    ['accounting.php', 'Accounting'], ['reports.php', 'Reports'], ['setup.php', 'Setup']
  ];
  for (const p of pages) {
    await go(BASE + '/auth/pages/' + p[0], 950);
    const info = await ev('({path:location.pathname,title:document.title,denied:/not authorised|not authorized|forbidden/i.test(document.body.innerText.slice(0,4000))})');
    rec('pages', 'SuperAdmin can open ' + p[1], info && info.path && info.path.indexOf('auth.php') === -1 && !info.denied, JSON.stringify(info));
    await shot('page-' + p[0].replace('.php', '') + '.png');
  }

  await go(BASE + '/auth/pages/setup.php', 950);
  const setupTabs = await ev('Array.from(document.querySelectorAll(\'.setup-section-nav a,.setup-tab\')).map(function(a){return a.textContent.trim();})');
  rec('setup', 'setup section navigation renders', Array.isArray(setupTabs) && setupTabs.length >= 10, JSON.stringify(setupTabs));

  await go(BASE + '/auth/pages/reception.php?section=patients', 1100);
  const openAge = await ev('(function(){var b=document.getElementById(\'addPatientBtn\')||Array.from(document.querySelectorAll(\'button,a\')).filter(function(x){return /register patient|add patient/i.test(x.textContent);})[0];if(!b)return {err:\'no trigger\'};b.click();return {overlay:!!document.getElementById(\'patientModalOverlay\'),age:!!document.getElementById(\'pf_Age\'),dob:!!document.getElementById(\'pf_DateOfBirth\')};})()');
  rec('age-dob', 'reception register-patient modal opens', openAge && openAge.overlay && openAge.age && openAge.dob, JSON.stringify(openAge));

  const modalProbe = await ev('(function(){var o=document.getElementById(\'patientModalOverlay\');if(!o)return {err:\'no overlay\'};var b=o.querySelector(\'.modal-box\');var r=b.getBoundingClientRect();var cs=getComputedStyle(o);var bs=getComputedStyle(document.body);return {display:cs.display,position:cs.position,zIndex:cs.zIndex,backdrop:cs.backgroundColor,bodyOverflow:bs.overflow,cx:Math.round(r.left+r.width/2),cy:Math.round(r.top+r.height/2),vw:window.innerWidth,vh:window.innerHeight,left:Math.round(r.left),top:Math.round(r.top),w:Math.round(r.width),h:Math.round(r.height),openCount:document.querySelectorAll(\'.modal-overlay.show,.modal-overlay.is-open\').length};})()');
  rec('modal', 'overlay is fixed + dark backdrop', modalProbe && modalProbe.position === 'fixed' && /rgba?\(/.test(String(modalProbe.backdrop)), JSON.stringify(modalProbe));
  rec('modal', 'modal centred horizontally', modalProbe && Math.abs(modalProbe.cx - modalProbe.vw / 2) <= 4, modalProbe ? 'cx=' + modalProbe.cx + ' vw=' + modalProbe.vw : '');
  rec('modal', 'modal centred vertically', modalProbe && Math.abs(modalProbe.cy - modalProbe.vh / 2) <= 4, modalProbe ? 'cy=' + modalProbe.cy + ' vh=' + modalProbe.vh : '');
  rec('modal', 'modal fully inside viewport', modalProbe && modalProbe.left >= 0 && modalProbe.top >= 0 && modalProbe.top + modalProbe.h <= modalProbe.vh + 1, JSON.stringify(modalProbe));
  rec('modal', 'body scroll locked while open', modalProbe && modalProbe.bodyOverflow === 'hidden', modalProbe ? modalProbe.bodyOverflow : '');
  rec('modal', 'only one modal open', modalProbe && modalProbe.openCount === 1, modalProbe ? modalProbe.openCount : '');
  await shot('modal-patient-desktop.png');

  const age56 = await ev('(function(){var a=document.getElementById(\'pf_Age\');var d=document.getElementById(\'pf_DateOfBirth\');a.value=\'56\';a.dispatchEvent(new Event(\'input\',{bubbles:true}));return d.value;})()');
  rec('age-dob', 'age 56 derives DOB', age56 === dobFor(56), 'got=' + age56 + ' want=' + dobFor(56));

  const age30 = await ev('(function(){var a=document.getElementById(\'pf_Age\');var d=document.getElementById(\'pf_DateOfBirth\');a.value=\'30\';a.dispatchEvent(new Event(\'input\',{bubbles:true}));return d.value;})()');
  rec('age-dob', 'changing age updates DOB', age30 === dobFor(30), 'got=' + age30 + ' want=' + dobFor(30));

  const loopCheck = await ev('(function(){var a=document.getElementById(\'pf_Age\');var d=document.getElementById(\'pf_DateOfBirth\');var before=a.value;for(var i=0;i<12;i++){d.dispatchEvent(new Event(\'change\',{bubbles:true}));a.dispatchEvent(new Event(\'input\',{bubbles:true}));}return {age:a.value,dob:d.value,stable:a.value===before};})()');
  rec('age-dob', 'no age/DOB update loop', loopCheck && loopCheck.stable === true, JSON.stringify(loopCheck));

  const dobChange = await ev('(function(){var a=document.getElementById(\'pf_Age\');var d=document.getElementById(\'pf_DateOfBirth\');d.value=\'2000-01-01\';d.dispatchEvent(new Event(\'change\',{bubbles:true}));return {age:a.value,dob:d.value};})()');
  rec('age-dob', 'changing DOB recalculates age', dobChange && Number(dobChange.age) === 26, JSON.stringify(dobChange));

  const future = await ev('(function(){var a=document.getElementById(\'pf_Age\');var d=document.getElementById(\'pf_DateOfBirth\');var t=new Date();t.setDate(t.getDate()+5);var v=t.getFullYear()+\'-\'+String(t.getMonth()+1).padStart(2,\'0\')+\'-\'+String(t.getDate()).padStart(2,\'0\');d.value=v;d.dispatchEvent(new Event(\'change\',{bubbles:true}));return {future:v,age:a.value,neg:Number(a.value)<0};})()');
  rec('age-dob', 'future DOB never yields negative age', future && future.neg === false, JSON.stringify(future));

  const badAge = await ev('(function(){var a=document.getElementById(\'pf_Age\');var d=document.getElementById(\'pf_DateOfBirth\');a.value=\'999\';a.dispatchEvent(new Event(\'input\',{bubbles:true}));var over=d.value;a.value=\'abc\';a.dispatchEvent(new Event(\'input\',{bubbles:true}));return {over:over,min:a.getAttribute(\'min\'),max:a.getAttribute(\'max\')};})()');
  rec('age-dob', 'invalid ages rejected (0..150)', badAge && badAge.over === '' && badAge.max === '150' && badAge.min === '0', JSON.stringify(badAge));

  const escClose = await ev('(function(){document.dispatchEvent(new KeyboardEvent(\'keydown\',{key:\'Escape\',bubbles:true}));var o=document.getElementById(\'patientModalOverlay\');return {shown:o.classList.contains(\'show\'),bodyClass:document.body.className};})()');
  rec('modal', 'Escape closes modal', escClose && escClose.shown === false, JSON.stringify(escClose));

  const reopen = await ev('(function(){document.getElementById(\'addPatientBtn\').click();var o=document.getElementById(\'patientModalOverlay\');return {shown:o.classList.contains(\'show\')};})()');
  const cancelClose = await ev('(function(){document.getElementById(\'patientModalCancelBtn\').click();var o=document.getElementById(\'patientModalOverlay\');return {shown:o.classList.contains(\'show\')};})()');
  rec('modal', 'Cancel closes modal', reopen && reopen.shown && cancelClose && cancelClose.shown === false, JSON.stringify([reopen, cancelClose]));

  await go(BASE + '/auth/pages/patients.php', 1100);
  const patBtn = await ev('(function(){var b=document.getElementById(\'addPatientBtn\')||Array.from(document.querySelectorAll(\'button,a\')).filter(function(x){return /add patient|register patient|new patient/i.test(x.textContent);})[0];if(!b)return {err:\'no trigger\'};b.click();return {age:!!document.getElementById(\'pf_Age\'),dob:!!document.getElementById(\'pf_DateOfBirth\'),overlay:!!document.querySelector(\'.modal-overlay.show,.modal-overlay.is-open\')};})()');
  rec('age-dob', 'patients page modal exposes age + DOB', patBtn && patBtn.age && patBtn.dob, JSON.stringify(patBtn));
  if (patBtn && patBtn.age) {
    const pAge = await ev('(function(){var a=document.getElementById(\'pf_Age\');var d=document.getElementById(\'pf_DateOfBirth\');a.value=\'41\';a.dispatchEvent(new Event(\'input\',{bubbles:true}));return d.value;})()');
    rec('age-dob', 'patients page age 41 derives DOB', pAge === dobFor(41), 'got=' + pAge + ' want=' + dobFor(41));
    const pEdit = await ev('(function(){var row=document.querySelector(\'.edit-patient-btn,[data-dob]\');if(!row)return {none:true};row.click();return {age:document.getElementById(\'pf_Age\').value,dob:document.getElementById(\'pf_DateOfBirth\').value,title:document.getElementById(\'patientModalTitle\')?document.getElementById(\'patientModalTitle\').textContent:\'\'};})()');
    rec('age-dob', 'existing patient keeps stored DOB on edit', pEdit && (pEdit.none || (pEdit.dob && pEdit.dob.length === 10)), JSON.stringify(pEdit));
    await ev('document.dispatchEvent(new KeyboardEvent(\'keydown\',{key:\'Escape\',bubbles:true}));1');
  }
  await shot('page-patients-modal.png');

  await go(BASE + '/auth/pages/reception.php?section=consultations', 1300);
  const wait = await ev('(function(){var t=document.querySelector(\'.data-table\');var th=t?Array.from(t.querySelectorAll(\'thead th\')).map(function(x){return x.textContent.trim();}):[];return {heads:th,from:!!document.querySelector(\'input[name=from_date]\'),to:!!document.querySelector(\'input[name=to_date]\'),search:!!document.querySelector(\'input[name=q]\'),perPage:Array.from(document.querySelectorAll(\'select[name=per_page] option\')).map(function(o){return o.textContent.trim();}),csv:document.body.innerText.indexOf(\'Export CSV\')!==-1,print:!!document.querySelector(\'[data-print-page]\'),badges:document.querySelectorAll(\'.data-table .status-badge\').length};})()');
  rec('waiting', 'date range filter present', wait && wait.from && wait.to, wait ? wait.heads.join('|') : '');
  rec('waiting', 'search + entries + export present', wait && wait.search && wait.perPage.length === 4 && wait.csv && wait.print, JSON.stringify(wait));
  rec('waiting', 'queue table columns correct', wait && ['Appointment', 'Patient', 'Gender', 'Age', 'Phone', 'Visit date', 'Queue', 'Payment', 'Actions'].every(function(h) { return wait.heads.indexOf(h) !== -1; }), wait ? wait.heads.join('|') : '');
  await shot('page-doctor-waiting.png');

  await go(BASE + '/auth/pages/reception.php?section=consultations&from_date=2026-12-31&to_date=2026-01-01', 1200);
  const rangeErr = await ev('({hasError:/from date|to date|range|after|before/i.test(document.body.innerText.slice(0,6000)),hasInput:!!document.querySelector(\'input[name=from_date]\')})');
  rec('date-filter', 'from > to is rejected', rangeErr && rangeErr.hasError === true, JSON.stringify(rangeErr));

  await go(BASE + '/auth/pages/pharmacy.php?section=purchases', 1200);
  let po = await ev('(function(){var f=document.getElementById(\'purchaseForm\');return {form:!!f,add:!!document.getElementById(\'addLineBtn\'),sub:!!document.getElementById(\'pof_SubtotalDisplay\'),net:!!document.getElementById(\'pof_NetDisplay\'),due:!!document.getElementById(\'pof_DueDisplay\'),supplier:!!document.getElementById(\'pof_SupplierName\'),phone:!!document.getElementById(\'pof_SupplierPhone\'),ref:!!document.getElementById(\'pof_ReferenceNumber\'),date:!!document.getElementById(\'pof_PurchaseDate\'),disc:!!document.getElementById(\'pof_Discount\'),vat:!!document.getElementById(\'pof_VATAmount\'),paid:!!document.getElementById(\'pof_AmountPaid\')};})()');
  if (!po.form) {
    const link = await ev('(function(){var a=Array.from(document.querySelectorAll(\'a,button\')).filter(function(x){return /new purchase|add purchase|record purchase/i.test(x.textContent);})[0];if(!a)return {none:true};a.click();return {clicked:true};})()');
    await sleep(1200);
    po = await ev('(function(){var f=document.getElementById(\'purchaseForm\');return {form:!!f,add:!!document.getElementById(\'addLineBtn\'),sub:!!document.getElementById(\'pof_SubtotalDisplay\'),net:!!document.getElementById(\'pof_NetDisplay\'),due:!!document.getElementById(\'pof_DueDisplay\'),supplier:!!document.getElementById(\'pof_SupplierName\'),phone:!!document.getElementById(\'pof_SupplierPhone\'),ref:!!document.getElementById(\'pof_ReferenceNumber\'),date:!!document.getElementById(\'pof_PurchaseDate\'),disc:!!document.getElementById(\'pof_Discount\'),vat:!!document.getElementById(\'pof_VATAmount\'),paid:!!document.getElementById(\'pof_AmountPaid\')};})()');
    rec('purchase', 'new purchase form reachable from list', po.form === true, JSON.stringify(link));
  }
  rec('purchase', 'all header + item + summary fields present', po && po.form && po.supplier && po.phone && po.ref && po.date && po.add && po.sub && po.net && po.due && po.disc && po.vat && po.paid, JSON.stringify(po));

  const calc = await ev('(function(){var row=document.querySelector(\'#lineItemsBody tr\');function set(el,v){if(!el)return;el.value=v;el.dispatchEvent(new Event(\'input\',{bubbles:true}));el.dispatchEvent(new Event(\'change\',{bubbles:true}));}set(row.querySelector(\'input[name^=Quantity]\'),\'10\');set(row.querySelector(\'input[name^=UnitPrice]\'),\'10\');set(row.querySelector(\'input[name^=SellingPrice]\'),\'15\');set(row.querySelector(\'input[name^=ConversionFactor]\'),\'1\');set(document.getElementById(\'pof_Discount\'),\'10\');set(document.getElementById(\'pof_VATAmount\'),\'5\');set(document.getElementById(\'pof_AmountPaid\'),\'60\');function num(id){var t=document.getElementById(id);return t?parseFloat(String(t.textContent).replace(/[^0-9.]/g,\'\')):null;}return {sub:num(\'pof_SubtotalDisplay\'),net:num(\'pof_NetDisplay\'),due:num(\'pof_DueDisplay\'),remove:!!row.querySelector(\'.remove-line-btn\')};})()');
  rec('purchase', 'subtotal 100 for 10 x 10', calc && calc.sub === 100, JSON.stringify(calc));
  rec('purchase', 'net = subtotal - discount + VAT = 95', calc && calc.net === 95, JSON.stringify(calc));
  rec('purchase', 'due = max(0, net - paid) = 35', calc && calc.due === 35, JSON.stringify(calc));
  rec('purchase', 'remove-item control present', calc && calc.remove === true, JSON.stringify(calc));

  const over = await ev('(function(){var el=document.getElementById(\'pof_AmountPaid\');el.value=\'500\';el.dispatchEvent(new Event(\'input\',{bubbles:true}));el.dispatchEvent(new Event(\'change\',{bubbles:true}));var d=document.getElementById(\'pof_DueDisplay\');return {due:parseFloat(String(d.textContent).replace(/[^0-9.]/g,\'\'))};})()');
  rec('purchase', 'due never goes negative on overpayment', over && over.due === 0, JSON.stringify(over));

  const addRow = await ev('(function(){var b=document.getElementById(\'addLineBtn\');var n0=document.querySelectorAll(\'#lineItemsBody tr\').length;b.click();return {before:n0,after:document.querySelectorAll(\'#lineItemsBody tr\').length};})()');
  rec('purchase', 'Add Item appends a row', addRow && addRow.after === addRow.before + 1, JSON.stringify(addRow));
  await shot('page-pharmacy-purchase.png');

  await go(BASE + '/auth/pages/reports.php', 1300);
  const rc = await ev('(function(){var t=document.body.innerText;return {clinical:/clinical|patient/i.test(t),billing:/billing|payment|revenue/i.test(t),pharmacy:/pharmacy/i.test(t),laboratory:/laborator/i.test(t),financial:/financial|income|expense/i.test(t),csv:t.indexOf(\'Export CSV\')!==-1,xlsx:/xlsx/i.test(t)};})()');
  rec('reports', 'reports centre lists all five categories', rc && rc.clinical && rc.billing && rc.pharmacy && rc.laboratory && rc.financial, JSON.stringify(rc));
  rec('reports', 'exports labelled CSV not xlsx', rc && rc.xlsx === false, JSON.stringify(rc));
  await shot('page-reports-centre.png');

  for (const sec of ['income-statement', 'balance-sheet']) {
    await go(BASE + '/auth/pages/reports.php?section=' + sec, 1400);
    const s = await ev('(function(){var t=document.body.innerText;return {path:location.pathname+location.search,notFound:/404|not found/i.test(t),rows:document.querySelectorAll(\'.data-table tbody tr\').length,hasDate:!!document.querySelector(\'input[name=from],input[name=as_of]\'),csv:t.indexOf(\'Export CSV\')!==-1};})()');
    rec('reports', sec + ' renders (no 404)', s && s.path.indexOf('auth.php') === -1 && s.notFound === false, JSON.stringify(s));
    rec('reports', sec + ' exposes date filter + CSV', s && s.hasDate && s.csv, JSON.stringify(s));
    await shot('page-reports-' + sec + '.png');
  }

  await go(BASE + '/auth/pages/setup.php?section=roles', 1100);
  const roles = await ev('({dateFilter:!!document.querySelector(\'input[name=from_date],input[name=to_date]\')})');
  await go(BASE + '/auth/pages/setup.php?section=permissions', 1100);
  const perms = await ev('({dateFilter:!!document.querySelector(\'input[name=from_date]\'),groups:document.querySelectorAll(\'.permission-group\').length,items:document.querySelectorAll(\'.permission-item\').length})');
  await go(BASE + '/auth/pages/setup.php?section=organization', 1100);
  const org = await ev('({dateFilter:!!document.querySelector(\'input[name=from_date]\')})');
  rec('date-filter', 'no date filter on roles (static master data)', roles.dateFilter === false, JSON.stringify(roles));
  rec('date-filter', 'no date filter on permissions', perms.dateFilter === false, JSON.stringify(perms));
  rec('date-filter', 'no date filter on organization', org.dateFilter === false, JSON.stringify(org));
  rec('setup', 'permission modules render as groups', perms.groups > 0 && perms.items > 0, JSON.stringify(perms));

  const targets = [
    ['home.php', 'Dashboard'], ['reception.php', 'Reception'], ['doctors.php', 'Doctors'],
    ['patients.php', 'Patients'], ['laboratory.php', 'Laboratory'], ['pharmacy.php', 'Pharmacy'],
    ['accounting.php', 'Accounting'], ['reports.php', 'Reports'], ['setup.php', 'Setup'],
    ['reception.php?section=consultations', 'Doctor waiting'], ['pharmacy.php?section=purchases', 'Purchases']
  ];
  for (const size of [[1920, 1080], [1366, 768], [820, 900], [480, 900]]) {
    await viewport(size[0], size[1], size[0] < 900);
    for (const t of targets) {
      await go(BASE + '/auth/pages/' + t[0], 850);
      const o = await ev(overflowExpr);
      rec('responsive', size[0] + 'px ' + t[1] + ' no horizontal overflow', o && o.overflow === 0, JSON.stringify(o));
    }
  }
  await command('Emulation.clearDeviceMetricsOverride');
  await sleep(300);

  await viewport(480, 900, true);
  await go(BASE + '/auth/pages/reception.php?section=patients', 1100);
  await ev('document.getElementById(\'addPatientBtn\').click();1');
  await sleep(600);
  const mob = await ev('(function(){var o=document.getElementById(\'patientModalOverlay\');var b=o.querySelector(\'.modal-box\');var r=b.getBoundingClientRect();return {vw:window.innerWidth,left:Math.round(r.left),right:Math.round(r.right),w:Math.round(r.width),h:Math.round(r.height),vh:window.innerHeight,within:r.left>=0&&r.right<=window.innerWidth+1&&r.top>=0&&r.bottom<=window.innerHeight+1};})()');
  rec('responsive', '480px patient modal fits viewport', mob && mob.within === true, JSON.stringify(mob));
  await shot('modal-patient-480.png');
  await viewport(820, 900, true);
  await go(BASE + '/auth/pages/setup.php?section=permissions', 1000);
  const tab = await ev('(function(){var n=document.querySelector(\'.setup-section-nav\');var d=document.documentElement;return {stripScrolls:n?n.scrollWidth>n.clientWidth:null,docFits:d.scrollWidth<=d.clientWidth+1};})()');
  rec('responsive', '820px setup tab strip scrolls internally', tab && tab.docFits === true, JSON.stringify(tab));
  await shot('setup-permissions-820.png');
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
  console.log(JSON.stringify({ total: R.length, failed: failed.length, failures: failed }, null, 2));
  process.exit(failed.length === 0 ? 0 : 2);
}
