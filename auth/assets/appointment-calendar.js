(function () {
    'use strict';
    const pad = n => String(n).padStart(2, '0');
    const iso = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
    const parse = s => new Date(s + 'T00:00:00');
    const today = () => new Intl.DateTimeFormat('en-CA', {timeZone:'Africa/Mogadishu',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
    const names = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    function schedule(option) {
        return {days:(option?.dataset.days || '').split(',').map(Number).filter(n=>n>=1&&n<=7), start:option?.dataset.start || '', end:option?.dataset.end || '', booked:JSON.parse(option?.dataset.booked || '[]')};
    }
    function availableDay(day, info) { return info.days.includes(parse(day).getDay() || 7) && !!info.start && !!info.end; }
    window.TdcAvailability = {schedule, availableDay};
    window.TdcAppointmentCalendar = function(field, doctor, root, hint, interval, existingSlot='', existingDoctor='') {
        if (field.appointmentCalendar) return field.appointmentCalendar;
        field.type='hidden'; field.removeAttribute('list');
        let date=field.value.slice(0,10), time=field.value.slice(11,16), month=parse(date || today()); month.setDate(1);
        const form=field.form;
        function slots(day) {
            const info=schedule(doctor.selectedOptions[0]);
            if(!doctor.value || !availableDay(day,info)) return [];
            const minutes=s=>Number(s.slice(0,2))*60+Number(s.slice(3,5));
            const result=[];
            // The server currently accepts a start exactly at closing time.
            for(let m=minutes(info.start);m<=minutes(info.end);m+=interval){
                const clock=pad(Math.floor(m/60))+':'+pad(m%60), value=day+'T'+clock;
                const stamp=Date.parse(value+':00+03:00');
                const own=value===existingSlot && doctor.value===existingDoctor;
                const booked=info.booked.some(b=> !(own && b===existingSlot) && Math.abs(Date.parse(b+':00+03:00')-stamp)<interval*60000);
                result.push({clock,value,booked,disabled:stamp<Date.now() || booked});
            }
            return result;
        }
        function valid() { return !!date && !!time && slots(date).some(s=>s.clock===time&&!s.disabled); }
        function sync(){ field.value=date&&time?date+'T'+time:''; field.setAttribute('value',field.value); field.dataset.selectedDate=date; field.dataset.selectedTime=time; }
        function button(text, action, disabled=false){const b=document.createElement('button');b.type='button';b.className='btn btn-secondary';b.textContent=text;b.disabled=disabled;b.addEventListener('click',action);return b;}
        function render(){
            sync(); root.replaceChildren(); const info=schedule(doctor.selectedOptions[0]);
            const configured=doctor.value&&info.days.length&&info.start&&info.end;
            hint.textContent=!doctor.value?'Choose a doctor to see available days and hours.':!configured?'Availability not configured':info.days.map(n=>names[n%7]).join(', ')+' · '+info.start+'–'+info.end;
            const header=document.createElement('div');header.className='appointment-calendar-header';
            const title=document.createElement('strong');title.textContent=month.toLocaleDateString('en-GB',{month:'long',year:'numeric'});
            const prev=button('‹',()=>{month.setMonth(month.getMonth()-1);render();},!configured);prev.setAttribute('aria-label','Previous month');
            const next=button('›',()=>{month.setMonth(month.getMonth()+1);render();},!configured);next.setAttribute('aria-label','Next month');header.append(prev,title,next);root.append(header);
            const selected=document.createElement('p');selected.setAttribute('aria-live','polite');selected.textContent='Selected appointment date: '+(date?parse(date).toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'}):'Not selected');root.append(selected);
            const grid=document.createElement('div');grid.className='appointment-calendar-grid';names.forEach(n=>{const s=document.createElement('span');s.textContent=n;grid.append(s);});
            const offset=month.getDay();
            for(let i=0;i<42;i++){
                const day=new Date(month.getFullYear(),month.getMonth(),i-offset+1), key=iso(day);
                const enabled=configured&&key>=today()&&slots(key).some(s=>!s.disabled);
                const b=button(String(day.getDate()),()=>{date=key;time='';month=new Date(day.getFullYear(),day.getMonth(),1);render();},!enabled);
                b.className='appointment-calendar-day';b.dataset.date=key;b.setAttribute('aria-label',day.toLocaleDateString('en-GB',{dateStyle:'full'}));b.setAttribute('aria-pressed',String(date===key));
                b.classList.toggle('is-selected',date===key);b.classList.toggle('is-today',key===today());b.classList.toggle('is-other-month',day.getMonth()!==month.getMonth());grid.append(b);
            }root.append(grid);
            const label=document.createElement('p');label.textContent=date?'Available times for '+date:'Select an available date to see times.';root.append(label);
            const times=document.createElement('div');times.className='appointment-calendar-slots';
            if(date) slots(date).forEach(s=>{const b=button(s.clock+(s.booked?' — Booked':''),()=>{time=s.clock;render();},s.disabled);b.dataset.time=s.clock;b.setAttribute('aria-pressed',String(time===s.clock));b.classList.toggle('is-selected',time===s.clock);times.append(b);});root.append(times);
            const summary=document.createElement('p');summary.setAttribute('aria-live','polite');summary.textContent='Selected time: '+(time||'Not selected')+(date&&time?' · Appointment: '+date+' at '+time:'');root.append(summary);
        }
        function refresh(){
            const incoming=field.value;
            if(incoming!== (date&&time?date+'T'+time:'')){date=incoming.slice(0,10);time=incoming.slice(11,16);}
            if(date&&!slots(date).some(s=>!s.disabled)){date='';time='';}
            if(time&&!valid())time=''; render();
        }
        doctor.addEventListener('change',refresh);
        form.addEventListener('submit',event=>{
            if(field.required&&!valid()){
                event.preventDefault();event.stopImmediatePropagation();hint.textContent='Select an available appointment date and time.';root.scrollIntoView({block:'center'});root.querySelector('button:not(:disabled)')?.focus();
            }
        },true);
        form.addEventListener('reset',()=>setTimeout(()=>{date='';time='';field.value='';month=parse(today());month.setDate(1);refresh();},0));
        function load(edit){
            if(edit){existingSlot=field.value;existingDoctor=doctor.value;}
            date=field.value.slice(0,10);time=field.value.slice(11,16);month=parse(date||today());month.setDate(1);refresh();
        }
        field.appointmentCalendar={refresh,render,load};render();return field.appointmentCalendar;
    };
})();
