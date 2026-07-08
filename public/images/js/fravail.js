// FlyRes – gemeinsame Verfuegbarkeits-Helfer fuer die modernen Web-Ansichten
// (overview + reserve). Global definiert, damit die Inline-IIFEs beider Seiten
// sie nutzen koennen. Zuvor waren toMin/busy in beiden Templates dupliziert.

// "HH:MM" -> Minuten seit Mitternacht (null bei leer)
function toMin(t){ if(!t) return null; var p=t.split(':'); return (+p[0])*60+(+p[1]); }

// freie Fenster [{start,end}] -> belegte Intervalle [[s,e],...] innerhalb [s,e]
function busy(free,s,e){ var f=(free||[]).map(function(w){return [toMin(w.start),toMin(w.end)];}).sort(function(a,b){return a[0]-b[0];}); var out=[],cur=s; f.forEach(function(x){ if(x[0]>cur)out.push([cur,x[0]]); cur=Math.max(cur,x[1]); }); if(cur<e)out.push([cur,e]); return out; }
