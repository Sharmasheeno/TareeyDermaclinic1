(() => {
    const trigger = document.querySelector('.profile-trigger');
    const panel = document.getElementById('profile-panel');
    if (!trigger || !panel) return;
    const close = () => { panel.hidden = true; trigger.setAttribute('aria-expanded', 'false'); };
    trigger.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        trigger.setAttribute('aria-expanded', String(!panel.hidden));
        document.querySelectorAll('[data-menu].open').forEach(item => item.classList.remove('open'));
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('.profile-area')) close();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !panel.hidden) { close(); trigger.focus(); }
    });
    trigger.addEventListener('keydown', event => {
        if (event.key !== 'ArrowDown') return;
        event.preventDefault(); panel.hidden = false;
        trigger.setAttribute('aria-expanded', 'true'); panel.querySelector('a')?.focus();
    });
    document.addEventListener('focusin', event => {
        if (!event.target.closest('.profile-area')) close();
    });
    document.querySelectorAll('[data-menu] .icon-btn').forEach(button => {
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('title', button.getAttribute('aria-label') || 'Notifications');
        new MutationObserver(() => button.setAttribute('aria-expanded', String(button.parentElement.classList.contains('open'))))
            .observe(button.parentElement, { attributes: true, attributeFilter: ['class'] });
    });
})();

document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (event.defaultPrevented) return;
    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
    }
    if (!form.noValidate && !form.checkValidity()) return;
    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');
    window.setTimeout(() => {
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(control => {
            control.disabled = true;
        });
    }, 0);
});

// Shared payment guard. The server remains authoritative; this only keeps
// the UI aligned with the current outstanding balance and prevents accidental
// overpayment during typing, paste, wheel, and live total changes.
(() => {
    const selectors = 'input[name="PaymentAmount"], input[name="AmountPaid"], input[name="AppointmentAmountPaid"], input[name="PaidAmount"], input[name="amount_paid"], input[name="payment_amount"], input[data-payment-amount]';
    const money = value => Math.round((Number(value) || 0) * 100) / 100;
    const readDisplay = id => { const node = document.getElementById(id); if (!node) return null; const n = Number(String(node.textContent || node.value || '').replace(/[^0-9.-]/g, '')); return Number.isFinite(n) ? money(n) : null; };
    const dueFor = input => {
        const explicit = input.dataset.currentDue;
        if (explicit !== undefined && explicit !== '') return money(explicit);
        const displayIds = input.id === 'sf_AmountPaid' ? ['sf_FinalTotalDisplay'] : input.id === 'pof_AmountPaid' ? ['pof_NetDisplay'] : [];
        for (const id of displayIds) { const due = readDisplay(id); if (due !== null) return due; }
        const max = input.getAttribute('max');
        return max === null || max === '' ? null : money(max);
    };
    const showError = (input, due) => {
        let error = input.parentElement?.querySelector('.tdc-payment-error');
        if (!error) { error = document.createElement('small'); error.className = 'tdc-payment-error'; input.parentElement?.appendChild(error); }
        error.textContent = `Payment cannot exceed the current outstanding balance of ${due.toFixed(2)}.`; input.classList.add('tdc-payment-invalid'); input.setCustomValidity(`Payment cannot exceed the current outstanding balance of ${due.toFixed(2)}.`);
    };
    const clearError = input => { input.classList.remove('tdc-payment-invalid'); input.setCustomValidity(''); input.parentElement?.querySelector('.tdc-payment-error')?.remove(); };
    const showLimit = (input, due) => {
        let helper = input.parentElement?.querySelector('.tdc-payment-limit');
        if (!helper) { helper = document.createElement('small'); helper.className = 'tdc-payment-limit'; input.parentElement?.appendChild(helper); }
        helper.textContent = `Maximum payable: ${due.toFixed(2)}`;
    };
    const bind = input => {
        if (input.dataset.paymentGuardReady === 'true') return;
        input.dataset.paymentGuardReady = 'true'; input.dataset.paymentAmount = 'true';
        input.type = 'number'; input.step = '0.01'; if (!['AmountPaid', 'AppointmentAmountPaid', 'PaymentAmount'].includes(input.name)) input.min = '0.01';
        const setUnifiedSubmit = disabled => { const submit = input.form?.querySelector('.unified-submit'); if (submit) submit.disabled = disabled; };
        const syncLimit = () => { const waived = input.name === 'AppointmentAmountPaid' && input.form?.querySelector('[name="AppointmentFreeConsultation"]')?.checked; if (waived) { showLimit(input, 0); const helper = input.parentElement?.querySelector('.tdc-payment-limit'); if (helper) helper.textContent = 'Free consultation — no payment required.'; input.value = '0'; input.disabled = true; clearError(input); return; } const due = dueFor(input); if (due === null) return; const max = due.toFixed(2); if (input.max !== max) input.max = max; if (due <= 0) { showLimit(input, due); const helper = input.parentElement?.querySelector('.tdc-payment-limit'); if (helper) helper.textContent = 'Bill is fully paid.'; input.value = ''; input.disabled = true; input.placeholder = 'Bill is fully paid.'; setUnifiedSubmit(true); clearError(input); return; } showLimit(input, due); input.disabled = false; input.removeAttribute('readonly'); const raw = String(input.value ?? '').trim(); const value = raw === '' ? null : Number(raw); if (value !== null && Number.isFinite(value) && value > due) { showError(input, due); setUnifiedSubmit(true); } else if (value === null || (Number.isFinite(value) && value >= 0 && value <= due)) { clearError(input); setUnifiedSubmit(false); } };
        const guard = (enforce = false) => { const due = dueFor(input); if (due === null) return; const raw = String(input.value ?? '').trim(); if (raw === '' || raw === '.' || raw.endsWith('.')) { if (enforce && raw === '.') input.value = ''; return; } const value = Number(raw); if (!Number.isFinite(value)) return; if (value > due) { showError(input, due); setUnifiedSubmit(true); } else if (value >= 0 && value <= due) { clearError(input); setUnifiedSubmit(false); } };
        input.addEventListener('input', () => guard(false)); input.addEventListener('change', () => guard(true)); input.addEventListener('blur', () => guard(true)); input.addEventListener('paste', () => window.setTimeout(() => guard(true), 0)); input.addEventListener('wheel', () => window.setTimeout(() => guard(true), 0), { passive: true });
        input.form?.addEventListener('submit', event => { guard(true); if (input.validity.customError || input.validity.rangeOverflow) { event.preventDefault(); input.reportValidity(); } });
        syncLimit();
        new MutationObserver(syncLimit).observe(input, { attributes: true, attributeFilter: ['max', 'data-current-due'] });
        const display = input.id === 'sf_AmountPaid' ? document.getElementById('sf_FinalTotalDisplay') : input.id === 'pof_AmountPaid' ? document.getElementById('pof_NetDisplay') : null;
        if (display) new MutationObserver(syncLimit).observe(display, { childList: true, characterData: true, subtree: true });
    };
    const init = root => root.querySelectorAll?.(selectors).forEach(bind);
    init(document); new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => { if (node.nodeType === 1) { if (node.matches?.(selectors)) bind(node); init(node); } }))).observe(document.body, { childList: true, subtree: true });
})();

// Shared search clear interaction; query/filter semantics stay server-side.
document.addEventListener('click', event => {
    const clear = event.target.closest('[data-search-clear]'); if (!clear) return;
    const input = clear.closest('.tdc-search')?.querySelector('input'); if (!input) return;
    const form = input.form; if (!form) { input.value = ''; input.focus(); return; }
    const url = new URL(form.action || window.location.href, window.location.href); url.searchParams.delete(input.name); window.location.assign(url.toString());
});

// One Doctor action contract; only the requested fragment enters the live DOM.
(() => {
    const modal = document.getElementById('doctorActionModal');
    const content = document.getElementById('doctorActionModalContent');
    if (!modal || !content) return;
    let request = 0;
    function removeTemplates(root) {
        root.querySelectorAll('.doctor-modal-panels, .doctor-history-modal').forEach(node => node.remove());
    }
    removeTemplates(document);
    function updateHeader(action) {
        const shell = modal.querySelector('.doctor-action-modal-shell');
        if (!shell) return;
        shell.dataset.modalSize = ['history', 'results'].includes(action) ? 'wide' : action === 'lab' ? 'large' : 'medium';
        const title = modal.querySelector('[data-doctor-modal-title]');
        const subtitle = modal.querySelector('[data-doctor-modal-subtitle]');
        const labels = {
            history: { title: 'Medical History', subtitle: 'Previous visits, orders, prescriptions, and results' },
            lab: { title: 'Request Lab Test', subtitle: 'Select a test from the clinical catalogue' },
            prescription: { title: 'Create Prescription', subtitle: 'Send prescribed items to the pharmacy queue' },
            results: { title: 'Lab Results', subtitle: 'Current patient result history and review data' },
            edit_lab: { title: 'Edit Lab Request', subtitle: 'Adjust selected tests and notes' },
            edit_prescription: { title: 'Edit Prescription', subtitle: 'Adjust medicine entries before dispense' }
        };
        const label = labels[action] || { title: 'Doctor action', subtitle: 'Patient activity' };
        if (title) title.textContent = label.title;
        if (subtitle) subtitle.textContent = label.subtitle;
    }
    function close() {
        request++;
        window.TDCModal.close(modal);
        content.replaceChildren();
    }
    document.addEventListener('click', async event => {
        if (event.target.closest('[data-close-doctor-modal]')) { close(); return; }
        const button = event.target.closest('[data-doctor-action]');
        if (!button) return;
        const action = button.dataset.doctorAction;
        if (action === 'call') return;
        const selectors = {
            history: '#doctorHistoryModal',
            results: '#doctorResultsModal',
            lab: '#doctorLabRequestForm',
            prescription: '#prescriptionForm',
            consultation: '.clinical-work-panel',
            edit_lab: '#doctorLabEditForm',
            edit_prescription: '#doctorPrescriptionEditForm'
        };
        if (!selectors[action]) return;
        event.preventDefault();
        const current = ++request;
        const visit = button.dataset.visitId;
        const patient = button.dataset.patientId;
        button.disabled = true;
        try {
            const url = new URL('doctors.php', location.href);
            url.searchParams.set('workspace', '1');
            url.searchParams.set('visit', visit);
            if (action === 'edit_lab' || action === 'edit_prescription') {
                url.searchParams.set(action, button.dataset.reference);
            }
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'text/html' }
            });
            if (!response.ok) throw new Error('Unable to load the selected visit.');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const context = page.querySelector('.clinical-record-form');
            if (context?.querySelector('[name="VisitID"]')?.value !== visit || context?.querySelector('[name="PatientID"]')?.value !== patient) {
                throw new Error('This patient and visit are not available to your doctor account.');
            }
            const fragment = page.querySelector(selectors[action]);
            if (!fragment) throw new Error('The requested form could not be loaded.');
            if (current !== request) return;
            fragment.querySelectorAll('form').forEach(form => {
                form.action = url.pathname + url.search;
            });
            if (fragment.matches('form')) {
                fragment.action = url.pathname + url.search;
            }
            if (action === 'consultation') {
                removeTemplates(fragment);
                document.getElementById('doctorSelectedEncounter')?.remove();
                const inline = document.getElementById('doctorInlineEncounter');
                inline.replaceChildren(fragment);
                window.tdcInitUi?.(inline);
                inline.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }
            fragment.classList.remove('doctor-history-modal', 'is-open');
            fragment.style.display = 'block';
            fragment.querySelectorAll('[data-close-doctor-modal]').forEach(node => node.remove());
            content.replaceChildren(fragment);
            updateHeader(action);
            window.initDoctorPrescriptionStaging?.(content);
            window.initDoctorLabStaging?.(content);
            window.initDoctorPrescriptionEditStaging?.(content);
            window.tdcInitUi?.(content);
            window.TDCModal.open(modal, button);
        } catch (error) {
            if (current !== request) return;
            const message = document.createElement('p');
            message.setAttribute('role', 'alert');
            message.textContent = error.message;
            content.replaceChildren(message);
            updateHeader('history');
            window.TDCModal.open(modal, button);
        } finally {
            button.disabled = false;
        }
    });
})();

// Prescription forms are fetched into the doctor action modal. Keep Add Item
// reliable even when a fragment arrives before its page-level initializer.
function initDoctorPrescriptionStaging(root = document) {
    const form = root.matches?.('#prescriptionForm') ? root : root.querySelector?.('#prescriptionForm');
    if (!form || form.dataset.prescriptionStagingReady === 'true') return form;
    const medication = form.querySelector('#rxMedication');
    const quantity = form.querySelector('#rxQuantity');
    const frequency = form.querySelector('#rxFrequency');
    const duration = form.querySelector('#rxDuration');
    const route = form.querySelector('#rxRoute');
    const instructions = form.querySelector('#rxInstructions');
    const add = form.querySelector('#addPrescriptionItem');
    const update = form.querySelector('#updatePrescriptionItem');
    const cancel = form.querySelector('#cancelPrescriptionEdit');
    const body = form.querySelector('#prescriptionItemsBody');
    const count = form.querySelector('#rxItemCount');
    const empty = form.querySelector('#rxEmptyItems');
    const hidden = form.querySelector('#prescriptionStagedInputs');
    const error = form.querySelector('#prescriptionValidation');
    const meta = form.querySelector('#rxMedicationMeta');
    if (!medication || !quantity || !frequency || !duration || !route || !instructions || !add || !update || !cancel || !body || !count || !empty || !hidden || !error || !meta) return form;

    const items = [];
    let editing = -1;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const setError = message => { error.textContent = message || ''; error.hidden = !message; };
    const syncMeta = () => { const option = medication.options[medication.selectedIndex]; if (!option?.value) { meta.textContent = ''; quantity.removeAttribute('max'); return; } quantity.max = option.dataset.stock || ''; meta.textContent = `${option.dataset.stock || 0} ${option.dataset.unit || 'units'} available · ${Number(option.dataset.price || 0).toFixed(2)} each`; };
    const clear = focus => { medication.value = ''; quantity.value = '1'; frequency.value = ''; duration.value = ''; route.value = ''; instructions.value = ''; editing = -1; add.hidden = false; update.hidden = true; cancel.hidden = true; syncMeta(); if (focus) medication.focus(); };
    const render = () => { body.replaceChildren(); if (!items.length) { empty.hidden = false; body.appendChild(empty); } else { empty.hidden = true; items.forEach((item, index) => { const row = document.createElement('tr'); row.innerHTML = `<td>${index + 1}</td><td><span class="rx-medication-name">${esc(item.medication)}</span>${item.instructions ? `<span class="rx-instruction">${esc(item.instructions)}</span>` : ''}</td><td>${esc(item.quantity)}</td><td>${esc(item.frequency || '—')}</td><td>${esc(item.duration || '—')}</td><td>${esc(item.route || '—')}</td><td><div class="rx-item-actions"><button type="button" class="btn-secondary btn-sm" data-rx-edit="${index}">Edit</button><button type="button" class="btn-danger btn-sm" data-rx-remove="${index}">Remove</button></div></td>`; body.appendChild(row); }); } count.textContent = `(${items.length})`; };
    const stage = () => { const option = medication.options[medication.selectedIndex]; const name = medication.value.trim(); const qty = Number(quantity.value); if (!option?.value || !name) { setError('Select a medication.'); return false; } if (!Number.isInteger(qty) || qty < 1) { setError('Quantity must be greater than zero.'); return false; } if (items.some((item, index) => index !== editing && item.medication.toLowerCase() === name.toLowerCase())) { setError('This medication has already been added.'); return false; } const item = { medication: name, quantity: qty, frequency: frequency.value.trim(), duration: duration.value.trim(), route: route.value, instructions: instructions.value.trim() }; if (editing >= 0) items[editing] = item; else items.push(item); setError(''); render(); clear(true); return true; };
    const writeHidden = () => { hidden.replaceChildren(); items.forEach(item => Object.entries({MedicationName:item.medication, Quantity:item.quantity, Frequency:item.frequency, Duration:item.duration, Route:item.route, Instructions:item.instructions}).forEach(([name, value]) => { const input = document.createElement('input'); input.type = 'hidden'; input.name = `${name}[]`; input.value = value; hidden.appendChild(input); })); };
    medication.addEventListener('change', syncMeta); add.addEventListener('click', stage); update.addEventListener('click', stage); cancel.addEventListener('click', () => clear(true));
    body.addEventListener('click', event => { const edit = event.target.closest('[data-rx-edit]'); const remove = event.target.closest('[data-rx-remove]'); const index = Number((edit || remove)?.dataset.rxEdit ?? (edit || remove)?.dataset.rxRemove); if (edit && items[index]) { editing = index; const item = items[index]; medication.value = item.medication; quantity.value = item.quantity; frequency.value = item.frequency; duration.value = item.duration; route.value = item.route; instructions.value = item.instructions; add.hidden = true; update.hidden = false; cancel.hidden = false; syncMeta(); medication.focus(); } else if (remove && Number.isInteger(index)) { items.splice(index, 1); if (editing === index) clear(false); else if (editing > index) editing -= 1; render(); } });
    form.addEventListener('submit', event => { if (editing >= 0) { event.preventDefault(); setError('Update or cancel the current item before submitting.'); return; } if (!items.length) { event.preventDefault(); setError('Add at least one medication before submitting the prescription.'); return; } const unsaved = [medication.value, frequency.value, duration.value, route.value, instructions.value].some(value => String(value).trim() !== '') || Number(quantity.value) !== 1; if (unsaved) { event.preventDefault(); setError('Click Add Item to add the current medication before submitting.'); return; } writeHidden(); });
    form.dataset.prescriptionStagingReady = 'true'; form._rxStageReady = true; form._rxStageCurrent = stage; render(); clear(false); return form;
}
window.initDoctorPrescriptionStaging = initDoctorPrescriptionStaging;

// The laboratory editor uses the same single-entry/staged-list contract as
// prescriptions. The catalogue is display-only; the server remains
// authoritative when the final form is posted.
function initDoctorLabStaging(root = document) {
    const form = root.matches?.('#doctorLabRequestForm') ? root : root.querySelector?.('#doctorLabRequestForm');
    const editForm = root.matches?.('#doctorLabEditForm') ? root : root.querySelector?.('#doctorLabEditForm');
    const activeForm = form || editForm;
    if (!activeForm || activeForm.dataset.labStagingReady === 'true') return activeForm;
    let catalog;
    try { catalog = JSON.parse(activeForm.dataset.labCatalog || '{}'); } catch { return activeForm; }
    const editor = activeForm.querySelector('[data-lab-editor]');
    const tableBody = activeForm.querySelector('[data-lab-staged-body]');
    const count = activeForm.querySelector('[data-lab-staged-count]');
    const hidden = activeForm.querySelector('[data-lab-staged-inputs]');
    const error = activeForm.querySelector('[data-lab-validation]');
    const category = activeForm.querySelector('[data-lab-category]');
    const type = activeForm.querySelector('[data-lab-type]');
    const test = activeForm.querySelector('[data-lab-test]');
    const add = activeForm.querySelector('[data-lab-stage]');
    const update = activeForm.querySelector('[data-lab-update]');
    const cancel = activeForm.querySelector('[data-lab-cancel]');
    const price = activeForm.querySelector('[data-lab-price]');
    const submit = activeForm.querySelector('#sendDoctorLabRequest, [data-lab-submit]');
    if (!editor || !tableBody || !count || !hidden || !error || !category || !type || !test || !add || !update || !cancel || !submit) return activeForm;
    // The cascade is an entry editor, not the submitted payload. Once an item
    // is staged it is intentionally reset to blank while the hidden item list
    // remains authoritative. Keep native form validation from blocking that
    // valid staged state; stage() still validates the selected test.
    [category, type, test].forEach(select => { select.required = false; });
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    let items = []; let editing = -1;
    try { items = JSON.parse(activeForm.dataset.initialTests || '[]').map(id => ({id:String(id)})); } catch {}
    const setError = message => { error.textContent = message || ''; error.hidden = !message; };
    const reset = (select, label) => { select.replaceChildren(new Option(label, '')); };
    const fillCategories = () => { reset(category, 'Select category'); (catalog.categories || []).forEach(item => category.appendChild(new Option(item.CategoryName, item.CategoryID))); };
    const refreshTypes = () => { reset(type, 'Select type'); reset(test, 'Select test'); type.disabled = !category.value; test.disabled = true; (catalog.types || []).filter(item => String(item.CategoryID) === String(category.value)).forEach(item => type.appendChild(new Option(item.TypeName, item.TypeID))); };
    const refreshTests = () => { reset(test, 'Select test'); test.disabled = !type.value; (catalog.tests || []).filter(item => String(item.TypeID) === String(type.value)).forEach(item => test.appendChild(new Option(`${item.TestName} · ${Number(item.Price || 0).toFixed(2)}`, item.TestID))); };
    const clear = focus => { category.value=''; refreshTypes(); editing=-1; add.hidden=false; update.hidden=true; cancel.hidden=true; if (focus) category.focus(); };
    const syncHidden = () => { hidden.replaceChildren(); items.forEach(item => { const input=document.createElement('input'); input.type='hidden'; input.name='SelectedModernTestID[]'; input.value=item.id; hidden.appendChild(input); }); };
    const render = () => { tableBody.replaceChildren(); let total=0; if (!items.length) { const row=document.createElement('tr'); row.innerHTML='<td colspan="4">No laboratory tests added yet.</td>'; tableBody.appendChild(row); } items.forEach((item,index)=>{ const data=(catalog.tests||[]).find(candidate=>String(candidate.TestID)===item.id); if(data) total+=Number(data.Price||0); const row=document.createElement('tr'); row.innerHTML=`<td>${index+1}</td><td>${esc(data?.TestName || 'Test '+item.id)}</td><td>${Number(data?.Price || 0).toFixed(2)}</td><td><button type="button" class="btn-secondary btn-sm" data-lab-edit="${index}">Edit</button> <button type="button" class="btn-danger btn-sm" data-lab-remove="${index}">Remove</button></td>`; tableBody.appendChild(row); }); count.textContent=`(${items.length})`; if(price) price.value=total.toFixed(2); submit.disabled=!items.length; syncHidden(); };
    const stage = () => { if(!test.value){setError('Select a laboratory test.');return;} if(items.some((item,index)=>index!==editing&&item.id===String(test.value))){setError('This test has already been added.');return;} const item={id:String(test.value)}; if(editing>=0)items[editing]=item;else items.push(item);setError('');render();clear(true); };
    fillCategories();
    category.addEventListener('change',refreshTypes); type.addEventListener('change',refreshTests); add.addEventListener('click',stage); update.addEventListener('click',stage); cancel.addEventListener('click',()=>clear(true));
    tableBody.addEventListener('click', event=>{ const edit=event.target.closest('[data-lab-edit]'); const remove=event.target.closest('[data-lab-remove]'); if(edit){ const index=Number(edit.dataset.labEdit), item=items[index], data=(catalog.tests||[]).find(candidate=>String(candidate.TestID)===item.id); if(!data)return; editing=index; category.value=String(data.CategoryID); refreshTypes(); type.value=String(data.TypeID); refreshTests(); test.value=item.id; add.hidden=true; update.hidden=false; cancel.hidden=false; category.focus(); } if(remove){items.splice(Number(remove.dataset.labRemove),1); if(editing===Number(remove.dataset.labRemove))clear(false); render();} });
    activeForm.addEventListener('submit', event=>{if(!items.length){event.preventDefault();setError('Add at least one laboratory test before submitting.');return;}if(editing>=0){event.preventDefault();setError('Update or cancel the current test before submitting.');return;}syncHidden();submit.disabled=true;submit.dataset.originalText=submit.textContent.trim();submit.textContent='Submitting...';});
    activeForm.dataset.labStagingReady='true'; render(); clear(false); return activeForm;
}
window.initDoctorLabStaging = initDoctorLabStaging;

function initDoctorPrescriptionEditStaging(root = document) {
    const form = root.matches?.('#doctorPrescriptionEditForm') ? root : root.querySelector?.('#doctorPrescriptionEditForm');
    if (!form || form.dataset.prescriptionEditStagingReady === 'true') return form;
    const editor=form.querySelector('[data-rx-edit-editor]'), body=form.querySelector('[data-rx-edit-body]'), hidden=form.querySelector('[data-rx-edit-inputs]'), error=form.querySelector('[data-rx-edit-validation]');
    const medication=form.querySelector('[data-rx-edit-medication]'), quantity=form.querySelector('[data-rx-edit-quantity]'), frequency=form.querySelector('[data-rx-edit-frequency]'), duration=form.querySelector('[data-rx-edit-duration]'), route=form.querySelector('[data-rx-edit-route]'), instructions=form.querySelector('[data-rx-edit-instructions]'), add=form.querySelector('[data-rx-edit-stage]'), update=form.querySelector('[data-rx-edit-update]'), cancel=form.querySelector('[data-rx-edit-cancel]'), count=form.querySelector('[data-rx-edit-count]');
    if(!editor||!body||!hidden||!error||!medication||!quantity||!frequency||!duration||!route||!instructions||!add||!update||!cancel||!count)return form;
    let items=[];let editing=-1;try{items=JSON.parse(form.dataset.initialItems||'[]');}catch{}
    const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));const setError=m=>{error.textContent=m||'';error.hidden=!m};const syncMeta=()=>{const option=medication.options[medication.selectedIndex];quantity.max=option?.dataset.stock||''};const clear=focus=>{medication.value='';quantity.value='1';frequency.value='';duration.value='';route.value='';instructions.value='';editing=-1;add.hidden=false;update.hidden=true;cancel.hidden=true;syncMeta();if(focus)medication.focus()};const render=()=>{body.replaceChildren();if(!items.length){const row=document.createElement('tr');row.innerHTML='<td colspan="7">No medications added yet.</td>';body.appendChild(row)}items.forEach((item,index)=>{const row=document.createElement('tr');row.innerHTML=`<td>${index+1}</td><td>${esc(item.medication)}${item.instructions?`<span class="rx-instruction">${esc(item.instructions)}</span>`:''}</td><td>${esc(item.quantity)}</td><td>${esc(item.frequency||'—')}</td><td>${esc(item.duration||'—')}</td><td>${esc(item.route||'—')}</td><td><button type="button" class="btn-secondary btn-sm" data-rx-edit-existing="${index}">Edit</button> <button type="button" class="btn-danger btn-sm" data-rx-remove-existing="${index}">Remove</button></td>`;body.appendChild(row)});count.textContent=`(${items.length})`};const stage=()=>{const name=medication.value.trim(),qty=Number(quantity.value);if(!name||!Number.isInteger(qty)||qty<1){setError('Select a medication and enter a positive quantity.');return}if(items.some((item,index)=>index!==editing&&item.medication.toLowerCase()===name.toLowerCase())){setError('This medication has already been added.');return}const item={id:editing>=0?items[editing].id:'',medication:name,quantity:qty,frequency:frequency.value.trim(),duration:duration.value.trim(),route:route.value,instructions:instructions.value.trim()};if(editing>=0)items[editing]=item;else items.push(item);setError('');render();clear(true)};const writeHidden=()=>{hidden.replaceChildren();items.forEach(item=>{Object.entries({ExistingPrescriptionID:item.id,MedicationName:item.medication,Quantity:item.quantity,Frequency:item.frequency,Duration:item.duration,Route:item.route,Instructions:item.instructions}).forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name+'[]';input.value=value;hidden.appendChild(input)})})};add.addEventListener('click',stage);update.addEventListener('click',stage);cancel.addEventListener('click',()=>clear(true));medication.addEventListener('change',syncMeta);body.addEventListener('click',event=>{const edit=event.target.closest('[data-rx-edit-existing]');const remove=event.target.closest('[data-rx-remove-existing]');if(edit){editing=Number(edit.dataset.rxEditExisting);const item=items[editing];medication.value=item.medication;quantity.value=item.quantity;frequency.value=item.frequency;duration.value=item.duration;route.value=item.route;instructions.value=item.instructions;add.hidden=true;update.hidden=false;cancel.hidden=false;syncMeta();medication.focus()}if(remove){items.splice(Number(remove.dataset.rxRemoveExisting),1);if(editing===Number(remove.dataset.rxRemoveExisting))clear(false);else if(editing>Number(remove.dataset.rxRemoveExisting))editing--;render()}});form.addEventListener('submit',event=>{if(!items.length||editing>=0){event.preventDefault();setError(items.length?'Update or cancel the current medication before submitting.':'Add at least one medication before submitting.');return}writeHidden()});form.dataset.prescriptionEditStagingReady='true';render();clear(false);return form;
}
window.initDoctorPrescriptionEditStaging=initDoctorPrescriptionEditStaging;

const initDoctorStagedEditors = () => { initDoctorLabStaging(document); initDoctorPrescriptionStaging(document); initDoctorPrescriptionEditStaging(document); };
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initDoctorStagedEditors, { once: true }); else initDoctorStagedEditors();

document.addEventListener('click', event => {
    const button = event.target.closest('#addPrescriptionItem');
    if (!button) return;
    const form = button.closest('#prescriptionForm');
    if (!form) return;
    const ready = form.dataset.prescriptionStagingReady === 'true';
    initDoctorPrescriptionStaging(form);
    if (!ready) form._rxStageCurrent?.();
});

/* ------------------------------------------------------------------
   Global UI layer: modal scroll-lock, Escape, focus trap, focus return.
   Runs for every page. Pages keep their own open/close helpers; this
   only adds the behaviour they are missing, and stays idempotent.
   ------------------------------------------------------------------ */
(() => {
    const OVERLAY = '.modal-overlay';
    const openOverlays = () => Array.from(document.querySelectorAll(OVERLAY + '.show, ' + OVERLAY + '.is-open'));
    const focusables = root => Array.from(root.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter(node => node.offsetParent !== null || node === document.activeElement);

    let lastFocus = null;

    const lock = () => {
        const open = openOverlays();
        document.body.classList.toggle('modal-open', open.length > 0);
        if (open.length === 0) {
            const target = lastFocus;
            lastFocus = null;
            if (target && document.body.contains(target)) target.focus({ preventScroll: true });
            return;
        }
        if (!lastFocus || !document.body.contains(lastFocus)) lastFocus = document.activeElement;
        const panel = open[open.length - 1].querySelector('.modal-box, [role="dialog"]');
        open[open.length - 1].removeAttribute('aria-hidden');
        panel?.removeAttribute('aria-hidden');
        if (panel && !panel.hasAttribute('role')) panel.setAttribute('role', 'dialog');
        if (panel && !panel.hasAttribute('aria-modal')) panel.setAttribute('aria-modal', 'true');
        if (panel && !panel.contains(document.activeElement)) {
            const items = focusables(panel);
            (items[0] || panel).focus?.({ preventScroll: true });
        }
    };

    const closeTop = overlay => {
        overlay.classList.remove('show', 'is-open');
        overlay.setAttribute('aria-hidden', 'true');
        const panel = overlay.querySelector('.modal-box');
        if (panel) panel.setAttribute('aria-hidden', 'true');
        overlay.dispatchEvent(new CustomEvent('tdc:modal-close'));
        if (openOverlays().length === 0) {
            document.body.classList.remove('modal-open');
            const target = lastFocus;
            lastFocus = null;
            if (target && document.body.contains(target)) target.focus({ preventScroll: true });
        }
    };

    window.TDCModal = {
        open(overlay, opener = document.activeElement) {
            if (!overlay) return;
            lastFocus = opener;
            overlay.classList.add('show');
            lock();
        },
        close: closeTop
    };

    window.TDCModal.confirm = (config = {}) => new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = '<div class="modal-box confirmation-dialog" role="dialog" aria-modal="true" aria-labelledby="confirmation-title" aria-describedby="confirmation-message"><div class="modal-head"><h3 id="confirmation-title"></h3><button type="button" class="modal-close" aria-label="Close" title="Close">×</button></div><div class="modal-body"><p id="confirmation-message"></p><div class="modal-actions"><button type="button" class="btn btn-secondary" data-cancel>Cancel</button><button type="button" data-confirm></button></div></div></div>';
        overlay.querySelector('h3').textContent = config.title || 'Confirm action';
        overlay.querySelector('p').textContent = config.message || 'Continue with this action?';
        const confirm = overlay.querySelector('[data-confirm]');
        confirm.className = 'btn ' + (config.destructive ? 'btn-danger' : 'btn-primary');
        confirm.textContent = config.confirmLabel || 'Confirm';
        let accepted = false;
        overlay.addEventListener('tdc:modal-close', () => { overlay.remove(); resolve(accepted); }, { once: true });
        overlay.querySelectorAll('[data-cancel], .modal-close').forEach(button => button.addEventListener('click', () => closeTop(overlay)));
        confirm.addEventListener('click', () => { accepted = true; closeTop(overlay); });
        document.body.append(overlay);
        window.TDCModal.open(overlay);
        overlay.querySelector('[data-cancel]').focus();
    });
    const confirmed = new WeakSet();
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) return;
        if (confirmed.delete(form)) return;
        event.preventDefault(); event.stopImmediatePropagation();
        const submitter = event.submitter;
        if (form.dataset.confirming) return;
        form.dataset.confirming = 'true';
        const accepted = await window.TDCModal.confirm({ message: form.dataset.confirm, destructive: true });
        delete form.dataset.confirming;
        if (accepted) { confirmed.add(form); form.requestSubmit(submitter); }
    }, true);

    new MutationObserver(lock).observe(document.body, {
        subtree: true, attributes: true, attributeFilter: ['class']
    });

    document.addEventListener('keydown', event => {
        const open = openOverlays();
        if (open.length === 0) return;

        if (event.key === 'Escape') {
            const top = open[open.length - 1];
            if (top.dataset.static === 'true') return;
            if (!document.body.classList.contains('modal-open')) return;
            event.preventDefault();
            closeTop(top);
            return;
        }

        if (event.key !== 'Tab') return;
        const panel = open[open.length - 1].querySelector('.modal-box');
        if (!panel) return;
        const items = focusables(panel);
        if (items.length === 0) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }, true);

    document.addEventListener('click', event => {
        const overlay = event.target.closest(OVERLAY);
        if (!overlay || event.target !== overlay) return;
        if (overlay.dataset.static === 'true') return;
        if (!document.body.classList.contains('modal-open')) return;
        closeTop(overlay);
    });
})();

(() => {
    const modalDefaults = {
        'category-modal': { action: 'lab_add_category', title: 'Add Category' },
        'type-modal': { action: 'lab_add_type', title: 'Add Lab Type' },
        'test-modal': { action: 'lab_add_test', title: 'Register Test' },
        'parameter-modal': { action: 'lab_add_parameter', title: 'Add Parameter' },
        'center-modal': { action: 'lab_add_center', title: 'Add Lab Center' },
        'unit-modal': { action: 'lab_add_unit', title: 'Add Unit' },
        'flag-modal': { action: 'lab_add_flag', title: 'Add Flag' }
    };

    const fillMap = {
        category: {
            modalId: 'category-modal',
            action: 'lab_update_category',
            title: 'Edit Category',
            fields: [
                ['CategoryID', 'category-id'],
                ['CategoryName', 'category-name'],
                ['Description', 'category-description'],
                ['DisplayOrder', 'category-order'],
                ['IsActive', 'category-active']
            ]
        },
        type: {
            modalId: 'type-modal',
            action: 'lab_update_type',
            title: 'Edit Lab Type',
            fields: [
                ['TypeID', 'type-id'],
                ['CategoryID', 'type-category'],
                ['TypeName', 'type-name'],
                ['Description', 'type-description'],
                ['DisplayOrder', 'type-order'],
                ['IsActive', 'type-active']
            ]
        },
        test: {
            modalId: 'test-modal',
            action: 'lab_update_test',
            title: 'Edit Test',
            fields: [
                ['TestID', 'test-id'],
                ['CategoryID', 'test-category'],
                ['TypeID', 'test-type'],
                ['TestName', 'test-name'],
                ['Description', 'test-description'],
                ['Price', 'test-price'],
                ['ResultMode', 'test-mode'],
                ['DisplayOrder', 'test-order'],
                ['IsActive', 'test-active']
            ]
        },
        parameter: {
            modalId: 'parameter-modal',
            action: 'lab_update_parameter',
            title: 'Edit Parameter',
            fields: [
                ['ParameterID', 'parameter-id'],
                ['TestID', 'parameter-test'],
                ['ParameterName', 'parameter-name'],
                ['ResultType', 'parameter-type'],
                ['UnitID', 'parameter-unit'],
                ['ReferenceRange', 'parameter-range'],
                ['NormalMinimum', 'parameter-min'],
                ['NormalMaximum', 'parameter-max'],
                ['DisplayOrder', 'parameter-order'],
                ['IsRequired', 'parameter-required'],
                ['IsActive', 'parameter-active'],
                ['SelectChoices', 'parameter-choices']
            ]
        },
        center: {
            modalId: 'center-modal',
            action: 'lab_update_center',
            title: 'Edit Lab Center',
            fields: [
                ['LabCenterID', 'center-id'],
                ['CenterName', 'center-name'],
                ['Location', 'center-location'],
                ['Phone', 'center-phone'],
                ['IsActive', 'center-active']
            ]
        },
        unit: {
            modalId: 'unit-modal',
            action: 'lab_update_unit',
            title: 'Edit Unit',
            fields: [
                ['UnitID', 'unit-id'],
                ['UnitName', 'unit-name'],
                ['UnitSymbol', 'unit-symbol'],
                ['IsActive', 'unit-active']
            ]
        },
        flag: {
            modalId: 'flag-modal',
            action: 'lab_update_flag',
            title: 'Edit Flag',
            fields: [
                ['FlagID', 'flag-id'],
                ['FlagName', 'flag-name'],
                ['FlagCode', 'flag-code'],
                ['Description', 'flag-description'],
                ['IsActive', 'flag-active']
            ]
        }
    };

    function setFieldValue(form, fieldName, rawValue) {
        if (!form) return;
        const field = form.elements[fieldName];
        if (!field) return;
        if (field.type === 'checkbox') {
            const isChecked = rawValue === true || rawValue === 1 || String(rawValue) === '1' || String(rawValue).toLowerCase() === 'true';
            field.checked = isChecked;
            return;
        }
        field.value = rawValue === null || rawValue === undefined ? '' : String(rawValue);
    }

    function syncCategoryTypeOptions(categoryField, typeField) {
        if (!categoryField || !typeField) return;
        const categoryId = String(categoryField.value || '');
        const options = Array.from(typeField.options);
        const placeholder = options.find(option => option.value === '');
        let selectedAllowed = false;

        options.forEach(function (option) {
            const matchesCategory = !categoryId || option.dataset.category === categoryId || option.getAttribute('data-category') === categoryId;
            const isPlaceholder = option.value === '';
            option.hidden = !isPlaceholder && !matchesCategory;
            option.disabled = !isPlaceholder && !matchesCategory;
            if (!isPlaceholder && matchesCategory && option.value === typeField.value) {
                selectedAllowed = true;
            }
        });

        typeField.disabled = !categoryId || options.filter(option => !option.hidden && option.value !== '').length === 0;

        if (!categoryId) {
            if (placeholder) {
                typeField.value = placeholder.value;
            }
            return;
        }

        if (!selectedAllowed || !options.some(option => !option.hidden && option.value === typeField.value)) {
            const fallback = options.find(option => !option.hidden && option.value !== '');
            typeField.value = fallback ? fallback.value : '';
        }
    }

    function syncLabTypeNameSuggestions() {
        const categoryField = document.getElementById('type-category');
        const list = document.getElementById('type-name-options');
        const hint = document.getElementById('type-category-hint');
        if (!categoryField || !list) return;
        const categoryId = String(categoryField.value || '');
        Array.from(list.options).forEach(option => {
            option.hidden = !!categoryId && option.dataset.category !== categoryId;
        });
        const selected = categoryField.selectedOptions?.[0]?.textContent?.trim() || '';
        if (hint) hint.textContent = selected ? 'Selected category: ' + selected + '. Enter a new type name or choose an existing suggestion.' : 'Choose one of the five laboratory categories.';
    }

    function setModalState(modal, mode, titleText, actionValue) {
        if (!modal) return;
        const form = modal.querySelector('form');
        if (form) {
            form.reset();
            const actionInput = form.querySelector('[name="workspace_action"]');
            if (actionInput) actionInput.value = actionValue || actionInput.value || 'save';
        }
        const title = modal.querySelector('.modal-head h3');
        if (title && titleText) title.textContent = titleText;
    }

    function fillModal(modalId, trigger, config) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        const form = modal.querySelector('form');
        if (!form) return;
        form.reset();
        const actionInput = form.querySelector('[name="workspace_action"]');
        if (actionInput) actionInput.value = config.action;
        const title = modal.querySelector('.modal-head h3');
        if (title) title.textContent = config.title;
        config.fields.forEach(([name, id]) => {
            const field = form.elements[name] || form.querySelector('[name="' + name + '"]');
            if (!field) return;
            const dataKey = id.replace(/-([a-z])/g, (_, char) => char.toUpperCase());
            const identityKey = 'fill' + modalId.replace('-modal', '').replace(/^./, c => c.toUpperCase());
            const rawValue = trigger.dataset[dataKey] ?? (id.endsWith('-id') ? trigger.dataset[identityKey] : undefined) ?? '';
            if (field.type === 'checkbox') {
                field.checked = String(rawValue) === '1' || rawValue === true || String(rawValue).toLowerCase() === 'true';
            } else {
                field.value = rawValue ?? '';
            }
        });
        const categoryField = form.elements['CategoryID'] || form.querySelector('#test-category');
        const typeField = form.elements['TypeID'] || form.querySelector('#test-type');
        if (categoryField && typeField) {
            syncCategoryTypeOptions(categoryField, typeField);
        }
        syncLabTypeNameSuggestions();
        if (typeof window.TDCModal !== 'undefined') {
            window.TDCModal.open(modal, trigger || document.activeElement);
        } else {
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const labTypeCategory = document.getElementById('type-category');
        labTypeCategory?.addEventListener('change', syncLabTypeNameSuggestions);
        syncLabTypeNameSuggestions();
        const categoryField = document.getElementById('test-category');
        const typeField = document.getElementById('test-type');
        if (categoryField && typeField) {
            syncCategoryTypeOptions(categoryField, typeField);
        }
    });

    document.addEventListener('change', function (event) {
        const categoryField = event.target.closest('#test-category');
        if (!categoryField) return;
        const typeField = document.getElementById('test-type');
        if (!typeField) return;
        syncCategoryTypeOptions(categoryField, typeField);
    });

    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-open-modal]');
        if (!trigger) return;
        const modal = document.getElementById(trigger.dataset.openModal);
        if (!modal) return;
        const config = modalDefaults[trigger.dataset.openModal] || { action: 'save', title: 'Add record' };
        setModalState(modal, 'add', config.title, config.action);
        if (typeof window.TDCModal !== 'undefined') {
            window.TDCModal.open(modal, trigger);
        } else {
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
        }
    });

    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-close-modal]');
        if (!trigger) return;
        const modal = document.getElementById(trigger.dataset.closeModal);
        if (!modal) return;
        if (typeof window.TDCModal !== 'undefined') {
            window.TDCModal.close(modal);
        } else {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
        }
    });

    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-fill-category]');
        if (trigger) { fillModal('category-modal', trigger, fillMap.category); return; }
        const typeTrigger = event.target.closest('[data-fill-type]');
        if (typeTrigger) { fillModal('type-modal', typeTrigger, fillMap.type); return; }
        const testTrigger = event.target.closest('[data-fill-test]');
        if (testTrigger) { fillModal('test-modal', testTrigger, fillMap.test); return; }
        const parameterTrigger = event.target.closest('[data-fill-parameter]');
        if (parameterTrigger) { fillModal('parameter-modal', parameterTrigger, fillMap.parameter); return; }
        const centerTrigger = event.target.closest('[data-fill-center]');
        if (centerTrigger) { fillModal('center-modal', centerTrigger, fillMap.center); return; }
        const unitTrigger = event.target.closest('[data-fill-unit]');
        if (unitTrigger) { fillModal('unit-modal', unitTrigger, fillMap.unit); return; }
        const flagTrigger = event.target.closest('[data-fill-flag]');
        if (flagTrigger) { fillModal('flag-modal', flagTrigger, fillMap.flag); }
    });
})();
/* =====================================================================
   Shared UI behaviors (auth/includes/ui.php components)
   Action menus, date-range validation, print, toast helpers.
   ===================================================================== */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    function closeAllMenus(except) {
        document.querySelectorAll('.action-menu.open').forEach(function (menu) {
            if (menu === except) return;
            menu.classList.remove('open');
            var trigger = menu.querySelector('.action-menu-trigger');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    }

    function initActionMenus(root) {
        (root || document).querySelectorAll('.action-menu').forEach(function (menu) {
            if (menu.dataset.bound === '1') return;
            menu.dataset.bound = '1';
            var trigger = menu.querySelector('.action-menu-trigger');
            if (!trigger) return;
            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var willOpen = !menu.classList.contains('open');
                closeAllMenus(menu);
                menu.classList.toggle('open', willOpen);
                trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
            menu.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    menu.classList.remove('open');
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.focus();
                }
            });
        });
    }

    document.addEventListener('click', function () { closeAllMenus(null); });

    function initDateRanges(root) {
        (root || document).querySelectorAll('[data-date-range]').forEach(function (form) {
            if (form.dataset.bound === '1') return;
            form.dataset.bound = '1';
            var from = form.querySelector('input[type="date"][name="from_date"]');
            var to = form.querySelector('input[type="date"][name="to_date"]');
            var error = form.querySelector('.date-range-error');
            if (!from || !to || !error) return;
            function validate() {
                var message = '';
                if (from.value && to.value && from.value > to.value) {
                    message = 'From Date must be on or before To Date.';
                }
                error.textContent = message;
                error.hidden = message === '';
                to.setCustomValidity(message);
                return message === '';
            }
            [from, to].forEach(function (input) {
                input.addEventListener('change', validate);
                input.addEventListener('input', validate);
            });
            form.addEventListener('submit', function (event) {
                if (!validate()) {
                    event.preventDefault();
                    error.scrollIntoView({ block: 'nearest' });
                }
            });
            validate();
        });
    }

    function initPrintButtons(root) {
        (root || document).querySelectorAll('[data-print-page]').forEach(function (button) {
            if (button.dataset.bound === '1') return;
            button.dataset.bound = '1';
            button.addEventListener('click', function () { window.print(); });
        });
    }

    function initDashboardMotion(root) {
        const scope = root || document;
        scope.querySelectorAll('.dashboard-metric strong').forEach(function (node) {
            if (node.dataset.motionBound === '1') return;
            const raw = node.textContent.trim();
            const match = raw.replace(/,/g, '').match(/^-?\d+(?:\.\d+)?$/);
            if (!match) return;
            node.dataset.motionBound = '1';
            const target = Number(match[0]);
            const decimals = (match[0].split('.')[1] || '').length;
            const start = performance.now();
            function tick(now) {
                const progress = Math.min(1, (now - start) / 850);
                const eased = 1 - Math.pow(1 - progress, 3);
                node.textContent = (target * eased).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
                if (progress < 1) requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
        });
    }

    window.tdcInitUi = function (root) {
        initActionMenus(root);
        initDateRanges(root);
        initPrintButtons(root);
        initDashboardMotion(root);
        (root || document).querySelectorAll('.form-group').forEach(function (group, index) {
            const label = group.querySelector('label');
            const input = group.querySelector('input:not([type="hidden"]), select, textarea');
            if (!label || !input || label.htmlFor || label.contains(input)) return;
            if (!input.id) {
                let id = 'tdc-field-' + index;
                while (document.getElementById(id)) id += '-field';
                input.id = id;
            }
            label.htmlFor = input.id;
        });
        (root || document).querySelectorAll('.modal-box').forEach(function (panel, index) {
            const title = panel.querySelector('.modal-head h2, .modal-head h3');
            if (!title || panel.hasAttribute('aria-labelledby')) return;
            if (!title.id) title.id = 'tdc-modal-title-' + index;
            panel.setAttribute('aria-labelledby', title.id);
        });
        (root || document).querySelectorAll('.modal-close, .btn-icon, .icon-action').forEach(function (button) {
            const label = button.getAttribute('aria-label') || button.getAttribute('title') || button.textContent.trim();
            if (!label) return;
            if (!button.hasAttribute('title')) button.setAttribute('title', label);
            if (!button.hasAttribute('aria-label')) button.setAttribute('aria-label', label);
        });
    };

    ready(function () { window.tdcInitUi(document); });

    if (window.MutationObserver) {
        var observer = new MutationObserver(function (mutations) {
            var touched = false;
            mutations.forEach(function (mutation) {
                if (mutation.addedNodes && mutation.addedNodes.length) touched = true;
            });
            if (touched) window.tdcInitUi(document);
        });
        ready(function () {
            observer.observe(document.body, { childList: true, subtree: true });
        });
    }
})();
