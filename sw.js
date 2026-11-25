const CACHE='kama-v35';
const ASSETS=["./", "index.html", "manifest.webmanifest", "ikony/icon-192.png", "ikony/icon-512.png", "ikony/apple-touch-icon.png", "img/logo-emblem.png", "img/about.jpg", "img/salon.jpg"];
self.addEventListener('install',e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS)).then(()=>self.skipWaiting()))});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(k=>Promise.all(k.filter(n=>n!==CACHE).map(n=>caches.delete(n)))).then(()=>self.clients.claim()))});
self.addEventListener('fetch',e=>{
  const r=e.request; if(r.method!=='GET')return;
  const url=new URL(r.url);
  if(url.origin===location.origin&&/\/(api|panel)\//.test(url.pathname))return; // live data & panel: always network
  if(r.mode==='navigate'){ // page: network first, offline fallback
    e.respondWith(fetch(r).then(res=>{const c=res.clone();caches.open(CACHE).then(x=>x.put(r,c));return res}).catch(()=>caches.match(r).then(m=>m||caches.match('index.html'))));return;}
  if(url.origin===location.origin||url.host.endsWith('gstatic.com')||url.host.endsWith('googleapis.com')){ // assets & fonts: cache first
    e.respondWith(caches.match(r).then(hit=>hit||fetch(r).then(res=>{const c=res.clone();caches.open(CACHE).then(x=>x.put(r,c));return res})));}
});
