import {spawn} from 'node:child_process';
import {readFile,writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve,join} from 'node:path';
import {pathToFileURL} from 'node:url';
import {request as httpRequest} from 'node:http';
const port=9368,base=`http://127.0.0.1:${port}/auth/pages/`;
const results=[],snapshots=[];
const check=(name,ok,detail='')=>{results.push({name,ok,detail});console.log(`${ok?'PASS':'FAIL'} HTTP ${name} ${detail}`);};
const server=spawn(process.env.TDC_TEST_PHP,['-S',`127.0.0.1:${port}`,'scripts/ui_http_router.php'],{stdio:'ignore',env:process.env});
const request=async(path,user='superuser',options={})=>{
 const headers={'X-TDC-Test-Secret':process.env.TDC_TEST_SECRET,'X-TDC-Test-User':user,Connection:'close',...options.headers};
 let body;
 if(options.body){const encoded=new Request(base+path,{method:'POST',body:options.body});body=Buffer.from(await encoded.arrayBuffer());headers['Content-Type']=encoded.headers.get('content-type');headers['Content-Length']=body.length;}
 return new Promise((resolve,reject)=>{const req=httpRequest(base+path,{method:options.method||'GET',headers,agent:false},res=>{const chunks=[];res.on('data',c=>chunks.push(c));res.on('end',()=>resolve({status:res.statusCode,headers:{get:key=>res.headers[key.toLowerCase()]},text:async()=>Buffer.concat(chunks).toString('utf8')}));});req.on('error',reject);req.end(body);});
};
function csv(text){const rows=[];let row=[],field='',quoted=false;text=text.replace(/^\uFEFF/,'');for(let i=0;i<text.length;i++){const c=text[i];if(c==='"'){if(quoted&&text[i+1]==='"'){field+='"';i++;}else quoted=!quoted;}else if(c===','&&!quoted){row.push(field);field='';}else if(c==='\n'&&!quoted){row.push(field.replace(/\r$/,''));rows.push(row);row=[];field='';}else field+=c;}if(field||row.length){row.push(field);rows.push(row);}if(quoted)throw Error('Unterminated CSV field');return rows;}
const reportNames=['patients','visits','doctor-consultations','billing','payments','outstanding','pharmacy-sales','pharmacy-purchases','pharmacy-stock','pharmacy-low-stock','pharmacy-expiry','lab-orders','lab-completed','lab-pending','lab-revenue','revenue-by-account','expenses','transactions','income-statement','balance-sheet'];
async function page(name,path,user='superuser'){
 const r=await request(path,user),body=await r.text();check(name+' page',r.status===200&&!/Fatal error|Warning:|Parse error/.test(body),`status=${r.status}`);
 const assets=pathToFileURL(resolve('auth/assets')).href+'/';await writeFile(join(tmpdir(),`tdc-ui-${name}.html`),body.replaceAll('../assets/',assets).replaceAll('../uploads/',pathToFileURL(resolve('auth/uploads')).href+'/'));snapshots.push(name);return body;
}
async function download(path,expected,user='superuser',headerRow=0){
 const r=await request(path,user),body=await r.text(),rows=csv(body),disposition=r.headers.get('content-disposition')||'';
 check(path+' '+user,r.status===200&&/text\/csv/i.test(r.headers.get('content-type')||'')&&/attachment;\s*filename="[a-z0-9][a-z0-9._-]+\.csv"/i.test(disposition)&&body.length>10&&!/<(?:html|br|b)>|Warning:|Fatal error/.test(body)&&(!expected||JSON.stringify(rows[headerRow])===JSON.stringify(expected)),`status=${r.status}; ${disposition}; rows=${rows.length}`);return {body,rows};
}
try{
 for(let i=0;i<70;i++){try{await request('home.php');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 const templates=[
 ['doctors.php?download=doctor-template',['doctor_name','specialization','consultation_fee','joined_date']],
 ['patients.php?download=patient-template',['patient_name','phone','address','gender','date_of_birth','patient_type','doctor_id','remark']],
 ['setup.php?section=users&download=user-template',['legal_name','username','role_key','doctor_id','is_active','temporary_password']],
 ['doctors.php?download=doctors',['doctor_id','doctor_name','specialization','consultation_fee','joined_date','linked_username']],
 ['patients.php?download=patients',['patient_id','patient_name','phone','address','gender','date_of_birth','patient_type','doctor_id','visit_count','due_balance','registered_at']],
 ['setup.php?section=users&download=users',['legal_name','username','role_key','doctor_id','is_active','last_login_at']],
 ['reception.php?section=consultations&export=csv',['Appointment','Patient','Gender','Age','Phone','Doctor','Visit Date','Queue Status','Payment Status','Fee','Paid','Balance']],
 ['pharmacy.php?section=purchases&export=csv',['PO Ref','Reference','Supplier','Phone','Items','Total','Paid','Due','Status','Purchase Date']]
 ];
 for(const [path,headers] of templates)await download(path,headers);
 await download('doctors.php?workspace=1&export=csv',['Visit','Patient','Gender','Age','Phone','Date Added','Doctor','Status'],'doctoruser');
 for(const role of ['pharmacyuser']){
  const {body}=await download('pharmacy.php?section=purchases&export=csv',['PO Ref','Supplier','Phone','Reference','Items','Date'],role);
  check(role+' purchase privacy',!/(cost|total|amount|discount|vat|paid|due)/i.test(csv(body)[0].join(',')));
  const denied=await request('reports.php?section=pharmacy-purchases&export=csv',role);check(role+' purchase report denied',denied.status===403);
 }
 for(const path of ['doctors.php?download=doctors','patients.php?download=patients','setup.php?section=users&download=users']){const r=await request(path,'doctoruser');check('unauthorized '+path,!r.headers.get('content-disposition')&&(r.status===403||(path.startsWith('doctors')&&r.status===200)));}
 for(const section of reportNames){
 const html=await page('report-'+section,'reports.php?section='+section);
  if(section==='income-statement') check('income statement uses printable paper markup',html.includes('income-statement-preview')&&html.includes('Pharmacy Gross Profit')&&html.includes('Drug Sold')&&html.includes('Drug Cost')&&html.includes('Net Profit or Loss')&&html.includes('window.print()'));
  if(section==='balance-sheet') check('balance sheet uses printable paper markup',html.includes('income-statement-preview')&&html.includes('aria-label="Balance Sheet"')&&html.includes('Assets')&&html.includes('Liabilities')&&html.includes('Equity')&&html.includes('Total Liabilities &amp; Equity')&&html.includes('income-report-status')&&html.includes('section-toolbar no-print'));
  if(section==='payments') check('payment report uses confirmed payment ledger',html.includes('Payment Reference')&&html.includes('Payment Method')&&html.includes('PAY'));
  if(section==='pharmacy-stock'||section==='pharmacy-low-stock'||section==='pharmacy-expiry') check(section+' identifies current snapshot',html.includes('Current inventory snapshot')&&!html.includes('rr_'+section.replaceAll('-','_')+'_from'));
  const headers=[...html.matchAll(/<th\b[^>]*>([\s\S]*?)<\/th>/g)].map(m=>m[1].replace(/<[^>]*>/g,'').trim().replaceAll('&amp;','&'));
  const legacy=['income-statement','balance-sheet'].includes(section);
 const data=await download('reports.php?section='+section+'&export=csv',legacy?['Type','Account','Amount']:headers,'superuser',legacy?2:1);
 check(section+' CSV rows match header',legacy||data.rows.slice(1).every(row=>row.length===headers.length));
 }
 const receptionIncomeCsv=await download('reports.php?section=income-statement&export=csv',['Type','Account','Amount'],'receptionuser',2);
 check('reception income CSV omits confidential COGS',!/(Drug Cost|Pharmacy Gross Profit|Net Income)/i.test(receptionIncomeCsv.body));
 for(const section of ['','organization','users','roles','permissions','clinical','laboratory','pharmacy','payment-methods','audit'])await page('setup-'+(section||'overview'),'setup.php?section='+section);
 const login=await request('../auth.php','missing-user');const loginHtml=await login.text();
 check('login page',login.status===200&&loginHtml.includes('loginForm'));
 await writeFile(join(tmpdir(),'tdc-ui-login.html'),loginHtml.replaceAll('href="assets/',`href="${pathToFileURL(resolve('auth/assets')).href}/`).replaceAll('src="uploads/',`src="${pathToFileURL(resolve('auth/uploads')).href}/`));snapshots.push('login');
 for(const [name,path,user] of [['patients','patients.php'],['clinical','doctors.php?workspace=1&visit=1','doctoruser'],['consultations','reception.php?section=consultations'],['lab-billing','reception.php?section=laboratory'],['pharmacy-billing','reception.php?section=pharmacy'],['prescriptions','pharmacy.php?section=prescriptions'],['accounts','accounting.php?section=accounts']])await page(name,path,user);
 const accountList=await page('accounts-list','accounting.php?section=accounts');
 const accountDetail=await page('account-detail-pay-cash','accounting.php?section=accounts&view=PAY-CASH');
 check('PAY-CASH view renders account detail',accountDetail.includes('Cash Clearing')&&accountDetail.includes('PAY-CASH')&&accountDetail.includes('Account Activity')&&!accountDetail.includes('Chart of Accounts</div>'));
 const revenueDetail=await page('account-detail-revenue','accounting.php?section=accounts&view=REV-CONSULT');
 check('REV-CONSULT view renders account detail',revenueDetail.includes('Consultation Revenue')&&revenueDetail.includes('REV-CONSULT')&&revenueDetail.includes('Account Activity'));
 const invalidDetail=await page('account-detail-invalid','accounting.php?section=accounts&view=DOES-NOT-EXIST');
 check('Invalid account view is handled',invalidDetail.includes('Account not found')&&invalidDetail.includes('Back to Chart of Accounts')&&!invalidDetail.includes('No accounts found'));
 check('Account list remains separate from detail',accountList.includes('Chart of Accounts')&&!accountList.includes('Account Activity'));
 const accountCsv=await download('accounting.php?section=accounts&view=PAY-CASH&export=csv',['Date','Reference','Description','Book','Debit','Credit','Running Balance','Status']);
 const imports=[
 ['doctors.php','form_action','import_csv','doctor_name,specialization,consultation_fee,joined_date\nHTTP Imported Doctor,Dermatology,25,2026-01-01\n','doctors.php?download=doctors','HTTP Imported Doctor'],
 ['patients.php','form_action','import_csv','patient_name,phone,gender,date_of_birth,patient_type\nHTTP Imported Patient,999000123,Male,2000-01-01,\n','patients.php?download=patients','HTTP Imported Patient'],
 ['setup.php?section=users','setup_action','import_users','legal_name,username,role_key,temporary_password\nHTTP Imported User,http_import_user,labuser,DisposableTest123!\n','setup.php?section=users&download=users','http_import_user']
 ];
 for(const [path,key,action,content,exportPath,marker] of imports){
  const form=new FormData();form.set(key,action);form.set('csrf_token','http-test-token');form.set('csv_file',new Blob([content],{type:'text/csv'}),'test.csv');
  const r=await request(path,'superuser',{method:'POST',body:form});const response=await r.text();check('multipart import '+path,r.status===302,`status=${r.status}`+(r.status!==302?response.match(/<div class="error-msg"[\s\S]*?<\/div>/)?.[0]:''));
  const exported=await download(exportPath);check('import persisted '+path,exported.body.includes(marker));
  if(path.startsWith('setup'))check('password excluded',!exported.body.includes('DisposableTest123!')&&!exported.body.includes('password'));
  const bad=new FormData();bad.set(key,action);bad.set('csrf_token','http-test-token');bad.set('csv_file',new Blob(['wrong\nvalue\n']),'invalid.csv');
  const invalid=await request(path,'superuser',{method:'POST',body:bad});check('invalid CSV rejected '+path,invalid.status===200&&(await invalid.text()).includes('is required'));
 }
 for(const role of ['superuser','receptionuser','pharmacyuser']){
  const html=await (await request('pharmacy.php?section=inventory',role)).text();
  const tag=[...html.matchAll(/<button[^>]*class="[^"]*edit-item-btn[\s\S]*?<\/button>/g)].map(m=>m[0]).find(t=>t.includes('Unit regression '+role));
  const id=tag?.match(/data-id="([^"]+)"/)?.[1];
  check(role+' medicine edit record available',!!id);
  if(id){const form=new FormData();for(const [key,value] of Object.entries({csrf_token:'http-test-token',form_action:'save',ItemID:id,ItemName:'Unit regression '+role,Category:'Medicine',QuantityInStock:'3',SalesUnit:'Custom legacy unit',SellingPrice:'4.25',ReorderLevel:'2',ExpiryDate:'2028-01-01'}))form.set(key,value);
   const saved=await request('pharmacy.php?section=inventory',role,{method:'POST',body:form});check(role+' medicine update HTTP',saved.status===302);
   const updated=await (await request('pharmacy.php?section=inventory',role)).text();
   const row=[...updated.matchAll(/<button[^>]*class="[^"]*edit-item-btn[\s\S]*?<\/button>/g)].map(m=>m[0]).find(t=>t.includes('data-id="'+id+'"'))||'';
   check(role+' medicine edit persisted',row.includes('data-unit="Custom legacy unit"')&&row.includes('data-price="4.25"')&&row.includes('data-stock="3"'));
  }
 }
 for(const path of ['pharmacy.php','laboratory.php']){const allowed=await request(path,'receptionuser');check('reception operational '+path,allowed.status===200&&!(await allowed.text()).match(/Fatal error|Warning:|Access denied/));}
 const posDefault=await request('pharmacy.php?section=pos','superuser');const posDefaultHtml=await posDefault.text();check('POS defaults to New Sale workspace',posDefault.status===200&&posDefaultHtml.includes('Create a new pharmacy sale.')&&posDefaultHtml.includes('Complete Sale')&&posDefaultHtml.includes('Sales History'));
 const posHistory=await request('pharmacy.php?section=pos&view_mode=history','superuser');const posHistoryHtml=await posHistory.text();check('POS Sales History view works',posHistory.status===200&&posHistoryHtml.includes('Sales History')&&posHistoryHtml.includes('Sale Ref')&&posHistoryHtml.includes('Search by customer or ref'));
 for(const section of ['consultations','laboratory','pharmacy']){const billing=await request('reception.php?section='+section,'receptionuser');check('reception billing '+section,billing.status===200&&!(await billing.text()).match(/Fatal error|Warning:|Access denied/));}
 const receptionConsultations=await request('reception.php?section=consultations','receptionuser');check('reception consultation payment uses configured methods',receptionConsultations.status===200&&(await receptionConsultations.text()).includes('EVC Plus'));
 const receipt=await request('pharmacy.php?section=pos&view=POS999999','superuser');const receiptHtml=await receipt.text();const medicalTable=(receiptHtml.match(/<table class="prescription-table">[\s\S]*?<\/table>/i)||[''])[0];check('POS receipt paper preview loads',receipt.status===200&&receiptHtml.includes('class="receipt-paper"')&&!/Fatal error|Warning:|Access denied/.test(receiptHtml));check('POS receipt uses clinic letterhead',receiptHtml.includes('tareydermacliniclogo.png')&&/Tarey Derma Clinic/i.test(receiptHtml));check('POS receipt uses clinical columns and hides purchase cost',medicalTable.includes('Frequency')&&medicalTable.includes('Route')&&!medicalTable.includes('Unit Price')&&!medicalTable.includes('Amount')&&!receiptHtml.includes('Purchase Price'));check('POS receipt resolves prescription and doctor details',receiptHtml.includes('PNo:')&&receiptHtml.includes('RX999999')&&receiptHtml.includes('BID')&&receiptHtml.includes('Topical'));
 const logout=await request('home.php?logout=1&csrf=http-test-token');check('logout redirect',logout.status===302&&/login|auth|index/i.test(logout.headers.get('location')||''));
 const anon=await request('home.php','missing-user');check('unauthenticated redirect',anon.status===302);
}finally{
 server.kill();await writeFile('scripts/ui-http-test-results.json',JSON.stringify(results,null,2)+'\n');await writeFile(join(tmpdir(),'tdc-ui-http-snapshots.json'),JSON.stringify(snapshots));
}
console.log(`${results.length} HTTP checks; ${results.filter(r=>!r.ok).length} failed.`);if(results.some(r=>!r.ok))process.exitCode=1;
