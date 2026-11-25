<?php
/* ==========================================================================
   SZYBKI WPIS GŁOSEM – Kama mówi jedno zdanie po rozmowie telefonicznej,
   panel wypełnia formularz „Dodaj wizytę”. Rozpoznawanie mowy robi przeglądarka
   (Chrome / Safari, pl-PL), resztę – prosty parser poniżej. Nic nie zapisuje się
   samo: Kama sprawdza pola i klika „Dodaj wizytę”.
   ========================================================================== */
function voice_box(): string
{
    return <<<'H'
<div class="qv" id="qv">
  <div class="qv-top">
    <b>🎤 Szybki wpis</b>
    <small><b>Przytrzymaj mikrofon i mów</b> – puść, gdy skończysz. Kolejne nagranie dopisuje brakujące dane, 🗑 czyści wszystko. Możesz też wpisać jednym zdaniem, np. <i>„wtorek jedenasta, shih tzu Fafik, pani Anna, 600 100 200, pełna pielęgnacja”</i></small>
  </div>
  <div class="qv-row">
    <button type="button" class="qv-mic" id="qvMic" aria-label="Przytrzymaj i mów"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg></button>
    <textarea id="qvTxt" rows="2" placeholder="np. 24.11 9:00 york Bella, pani Kasia 600100200"></textarea>
    <button type="button" class="qv-clr" id="qvClr" aria-label="Wyczyść i zacznij od nowa" title="Wyczyść i zacznij od nowa"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/></svg></button>
    <button type="button" class="btn sm" id="qvGo">Wypełnij</button>
  </div>
  <div class="qv-out" id="qvOut" aria-live="polite"></div>
</div>
<style>
.qv{border:1px solid var(--gold);border-radius:14px;padding:12px 14px;margin:6px 0 16px;background:linear-gradient(135deg,rgba(207,167,94,.12),rgba(207,167,94,.03))}
.qv-top b{color:var(--hi);font-size:1rem}.qv-top small{display:block;color:var(--muted);font-size:.8rem;margin-top:2px}
.qv-row{display:flex;gap:8px;align-items:stretch;margin-top:10px}
.qv-row textarea{flex:1;min-width:0;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--ink);color:var(--cream);font:inherit;font-size:16px;resize:vertical}
.qv-row .btn{align-self:center}
.qv-mic{flex:0 0 auto;width:64px;min-height:64px;touch-action:none;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;border-radius:12px;border:0;background:linear-gradient(135deg,#9a7232,var(--gold));color:var(--ink);cursor:pointer;display:grid;place-items:center}
.qv-mic svg{width:26px;height:26px}
.qv-clr{flex:0 0 auto;width:46px;border-radius:12px;border:1px solid var(--line);background:transparent;color:var(--muted);cursor:pointer;display:grid;place-items:center}.qv-clr:hover{color:#f0a0a0;border-color:#c0392b}.qv-clr svg{width:20px;height:20px}
.qv-mic.rec{background:#c0392b;color:#fff;transform:scale(1.08);animation:qvp 1.2s infinite}
@keyframes qvp{0%{box-shadow:0 0 0 0 rgba(192,57,43,.6)}100%{box-shadow:0 0 0 14px rgba(192,57,43,0)}}
.qv-out{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.qv-out:empty{display:none}
.qv-out span{font-size:.78rem;padding:3px 9px;border-radius:999px;background:rgba(98,180,120,.16);color:#9fe0b0}
.qv-out span.miss{background:rgba(231,160,80,.14);color:#f0c27a}
.qv-out p{flex-basis:100%;margin:2px 0 0;font-size:.8rem;color:var(--muted)}
#addf .qv-hl{box-shadow:0 0 0 2px rgba(98,180,120,.7)}
@media(max-width:560px){.qv-row{flex-wrap:wrap}.qv-row textarea{flex-basis:calc(100% - 126px)}.qv-row .btn{width:100%}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
const f=document.getElementById('addf'),B=window.__ADD_B||{breeds:[],services:[]};
const txt=document.getElementById('qvTxt'),mic=document.getElementById('qvMic'),out=document.getElementById('qvOut');
const nr=s=>String(s||'').toLowerCase().replace(/ł/g,'l').normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/[^a-z0-9:.\/+-]+/g,' ').replace(/\s+/g,' ').trim();
const pad=n=>String(n).padStart(2,'0');
const iso=d=>d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());
const DN=['niedz','pon','wt','sr','czw','pt','sob'];
const MON=['stycz','lut','mar','kwie','maj','czerw','lip','sierp','wrze','pazdzier','listopad','grud'];
const HW={'dziewiat':9,'dziesiat':10,'jedenast':11,'dwunast':12,'trzynast':13,'czternast':14,'pietnast':15,'szesnast':16,'siedemnast':17,'osiemnast':18,'osm':8,'siodm':7};
const MW={'pietnascie':15,'trzydziesci':30,'czterdziesci piec':45,'czterdziesci':40,'dwadziescia':20,'dziesiec':10,'piecdziesiat':50};
function parse(raw){
  let s=' '+nr(raw)+' ';const r={},used=[];const eat=m=>{s=s.replace(m,' ')};
  const today=new Date();today.setHours(0,0,0,0);
  /* telefon: 9 cyfr (także „600 100 200”, „+48…”) */
  const ph=[...s.matchAll(/(?<!\d)(?:\+?48[\s-]?)?((?:\d[\s-]?){8}\d)(?!\d)/g)];
  if(ph.length){const x=ph[ph.length-1],d=x[1].replace(/\D/g,'');r.phone=d.replace(/(\d{3})(\d{3})(\d{3})/,'$1 $2 $3');eat(x[0])}
  /* data */
  let m;
  if(/ (dzis|dzisiaj) /.test(s)){r.date=new Date(today);eat(/ (dzis|dzisiaj) /)}
  else if(/ pojutrze /.test(s)){r.date=new Date(today);r.date.setDate(r.date.getDate()+2);eat(/ pojutrze /)}
  else if(/ jutro /.test(s)){r.date=new Date(today);r.date.setDate(r.date.getDate()+1);eat(/ jutro /)}
  if(!r.date&&(m=s.match(/ (\d{1,2})[.\/-](\d{1,2})(?:[.\/-](\d{2,4}))? /))&&+m[2]>=1&&+m[2]<=12){
    let y=m[3]?+m[3]:today.getFullYear();if(y<100)y+=2000;const d=new Date(y,+m[2]-1,+m[1]);if(!m[3]&&d<today)d.setFullYear(y+1);r.date=d;eat(m[0])}
  if(!r.date&&(m=s.match(new RegExp(' (\\d{1,2})(?:-?go|-ego)? ('+MON.join('|')+')[a-z]* '))) ){
    const mi=MON.findIndex(x=>m[2].startsWith(x));const d=new Date(today.getFullYear(),mi,+m[1]);if(d<today)d.setFullYear(d.getFullYear()+1);r.date=d;eat(m[0])}
  if(!r.date&&(m=s.match(/ (?:w |we |na )?(?:przyszl[a-z]* )?(poniedzial|wtor|srod|czwart|piat|sobot|niedziel)[a-z]* /))){
    const w={poniedzial:1,wtor:2,srod:3,czwart:4,piat:5,sobot:6,niedziel:0}[m[1]];const d=new Date(today);let k=(w-d.getDay()+7)%7;if(k===0)k=7;d.setDate(d.getDate()+k);r.date=d;eat(m[0])}
  /* godzina */
  if((m=s.match(/ (?:o |na |godz[a-z]* )?(\d{1,2})[:.](\d{2}) /))&&+m[1]<24&&+m[2]<60){r.time=pad(+m[1])+':'+m[2];eat(m[0])}
  else if((m=s.match(/ (?:o |na |godz[a-z]* )(\d{1,2})(?: (\d{2}))? /))&&+m[1]<24){let h=+m[1];if(h<8)h+=12;r.time=pad(h)+':'+(m[2]&&+m[2]<60?m[2]:'00');eat(m[0])}
  else{for(const [w,h] of Object.entries(HW)){const re=new RegExp(' (?:o |na )?'+w+'[a-z]*( (?:'+Object.keys(MW).join('|')+'))? ');const x=s.match(re);if(x){const mm=x[1]?MW[x[1].trim()]:0;r.time=pad(h)+':'+pad(mm||0);eat(x[0]);break}}}
  if(!r.time&&(m=s.match(/ (\d{1,2}) (\d{2}) /))&&+m[1]>=7&&+m[1]<=19&&+m[2]<60){r.time=pad(+m[1])+':'+m[2];eat(m[0])}
  /* wielkość, sierść */
  if(/ (duz[a-z]*) /.test(s))r.size='big';else if(/ (sredni|srednia|sredniej|sredniego) (?!siers)/.test(s)&&!/ sredni[a-z]* siersc/.test(s))r.size='medium';else if(/ (mal[aey]|maly|malego|malutk[a-z]*) /.test(s))r.size='small';
  if(/krotk[a-z]*wlos|krotk[a-z]* siersc/.test(s))r.coat=0;else if(/sredni[a-z]* siersc/.test(s))r.coat=1;else if(/dlug[a-z]*wlos|dlug[a-z]* siersc|puchat/.test(s))r.coat=2;
  /* rasa – najdłuższe dopasowanie nazwy albo aliasu z cennika */
  let best=null,bl=0,bpos=-1,bstr='';
  const GEN=/wlos|siersc|puchat|^mal|^sred|^duz|^kg$|^\d/;
  for(const b of B.breeds){const nm=nr(b.n).replace(/ (maly|sredni|duzy)$/,'').replace(/ [-–]$/,'').trim();
    const keys=[[nm,100],...String(b.a||'').split(/\s+/).map(nr).filter(a=>a.length>=4&&!GEN.test(a)).map(a=>[a,0])];
    for(const [k,w] of keys){if(!k)continue;const i=s.indexOf(' '+k+' ')>=0?s.indexOf(' '+k+' '):s.indexOf(' '+k);if(i>=0&&k.length+w>bl){best=b;bl=k.length+w;bpos=i;bstr=k}}}
  if(best&&best.g){const mem=B.breeds.filter(x=>x.g===best.g);best=mem.find(x=>x.s===(r.size||'small'))||mem[0]}
  if(best){r.breed=best.n;
    /* imię psa: słowo zaraz po rasie (jeśli nie jest słowem kluczowym) */
    let after=s.slice(bpos+1+bstr.length).replace(/^[a-z]*/,'').trim().split(' ');
    while(after.length&&/^(mal|sred|duz|krot|dlug|puch|siersc|wlos|kundel|mieszan|i|z)/.test(after[0]))after.shift();
    const STOP=/^(pani|pan|o|na|w|we|i|telefon|numer|tel|pelna|pelne|kapiel|spa|pazur|szczen|mal|sred|duz|krot|dlug|puch|siersc|godz|jutro|dzis|pies|piesek|suczka|z|do|ok|kg|tzu|terrier)/;
    if(after[0]&&/^[a-z]{3,}$/.test(after[0])&&!STOP.test(after[0]))r.pet=after[0];}
  if(!r.pet&&(m=s.match(/ (?:pies|piesek|suczka|sunia|imie psa) ([a-z]{3,}) /)))r.pet=m[1];
  /* usługa */
  const has=re=>re.test(s);let svc=null;
  if(has(/pazur/))svc='nails';if(has(/szczeni|adaptac/))svc='puppy';if(has(/kapiel/))svc='bath';
  if(has(/ spa |ozon|hydromasaz/))svc=svc||'spa';
  if(has(/peln|strzyz|komplek|trymow|pielegnac/))svc='full';
  if(svc==='full'&&has(/ spa |ozon|hydromasaz/))r.addon=true;
  if(svc&&B.services.some(x=>x.k===svc))r.svc=svc;
  /* klientka / klient */
  if((m=s.match(/ (?:pani|pan|klientka|klient) ([a-z]{3,})(?: ([a-z]{3,}))? /))){let n=m[1];if(m[2]&&!/^(telefon|numer|tel|na|o|pies|piesek|suczka|i|z)$/.test(m[2]))n+=' '+m[2];r.name=n}
  return r}
const cap=s=>s.replace(/(^|[\s-])(\p{L})/gu,(a,b,c)=>b+c.toUpperCase());
/* imiona wracamy z oryginalnym zapisem (polskie znaki) */
function orig(raw,plain){if(!plain)return'';const words=raw.split(/\s+/);const pw=plain.split(' ');for(let i=0;i<words.length;i++){if(nr(words[i])===pw[0]){return cap(words.slice(i,i+pw.length).join(' ').replace(/[,.;:!?]+$/,''))}}return cap(plain)}
function set(el,v){if(!el||v==null||v==='')return false;el.value=v;el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));el.classList.add('qv-hl');setTimeout(()=>el.classList.remove('qv-hl'),2500);return true}
function fill(){const raw=txt.value.trim();if(!raw)return;const r=parse(raw),ok=[],miss=[];
  const DNP=['niedziela','poniedziałek','wtorek','środa','czwartek','piątek','sobota'];
  if(r.date&&set(f.date,iso(r.date)))ok.push('📅 '+DNP[r.date.getDay()]+' '+r.date.getDate()+'.'+pad(r.date.getMonth()+1));else if(!f.date.value)miss.push('data');
  if(r.time&&set(f.time,r.time))ok.push('🕘 '+r.time);else if(!f.time.value)miss.push('godzina');
  if(r.size)set(f.size,r.size);
  if(r.breed&&set(f.breed,r.breed))ok.push('🐶 '+r.breed);else if(!f.breed.value)miss.push('rasa');
  if(r.coat!=null&&f.coat&&!document.getElementById('coatL').hidden&&f.coat.options[r.coat]){set(f.coat,String(r.coat));ok.push('✂️ '+f.coat.options[r.coat].text.split(' – ')[0])}
  if(r.svc&&set(f.svc,r.svc))ok.push('🛁 '+f.svc.options[f.svc.selectedIndex].text);
  if(f.addon){f.addon.checked=!!r.addon;f.addon.dispatchEvent(new Event('change',{bubbles:true}));if(r.addon)ok.push('+ SPA')}
  if(r.pet&&set(f.pet,orig(raw,r.pet)))ok.push('🐾 '+f.pet.value);
  if(r.name&&set(f.name,orig(raw,r.name)))ok.push('👤 '+f.name.value);else if(!f.name.value)miss.push('imię klienta');
  if(r.phone&&set(f.phone,r.phone))ok.push('📞 '+r.phone);else if(!f.phone.value)miss.push('telefon');
  out.innerHTML=ok.map(x=>'<span>'+x.replace(/</g,'&lt;')+'</span>').join('')+miss.map(x=>'<span class="miss">brak: '+x+'</span>').join('')+'<p>Sprawdź pola poniżej i kliknij „Dodaj wizytę”.</p>'}
document.getElementById('qvGo').onclick=fill;
document.getElementById('qvClr').onclick=()=>{txt.value='';out.innerHTML='';const keep={csrf:1,a:1,back:1};
  [...f.elements].forEach(el=>{if(!el.name||keep[el.name]||el.type==='hidden')return;if(el.type==='checkbox')el.checked=false;else if(el.tagName==='SELECT')el.selectedIndex=0;else el.value=''});
  f.size&&f.size.dispatchEvent(new Event('change',{bubbles:true}));const h=document.getElementById('cliHint');if(h)h.innerHTML='';const dh=document.getElementById('durHint');if(dh)dh.textContent='';f.dur&&(f.dur.value='');txt.focus()};
txt.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();fill()}});
const SR=window.SpeechRecognition||window.webkitSpeechRecognition;let rec=null,on=false,held=false,base='',said='';
/* nagrywanie tylko gdy przycisk jest PRZYTRZYMANY (jak w walkie-talkie) – puszczenie = koniec i wypełnienie formularza */
function startRec(){
  if(!SR){txt.focus();out.innerHTML='<p>Ta przeglądarka nie nagrywa mowy – kliknij w pole tekstowe i użyj mikrofonu 🎤 na klawiaturze telefonu, potem „Wypełnij”.</p>';return}
  if(on)return;rec=new SR();rec.lang='pl-PL';rec.interimResults=true;rec.continuous=true;rec.maxAlternatives=1;
  rec.onresult=e=>{let t='';for(const x of e.results)t+=x[0].transcript;said=t;txt.value=(base?base+' ':'')+t};
  rec.onerror=e=>{if(e.error==='aborted'||e.error==='no-speech')return;held=false;out.innerHTML='<p>'+(e.error==='not-allowed'||e.error==='service-not-allowed'?'Zezwól przeglądarce na mikrofon (ikona kłódki przy adresie strony) i spróbuj ponownie.':'Nie udało się nagrać ('+e.error+'). Możesz też wpisać tekst.')+'</p>'};
  rec.onend=()=>{on=false;
    if(held){base=txt.value.trim();said='';try{rec.start();on=true;return}catch(_){}} /* telefon sam przerwał – nagrywamy dalej, póki palec trzyma */
    mic.classList.remove('rec');if(txt.value.trim())fill();else out.innerHTML='<p>Nic nie usłyszałem – przytrzymaj mikrofon i mów.</p>'};
  try{rec.start();on=true}catch(_){return}
  mic.classList.add('rec');out.innerHTML='<p>🔴 Słucham… mów, trzymając przycisk. Puść, gdy skończysz.</p>'}
function stopRec(){if(!held)return;held=false;if(rec&&on)setTimeout(()=>{try{rec.stop()}catch(_){}},250)}  /* 0,25 s zapasu na ostatnie słowo */
mic.addEventListener('pointerdown',e=>{e.preventDefault();try{mic.setPointerCapture(e.pointerId)}catch(_){}held=true;base=txt.value.trim();startRec()});
['pointerup','pointercancel','lostpointercapture'].forEach(ev=>mic.addEventListener(ev,stopRec));
mic.addEventListener('contextmenu',e=>e.preventDefault());
mic.addEventListener('keydown',e=>{if((e.key===' '||e.key==='Enter')&&!e.repeat){e.preventDefault();held=true;base=txt.value.trim();startRec()}});
mic.addEventListener('keyup',e=>{if(e.key===' '||e.key==='Enter'){e.preventDefault();stopRec()}});
window.__qvParse=parse;
});
</script>
H;
}
