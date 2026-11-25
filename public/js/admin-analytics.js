(() => {
    const source = document.getElementById('analytics-data');
    if (!source) return;
    const {data, start, end} = JSON.parse(source.textContent);
    const palette = getComputedStyle(document.body);
    const color = name => palette.getPropertyValue(name).trim();
    const statusColors = {pending:color('--status-pending'), approved:color('--status-approved'), rejected:color('--status-rejected'), completed:color('--status-completed'), cancelled:color('--status-cancelled')};
    const methodNames = {gcash:'GCash', card:'Credit/Debit Card', bank_transfer:'Bank Transfer'};
    const methodColors = {gcash:color('--method-gcash'), card:color('--method-card'), bank_transfer:color('--method-bank-transfer')};
    const resourceColors = ['#24784F', '#D6A32D', '#4685AA', '#389C96', '#8B7BA5'];
    function resourceColor(name) {
        let hash = 2166136261;
        for (const character of name) hash = Math.imul(hash ^ character.codePointAt(0), 16777619);
        return resourceColors[(hash >>> 0) % resourceColors.length];
    }
    const font = {family:'Poppins, sans-serif', size:11, weight:'400'};
    const peso = value => '₱' + Number(value).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    function dateLabel(value, includeYear) {
        const [year, month, day] = value.split('-').map(Number);
        return new Intl.DateTimeFormat('en-US', {month:'short', day:'numeric', ...(includeYear ? {year:'numeric'} : {}), timeZone:'UTC'}).format(new Date(Date.UTC(year, month - 1, day)));
    }
    function show(id, type, labels, values, options = {}) {
        const canvas = document.getElementById('chart-' + id);
        if (!values.some(value => Number(value) > 0) || typeof Chart === 'undefined') {
            canvas.hidden = true;
            const message = document.getElementById('empty-' + id);
            message.textContent = typeof Chart === 'undefined' ? 'Charts are unavailable. Check the chart library connection.' : 'No data for the selected period.';
            message.hidden = false;
            return;
        }
        const circular = !!options.circular;
        const nonzero = values.filter(value => Number(value) > 0).length;
        const years = new Set(options.dates ? labels.map(label => label.slice(0, 4)) : []);
        const max = Math.max(...values.map(Number));
        const countTicks = {precision:0, ...(max <= 5 ? {stepSize:1} : {}), font};
        const grid = {color:'rgba(23,74,53,.10)', drawBorder:false};
        const dataset = {label:options.label || '', data:values, backgroundColor:options.colors || '#23804c', borderColor:circular ? 'transparent' : '#174a35', borderWidth:circular ? 0 : (type === 'line' ? 2.5 : 0), fill:false};
        if (type === 'line') Object.assign(dataset,{tension:.35,cubicInterpolationMode:'monotone',pointRadius:2.5,pointHoverRadius:5,pointBackgroundColor:'#174a35',borderCapStyle:'round',borderJoinStyle:'round'});
        if (type === 'bar') Object.assign(dataset,{borderRadius:5,maxBarThickness:28});
        if (circular) Object.assign(dataset,{borderRadius:nonzero > 1 ? 3 : 0,spacing:nonzero > 1 ? 2 : 0});
        const scales = circular ? {} : {
            x: options.horizontal ? {beginAtZero:true,grace:'5%',ticks:countTicks,grid} : {grid:{display:false},ticks:{font,maxRotation:0,minRotation:0,autoSkip:true,maxTicksLimit:labels.length <= 7 ? 7 : 9,callback:function(value){const label=this.getLabelForValue(value);return options.dates ? dateLabel(label,years.size>1) : label;}}},
            y: options.horizontal ? {grid:{display:false},ticks:{font:{...font,size:12},autoSkip:false}} : {beginAtZero:true,grace:options.money ? 0 : '5%',grid,ticks:options.money ? {font,callback:value=>'₱'+Number(value).toLocaleString('en-PH')} : countTicks}
        };
        new Chart(canvas,{type,data:{labels,datasets:[dataset]},options:{responsive:true,maintainAspectRatio:false,animation:false,indexAxis:options.horizontal?'y':'x',cutout:circular?'68%':undefined,scales,plugins:{legend:{display:circular,position:'bottom',align:'center',labels:{usePointStyle:true,pointStyle:'circle',pointStyleWidth:11,boxWidth:11,boxHeight:11,padding:18,font:{...font,size:12}}},tooltip:{titleFont:{...font,size:12},bodyFont:{...font,size:12},callbacks:{title:items=>options.dates ? dateLabel(items[0].label,true) : items[0].label,label:context=>{const n=Number(context.raw);if(circular){const total=context.dataset.data.reduce((a,b)=>a+Number(b),0);return `${context.label}: ${n} (${(100*n/total).toFixed(1)}%)`;}return options.money ? peso(n) : `${n} ${options.label || ''}`.trim();}}}}}});
    }
    function series(rows) {
        const values = Object.fromEntries(rows.map(row => [row.day, Number(row.total)]));
        const first = start || (rows.length ? rows[0].day : null);
        if (!first) return [[],[]];
        const dates=[]; const date=new Date(first+'T00:00:00Z'); const last=new Date(end+'T00:00:00Z');
        while(date<=last){const key=date.toISOString().slice(0,10);dates.push(key);date.setUTCDate(date.getUTCDate()+1);}
        return [dates,dates.map(day=>values[day]||0)];
    }
    const [dates,counts]=series(data.reservations); show('trend','line',dates,counts,{label:'reservations',dates:true});
    const statuses=Object.keys(data.status); show('status','doughnut',statuses.map(s=>s[0].toUpperCase()+s.slice(1)),Object.values(data.status),{circular:true,colors:statuses.map(s=>statusColors[s]||'#7b7185')});
    show('resources','bar',data.resources.map(r=>r.name),data.resources.map(r=>Number(r.total)),{horizontal:true,label:'reservations',colors:data.resources.map(r=>resourceColor(r.name))});
    const [paidDates,amounts]=series(data.collections); show('collections','bar',paidDates,amounts,{money:true,dates:true,label:'PHP collected'});
    show('methods','doughnut',data.methods.map(r=>methodNames[r.payment_method]||r.payment_method),data.methods.map(r=>Number(r.total)),{circular:true,colors:data.methods.map(r=>methodColors[r.payment_method]||'#7b7185')});
})();
