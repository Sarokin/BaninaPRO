/* =====================================================================
   BaninaPRO – kliens alkalmazás (vanilla JS, keretrendszer nélkül)
   ===================================================================== */
'use strict';
(function () {
  let INIT = {};
  try { INIT = JSON.parse((document.getElementById('banina-init') || {}).textContent || '{}') || {}; } catch (e) { INIT = {}; }
  const App = {
    archivKijeloles: new Set(),
    importAllapot: null,
    user: INIT.felhasznalo || null,
    beallitasok: INIT.beallitasok || { reszt_bejovo: false },
    csrf: INIT.csrf || '',
    lejart: INIT.lejart || null,      // 1.14: az előző munkamenet lejáratának oka (a belépő képernyő mutatja)
    qrBelepes: INIT.qr_belepes !== false,   // 1.15: QR-kódos belépés (admin kapcsoló); kikapcsolva mindenki jelszóval lép be
    ma: INIT.ma || new Date().toISOString().slice(0, 10),
    cegek: null,
    hataridoSzuro: { ceg: null, cegNev: '', penznem: null, irany: 'MIND', utalasAlatt: false },
    kimenoSorok: {},
    kimenoFul: 'NYITOTT',
    kotesFul: 'NYITOTT',
    kotesIdoszak: null,               // a kötések oldal időszak-szűrője: { cegId, mezo, tol, ig, nap? } – a fül szerint szűr
    allapotSzuro: null,               // 1.19: állapot vizsgálat a kötés / kimenő oldalon: { kulcs: 'kotes'|'kimeno', id, tol, ig, nap }
    idoszakUrlap: null,               // a kötés / kimenő oldal szűrő-mezői egy újrarajzolás idejére: { kulcs, mezo, tol, ig, nap }
    bejovoFul: 'NYITOTT',
    utalasFul: 'NYITOTT',
    mindenUtalasSzuro: { statusz: 'NYITOTT', penznem: '', ceg: '' },
    kereso: { q: '', adat: null, sorszam: 0 },
  };

  // =============================================================== segédek
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const NBSP = ' ';
  const NNBSP = ' ';

  function fmtOsszeg(n, penznem) {
    n = Number(n) || 0;
    const neg = n < 0;
    const cent = Math.round(Math.abs(n) * 100);
    const egesz = Math.floor(cent / 100);
    const tized = cent % 100;
    let s = String(egesz).replace(/\B(?=(\d{3})+(?!\d))/g, NNBSP);
    if (tized) s += ',' + String(tized).padStart(2, '0');
    return (neg ? '−' : '') + s + (penznem ? NBSP + penznem : '');
  }
  const negOszt = (n) => (Number(n) < 0 ? ' negativ' : '');
  // ---- szerepkörök (1.10): admin > irodavezető > rögzítő > üzletkötő
  const SZEREP_SZINT = { admin: 4, irodavezeto: 3, rogzito: 2, uzletkoto: 1 };
  const SZEREP_NEV = { admin: 'Admin', irodavezeto: 'Irodavezető', rogzito: 'Rögzítő', uzletkoto: 'Üzletkötő' };
  const SZEREP_LEIRAS = {
    admin: 'Mindent láthat és módosíthat, minden adminjoggal rendelkezik (felhasználók, biztonság, DB-mentés és visszaállítás).',
    irodavezeto: 'Minden adatot felvihet, módosíthat és törölhet is; adminjoga nincs (nem adhat hozzá felhasználót, nem végezhet biztonsági műveletet, DB-visszaállítást).',
    rogzito: 'Új adatot felvihet, a meglévőket szerkesztheti, de semmit nem törölhet. A más által írt megjegyzést nem írhatja át, csak hozzáfűzhet. Csak a saját eszközeit és jelszavát kezelheti.',
    uzletkoto: 'Mindent láthat, szűrhet és PDF-et készíthet, de nem vihet fel, nem módosít és nem töröl semmit. Kivétel: BESZÁM-ot beírhat / módosíthat (nem törölhet), és a megjegyzésekhez hozzáfűzhet.',
  };
  /** Van-e a bejelentkezett felhasználónak adott joga: 'ir' (felvitel, szerkesztés), 'torol' (törlés, visszavonás), 'admin' */
  function jog(mi) {
    const sz = App.user ? (SZEREP_SZINT[App.user.szerep] || 2) : 0;
    return mi === 'admin' ? sz >= 4 : mi === 'torol' ? sz >= 3 : mi === 'ir' ? sz >= 2 : sz >= 1;
  }
  const szerepNev = () => (App.user ? (App.user.szerep_nev || SZEREP_NEV[App.user.szerep] || 'Rögzítő') : '');
  /** Bejövő számlánál a részteljesítés csak akkor, ha az admin bekapcsolta (alapból ki) */
  const resztBejovo = () => !!(App.beallitasok && App.beallitasok.reszt_bejovo);
  /** Belépés után: felhasználó + nyilvános beállítások */
  function belepve(adat) {
    App.user = adat.felhasznalo;
    App.lejart = null;
    if (adat.beallitasok) { App.beallitasok = adat.beallitasok; App.qrBelepes = adat.beallitasok.qr_belepes !== false; }
    else api('en').then((d) => { if (d.beallitasok) App.beallitasok = d.beallitasok; Jelenlet.indit(); }).catch(() => {});
    Jelenlet.utolsoAkt = Date.now();
    Jelenlet.indit();
  }
  /**
   * Megjegyzés mező: ha a meglévő szöveget MÁS írta és a felhasználó nem törölhet (Rögzítő, Üzletkötő),
   * a régi szöveg csak olvasható, alá egy új mezőbe lehet hozzáfűzni.
   */
  const megjVedett = (r) => !jog('torol') && !!(r && String(r.megjegyzes || '').trim()) && Number(r.megjegyzes_irta) !== Number(App.user && App.user.id);
  function megjegyzesMezo(r, o) {
    o = o || {};
    if (megjVedett(r)) {
      return `<div class="mezo megj-mezo"><label>${esc(o.cimke || 'Megjegyzés')} <span class="opc">(más írta – csak hozzáfűzhetsz)</span></label>
        <div class="megj-vedett" title="Ezt a szöveget más írta, nem módosítható">${esc(r.megjegyzes)}</div>
        <textarea name="megjegyzes_uj" placeholder="Ide írd a saját megjegyzésedet – a meglévő szöveg alá kerül" ${o.attr || ''}></textarea>
        <input type="hidden" name="megjegyzes_regi" value="${esc(r.megjegyzes)}"></div>`;
    }
    return `<div class="mezo megj-mezo"><label>${esc(o.cimke || 'Megjegyzés')}${o.opc === false ? '' : ' <span class="opc">(nem kötelező)</span>'}</label><textarea name="megjegyzes" ${o.attr || ''}>${esc(r && r.megjegyzes)}</textarea></div>`;
  }
  /** Az űrlap adataiból a mentendő megjegyzés (hozzáfűzés esetén: régi + új sor) */
  function megjegyzesErtek(a) {
    if (Object.prototype.hasOwnProperty.call(a, 'megjegyzes_regi')) {
      const uj = String(a.megjegyzes_uj || '').trim();
      return uj ? String(a.megjegyzes_regi || '').replace(/\s+$/, '') + '\n' + uj : String(a.megjegyzes_regi || '');
    }
    return a.megjegyzes;
  }
  /** Megjegyzés a kártyán (sortörésekkel) */
  const megjSzoveg = (t) => (t ? `<span class="megj-szoveg">${esc(t)}</span>` : '');
  function fmtDatum(d) {
    if (!d) return '';
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d);
    return m ? `${m[1]}.${m[2]}.${m[3]}.` : d;
  }
  function fmtIdo(d) {
    if (!d) return '';
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(d);
    return m ? `${m[1]}.${m[2]}.${m[3]}. ${m[4]}:${m[5]}` : d;
  }
  function napokMulva(d) {
    const a = new Date(App.ma + 'T00:00:00');
    const b = new Date(d + 'T00:00:00');
    return Math.round((b - a) / 86400000);
  }
  function hataridoOszt(d) {
    const n = napokMulva(d);
    if (n < 0) return 'lejart';
    if (n <= 7) return 'hamarosan';
    return 'kesobb';
  }
  function hataridoSzoveg(d) {
    const n = napokMulva(d);
    if (n < 0) return `${-n} napja lejárt`;
    if (n === 0) return 'MA jár le';
    if (n === 1) return 'holnap';
    return `${n} nap múlva`;
  }
  const STATUSZ = {
    FIZETENDO: 'FIZETENDŐ', UTALASHOZ_ADVA: 'UTALÁSHOZ ADVA', FIZETVE: 'FIZETVE', BESZAMITVA: 'BESZÁMÍTVA',
    NYITOTT: 'NYITOTT', FIZETETT: 'FIZETETT', UTALVA: 'UTALVA', MANUAL: 'MANUÁLIS', HATARERTEK: 'HATÁRÉRTÉKES',
    FIZETETLEN: 'FIZETETLEN', MEG_NEM_LETEZETT: 'MÉG NEM LÉTEZETT',
  };
  const badge = (s, extra) => `<span class="badge ${esc(s)}${extra ? ' ' + extra : ''}">${esc(STATUSZ[s] || s)}</span>`;
  /** Lelakatolt (gyűjtés befejezve, státusz NYITOTT) utalás jelvénye */
  const lakatBadge = (u) => (u && u.lezarva ? `<span class="badge lakat" title="A gyűjtés befejeződött – a lakat kinyitásáig nem adható hozzá és nem vehető ki számla">${I.lock} LELAKATOLVA</span>` : '');
  /** Bejövő oldal státusz-fülei (kötés- és számlaszinten) – ugyanaz a minta, mint a kimenő számláknál */
  const BEJOVO_FUL = { NYITOTT: (s) => s.statusz === 'FIZETENDO' || s.statusz === 'UTALASHOZ_ADVA', FIZETVE: (s) => s.statusz === 'FIZETVE' || s.statusz === 'BESZAMITVA', MIND: () => true };
  const KOTES_FUL = { NYITOTT: (k) => k.statusz === 'NYITOTT', FIZETETT: (k) => k.statusz === 'FIZETETT', MIND: () => true };
  /** Állapot vizsgálat (1.19): a fülek a VIZSGÁLT NAPI állapot szerint – NYITOTT = fizetetlen, FIZETETT / FIZETVE = rendezett, MIND = mind (a még nem létezett is) */
  const ALLAPOT_RENDEZETT = (s) => s.allapot === 'FIZETVE' || s.allapot === 'BESZAMITVA';
  const ALLAPOT_FUL = { NYITOTT: (s) => s.allapot === 'FIZETETLEN', FIZETETT: ALLAPOT_RENDEZETT, FIZETVE: ALLAPOT_RENDEZETT, MIND: () => true };
  /** Fül-sáv: fulek = [[kulcs, felirat, kis felirat]] */
  function statuszFulek(fulek, akt, act) {
    const i = Math.max(0, fulek.findIndex((f) => f[0] === akt));
    return `<div class="fulek fulek-kis fulek-statusz" style="--n:${fulek.length};--i:${i}">${fulek.map(([k, f, kis]) => `<button type="button" data-act="${act}" data-ful="${k}" class="${akt === k ? 'aktiv' : ''}">${f}${kis ? `<small>${kis}</small>` : ''}</button>`).join('')}</div>`;
  }
  /** Nyitott (még rendezetlen) számla? – bejövő FIZETENDŐ / UTALÁSHOZ ADVA, kimenő NYITOTT */
  const nyitottSzamla = (s) => s.statusz === 'FIZETENDO' || s.statusz === 'UTALASHOZ_ADVA' || s.statusz === 'NYITOTT';
  /** A számla FŐÉRTÉKE: nyitott számlánál a hátralék (összeg − részteljesítések), különben az összeg */
  const foErtek = (s) => (Number(s.reszt) > 0 && nyitottSzamla(s) ? Number(s.hatralek) : Number(s.osszeg));
  /** Részteljesítés-sor a kártyákon: eredeti összeg + a részfizetések felsorolása */
  function resztSor(s) {
    if (!(Number(s.reszt) > 0)) return '';
    const ny = nyitottSzamla(s);
    const lista = (s.reszteljesitesek || []).map((r) => `<span class="reszt-csip" title="Részteljesítés">${fmtOsszeg(r.osszeg, s.penznem)} · ${fmtDatum(r.datum)}${r.banki_azonosito ? ` · ${esc(r.banki_azonosito)}` : ''}</span>`).join('');
    return `<div class="alsor reszt-sor"><span class="reszt-cim">${ny ? `Eredeti összeg: <b>${fmtOsszeg(s.osszeg, s.penznem)}</b> · részteljesítés: <b>−${fmtOsszeg(s.reszt, s.penznem)}</b> (${s.reszt_db} db)` : `Ebből részteljesítés: <b>${fmtOsszeg(s.reszt, s.penznem)}</b> (${s.reszt_db} db)`}</span>${lista}</div>`;
  }
  /** Kártya alja középen: ki hozta létre és ki módosította utoljára (percre pontosan) */
  function modositoSor(r) {
    const l = r.letrehozva ? `Létrehozta <b>${esc(r.letrehozta_nev || '?')}</b> · ${fmtIdo(r.letrehozva)}` : '';
    const m = r.modositva ? `Utoljára módosította <b>${esc(r.modositotta_nev || r.letrehozta_nev || '?')}</b> · ${fmtIdo(r.modositva)}` : '';
    if (!l && !m) return '';
    return `<div class="modosito-sor">${[l, m].filter(Boolean).join('<span class="elv">|</span>')}</div>`;
  }
  /** „Könyv” gomb: a rekord teljes története (ki, mikor, mit módosított) */
  const naploGomb = (tipus, id, cim) => `<button class="btn btn-outline btn-sm btn-ikon naplo-gomb" type="button" data-act="naplo-mutat" data-tipus="${tipus}" data-id="${id}" data-cim="${esc(cim)}" title="Változásnapló – ki, mikor, mit módosított" aria-label="Változásnapló">${I.book}</button>`;
  /** Fizetés / utalás dátuma rendezett számlánál */
  const fizetveSzoveg = (s, cimke) => (s.statusz === 'FIZETVE' || s.statusz === 'BESZAMITVA' ? `<span class="fizetve-datum">${cimke || 'Utalva'}: <b>${s.fizetve_datum ? fmtDatum(s.fizetve_datum) : '–'}</b></span>` : '');
  const HONAPOK = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
  const HONAP_ROVID = ['jan', 'feb', 'már', 'ápr', 'máj', 'jún', 'júl', 'aug', 'szep', 'okt', 'nov', 'dec'];
  const NAPOK = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
  function maiDatumSzoveg() {
    const d = new Date(App.ma + 'T00:00:00');
    return `${d.getFullYear()}. ${HONAPOK[d.getMonth()]} ${d.getDate()}., ${NAPOK[d.getDay()]}`;
  }
  function datumChip(d, extraOszt) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || '');
    if (!m) return '';
    return `<span class="datum-chip ${extraOszt || ''}"><b>${m[3]}</b><small>${HONAP_ROVID[Number(m[2]) - 1]}</small></span>`;
  }
  const avatar = (nev) => { let h = 0; for (const ch of String(nev || '')) h = (h * 31 + ch.charCodeAt(0)) % 997; return `<span class="avatar" data-szin="${h % 3}">${esc(String(nev || '?').trim().charAt(0).toUpperCase())}</span>`; };
  const evek = () => { const y = Number(App.ma.slice(0, 4)); return [y - 1, y, y + 1]; };
  const store = {
    get(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* privát mód */ } },
  };

  // ================================================================= ikonok
  const I = {
    logo: `<img src="assets/favicon.svg" alt="" width="30" height="30" draggable="false">`,
    factory: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><path d="M3 21V10l5 3.5V10l5 3.5V10l5 3.5V5h3v16H3z"/><path d="M7 17h2M11 17h2M15 17h2"/></svg>`,
    rope: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><path d="M12 11c-2.5-4.5-8-5.5-9-2.5S6.5 13 12 11z"/><path d="M12 11c2.5-4.5 8-5.5 9-2.5S17.5 13 12 11z"/><path d="M12 11c-1.5 3-2.5 6-5 9M12 11c1.5 3 2.5 6 5 9"/><circle cx="12" cy="11" r="1.6" fill="currentColor"/></svg>`,
    plus: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>`,
    transfer: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10h13l-3-3M20 14H7l3 3"/><rect x="2" y="4" width="20" height="16" rx="3"/></svg>`,
    in: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v11M7 9l5 5 5-5"/><path d="M4 15v3a3 3 0 003 3h10a3 3 0 003-3v-3"/></svg>`,
    out: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 14V3M7 8l5-5 5 5"/><path d="M4 15v3a3 3 0 003 3h10a3 3 0 003-3v-3"/></svg>`,
    scale: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v18M5 21h14M3 7h18"/><path d="M6 7l-3 7a3 3 0 006 0zM18 7l-3 7a3 3 0 006 0z"/></svg>`,
    calendar: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4M8 15h3"/></svg>`,
    bank: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10l9-6 9 6M5 10v8M9 10v8M15 10v8M19 10v8M3 21h18"/></svg>`,
    back: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>`,
    menu: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>`,
    edit: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4l10-10-4-4L4 16v4zM13 7l4 4"/></svg>`,
    trash: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>`,
    chev: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>`,
    check: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>`,
    x: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>`,
    home: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-8 9 8v10a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1z"/></svg>`,
    user: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/></svg>`,
    key: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="14" r="4"/><path d="M11 11l9-9M16 6l3 3"/></svg>`,
    logout: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 4H5a1 1 0 00-1 1v14a1 1 0 001 1h5M15 8l4 4-4 4M19 12H9"/></svg>`,
    filter: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18l-7 8v6l-4-2v-4z"/></svg>`,
    gear: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 00-.1-1l2-1.5-2-3.5-2.3.9a7 7 0 00-1.7-1L14.5 3h-5l-.4 2.4a7 7 0 00-1.7 1L5.1 5.5l-2 3.5 2 1.5a7 7 0 000 2l-2 1.5 2 3.5 2.3-.9a7 7 0 001.7 1l.4 2.4h5l.4-2.4a7 7 0 001.7-1l2.3.9 2-3.5-2-1.5c.1-.3.1-.7.1-1z"/></svg>`,
    inbox: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 13l2-8h14l2 8v6H3z"/><path d="M3 13h5l2 3h4l2-3h5"/></svg>`,
    sheet: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 4v16M15 4v16"/></svg>`,
    db: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5.5" rx="8" ry="3"/><path d="M4 5.5v13c0 1.7 3.6 3 8 3s8-1.3 8-3v-13"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>`,
    print: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 9V3.5h10V9"/><path d="M6 17.5H4.5a2 2 0 01-2-2v-4.5a2 2 0 012-2h15a2 2 0 012 2v4.5a2 2 0 01-2 2H18"/><rect x="7" y="14" width="10" height="6.5" rx="1"/><path d="M17.6 12.2h.01"/></svg>`,
    search: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.5 15.5L21 21"/></svg>`,
    book: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/><path d="M9 7h7M9 11h7"/></svg>`,
    card: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20M6 15h4"/></svg>`,
    lock: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2.5"/><path d="M8 11V7a4 4 0 018 0v4"/><path d="M12 15v2.5"/></svg>`,
    unlock: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2.5"/><path d="M8 11V7a4 4 0 017.7-1.5"/><path d="M12 15v2.5"/></svg>`,
    eye: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>`,
    eyeOff: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.3A10.8 10.8 0 0112 5c6.5 0 10 7 10 7a17.6 17.6 0 01-3.2 4.2"/><path d="M6.6 6.6A17.2 17.2 0 002 12s3.5 7 10 7c1.6 0 3-.4 4.3-1"/><path d="M9.9 9.9a3 3 0 004.2 4.2"/></svg>`,
  };

  // ==================================================================== API
  async function api(action, params) {
    let r;
    try {
      r = await fetch('api.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': App.csrf },
        body: JSON.stringify(Object.assign({ action }, params || {})),
      });
    } catch (e) {
      throw Object.assign(new Error('Nincs kapcsolat a szerverrel.'), { kod: 'HALOZAT' });
    }
    let j = null;
    try { j = await r.json(); } catch (e) { throw Object.assign(new Error('Érvénytelen válasz a szervertől (HTTP ' + r.status + ').'), { kod: 'JSON' }); }
    if (j.csrf) App.csrf = j.csrf;
    if (!j.ok) {
      if (j.kod === 'NINCS_BELEPVE') { if (App.user) Jelenlet.lejart(j.extra && j.extra.lejart); else { App.user = null; render(); } }
      throw Object.assign(new Error(j.hiba || 'Ismeretlen hiba'), { kod: j.kod, extra: j.extra || {} });
    }
    return j.adat;
  }
  async function cegekBetolt(frissit) {
    if (!App.cegek || frissit) App.cegek = (await api('cegek')).cegek;
    return App.cegek;
  }

  // ============================================================== jelenlét
  /**
   * 1.14 – a belépés a böngésző bezárásáig él.
   *  • A nyitott lap ULES_JELENLET_MP (alapból 30) másodpercenként „szívverést” küld (ping) – ha ez elmarad
   *    (a böngésző bezárult, a gép alszik, a telefon másik appban van), a szerver a csend-korlát után kiléptet.
   *  • A lap bezárásakor (pagehide) „lap bezárva” jelzés megy: utána már csak a türelmi időn belül (alapból 3 perc)
   *    nyitható vissza belépve – így a véletlenül bezárt lap visszahozható, a bezárt böngésző után viszont ki vagy léptetve.
   *  • A tétlenséget (utolsó kattintás / gépelés) a ping is jelenti, így a szerver a nyitva felejtett lapot is kilépteti.
   */
  const Jelenlet = {
    ido: null, utolsoAkt: Date.now(), fut: false,
    mp() { return Math.max(5, Number(App.beallitasok && App.beallitasok.jelenlet_mp) || 30); },
    indit() {
      if (this.ido) clearInterval(this.ido);
      this.ido = setInterval(() => this.ping(), this.mp() * 1000);
    },
    async ping() {
      if (!App.user || this.fut) return;
      this.fut = true;
      try {
        const r = await api('ping', { tetlen: Math.max(0, Math.round((Date.now() - this.utolsoAkt) / 1000)) });
        if (App.user && r && r.belepve === false) this.lejart(r.lejart);
      } catch (e) { /* hálózati hiba: a következő szívverés újra próbálja */ }
      finally { this.fut = false; }
    },
    /** A szerver kiléptetett (lejárt munkamenet): belépő képernyő a magyarázattal, a név előtöltve */
    lejart(info) {
      qrLeallit();
      App.user = null; App.cegek = null; hataridoAdat = null;
      App.lejart = info || { oka: 'A munkamenet lejárt, ezért biztonsági okból kiléptettünk.' };
      $('#modal-root').innerHTML = '';
      render();
    },
    /** pagehide: „lap bezárva” jelzés – a válasz nem érdekes, csak az elküldés (sendBeacon / keepalive) */
    zaras() {
      if (!App.user) return;
      const torzs = JSON.stringify({ action: 'lap_zaras', csrf: App.csrf });
      try {
        if (navigator.sendBeacon && navigator.sendBeacon('api.php', new Blob([torzs], { type: 'text/plain;charset=UTF-8' }))) return;   // safelisted típus: nincs előzetes (CORS) kérés
      } catch (e) { /* tovább a fetch-re */ }
      try { fetch('api.php', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: torzs }).catch(() => {}); } catch (e) { /* mindegy */ }
    },
  };
  ['pointerdown', 'keydown', 'touchstart', 'wheel'].forEach((ev) => document.addEventListener(ev, () => { Jelenlet.utolsoAkt = Date.now(); }, { passive: true, capture: true }));
  window.addEventListener('pagehide', () => Jelenlet.zaras());
  window.addEventListener('pageshow', (e) => { if (e.persisted) Jelenlet.ping(); });   // vissza a böngésző gyorsítótárából
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') Jelenlet.ping(); });

  // ============================================================ toast/modal
  let toastIdo = null;
  function toast(html, tipus, ms) {
    const root = $('#toast-root');
    root.innerHTML = `<div class="toast ${tipus || ''}" title="Bezárás" style="--ms:${ms || 3500}ms">${html}</div>`;
    $('.toast', root).addEventListener('click', (e) => { if (e.target.tagName !== 'A') root.innerHTML = ''; });
    clearTimeout(toastIdo);
    toastIdo = setTimeout(() => { root.innerHTML = ''; }, ms || 3500);
  }
  const hibaToast = (e) => toast(esc(e && e.message ? e.message : String(e)), 'hiba', 6000);

  function modal(opts) {
    const root = $('#modal-root');
    const hatter = document.createElement('div');
    hatter.className = 'modal-hatter';
    hatter.innerHTML = `<div class="modal ${opts.osztaly || ''}" role="dialog" aria-modal="true"><div class="modal-belso">${opts.bezarhato === false ? '' : '<button class="bezar" type="button" aria-label="Bezárás">×</button>'}${opts.cim ? `<h3>${opts.cim}</h3>` : ''}<div class="modal-tartalom">${opts.html || ''}</div></div></div>`;
    root.appendChild(hatter);
    const m = {
      el: hatter,
      tartalom: $('.modal-tartalom', hatter),
      bezar() { if (hatter.parentNode) hatter.parentNode.removeChild(hatter); if (opts.onBezar) opts.onBezar(); },
    };
    if (opts.bezarhato !== false) {
      $('.bezar', hatter).addEventListener('click', m.bezar);
      hatter.addEventListener('click', (e) => { if (e.target === hatter) m.bezar(); });
      lapHuzas($('.modal', hatter), m.bezar);
    }
    return m;
  }
  /** Alsó lap lehúzással bezárható (csak érintőképernyőn, a lap tetejéről indítva) */
  function lapHuzas(lap, bezar) {
    let y0 = null, dy = 0, aktiv = false;
    lap.addEventListener('touchstart', (e) => { if (window.innerWidth >= 640 || lap.scrollTop > 0) return; y0 = e.touches[0].clientY; dy = 0; aktiv = true; lap.classList.remove('visszaugrik'); }, { passive: true });
    lap.addEventListener('touchmove', (e) => { if (!aktiv || y0 === null) return; dy = Math.max(0, e.touches[0].clientY - y0); if (dy > 0) { lap.classList.add('huzas'); lap.style.transform = `translateY(${dy}px)`; } }, { passive: true });
    lap.addEventListener('touchend', () => { if (!aktiv) return; aktiv = false; lap.classList.remove('huzas'); if (dy > 110) { bezar(); } else { lap.classList.add('visszaugrik'); lap.style.transform = ''; } y0 = null; }, { passive: true });
  }
  function confirmModal(cim, html, o) {
    o = o || {};
    return new Promise((resolve) => {
      let valasz = false;
      const m = modal({
        cim, osztaly: o.osztaly,
        html: `${html}<div class="lablec"><button class="btn btn-outline" type="button" data-m="nem">${esc(o.megse || 'Mégse')}</button><button class="btn ${o.okOsztaly || ''}" type="button" data-m="igen">${esc(o.ok || 'Igen')}</button></div>`,
        onBezar: () => resolve(valasz),
      });
      $('[data-m=nem]', m.el).addEventListener('click', () => m.bezar());
      $('[data-m=igen]', m.el).addEventListener('click', () => { valasz = true; m.bezar(); });
    });
  }
  function uzenetModal(html, o) {
    o = o || {};
    return new Promise((resolve) => {
      const m = modal({ osztaly: 'nagy-uzenet', html: `${o.pipa ? PIPA : ''}${html}<div class="lablec"><button class="btn ${o.okOsztaly || 'btn-zold'} btn-blokk" type="button" data-m="ok">${esc(o.ok || 'OK')}</button></div>`, onBezar: () => resolve(true) });
      $('[data-m=ok]', m.el).addEventListener('click', () => m.bezar());
      animSzamok(m.el);
    });
  }
  /** "Biztos?" – 5 másodperces visszaszámláló, az IGEN csak utána aktív */
  function visszaszamlaloModal(kerdes, mp) {
    mp = mp || 5;
    return new Promise((resolve) => {
      const m = modal({
        bezarhato: false, osztaly: 'nagy-uzenet',
        html: `<div class="fo" style="color:#c62828">${esc(kerdes || 'Biztos?')}</div><div class="szamlalo" data-m="szam">${mp}</div><div class="kis">Az IGEN gomb csak a visszaszámlálás lejárta után aktiválódik.</div><div class="lablec"><button class="btn btn-outline" type="button" data-m="nem">Nem</button><button class="btn btn-piros" type="button" data-m="igen" disabled>Igen</button></div>`,
      });
      let n = mp;
      const szam = $('[data-m=szam]', m.el);
      const igen = $('[data-m=igen]', m.el);
      const t = setInterval(() => {
        n -= 1;
        szam.textContent = n > 0 ? n : '✓';
        if (n <= 0) { clearInterval(t); igen.disabled = false; igen.textContent = 'Igen, biztos'; }
      }, 1000);
      $('[data-m=nem]', m.el).addEventListener('click', () => { clearInterval(t); m.bezar(); resolve(false); });
      igen.addEventListener('click', () => { if (igen.disabled) return; m.bezar(); resolve(true); });
    });
  }

  /** Felpörgő számok: [data-countup="érték"] [data-penznem="EUR"] elemekre */
  function animSzamok(root) {
    const csokkentett = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    $$('[data-countup]', root || document).forEach((el) => {
      const veg = Number(el.dataset.countup) || 0; const pn = el.dataset.penznem || '';
      delete el.dataset.countup;
      if (csokkentett || Math.abs(veg) < 1) { el.textContent = fmtOsszeg(veg, pn); return; }
      const t0 = performance.now(); const tart = 700;
      const lep = (t) => { const p = Math.min(1, (t - t0) / tart); const e = 1 - Math.pow(1 - p, 3); el.textContent = fmtOsszeg(veg * e, pn); if (p < 1) requestAnimationFrame(lep); else el.textContent = fmtOsszeg(veg, pn); };
      requestAnimationFrame(lep);
    });
  }
  const PIPA = '<svg class="pipa-anim" viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="28"/><path d="M20 33l8 8 16-17"/></svg>';

  // ======================================================= nyomtatási kosár
  // A kosár csak azt jegyzi meg, MELY sorokat kérted ({t, id, cimke}); a PDF a
  // szerveren, mindig friss adatokból készül. Felhasználónként külön kosár, felső korlát nélkül.
  // osszevetes: az Összevetés oldal teljes lekérdezése (cég + pénznem + teljesítési időszak a 'q' mezőben)
  // allapot: állapot vizsgálat (1.19) – irány + cég (+ kötés) + kelt szerinti időszak + vizsgált nap a 'q' mezőben
  const KOSAR_TIPUS = { osszevetes: 'Összevetés', allapot: 'Állapot vizsgálat', ceg: 'Cég', kotes: 'Kötés', bejovo: 'Bejövő számla', utalas: 'Utalás', kimeno: 'Kimenő számla', kivonat: 'Banki kivonat' };
  /** A lekérdezést hordozó kosártételek 'q' mezője (típusonként tisztítva) */
  const KOSAR_Q = {
    osszevetes: (q) => ({ ceg: Number(q.ceg), pn: String(q.pn), tol: String(q.tol), ig: String(q.ig) }),
    allapot: (q) => ({ i: String(q.i), ceg: Number(q.ceg), kotes: Number(q.kotes) || 0, tol: String(q.tol), ig: String(q.ig), nap: String(q.nap) }),
  };
  const Kosar = {
    _mem: null, _kulcs: null, _halmaz: null, _halmazLista: null,
    kulcs() { return 'banina_nyomtat_' + (App.user ? App.user.felhasznalonev : 'vendeg'); },
    lista() {
      const k = this.kulcs();
      if (this._mem && this._kulcs === k) return this._mem;
      let l = [];
      try { const j = JSON.parse(localStorage.getItem(k) || '[]'); if (Array.isArray(j)) l = j.filter((t) => t && KOSAR_TIPUS[t.t] && Number(t.id) > 0 && (!KOSAR_Q[t.t] || (t.q && typeof t.q === 'object'))).map((t) => Object.assign({ t: t.t, id: Number(t.id), cimke: String(t.cimke || '') }, typeof t.e === 'string' ? { e: t.e } : {}, KOSAR_Q[t.t] ? { q: KOSAR_Q[t.t](t.q) } : {})); } catch (e) { /* privát mód / hibás adat */ }
      this._mem = l; this._kulcs = k;
      return l;
    },
    ment(l) { this._mem = l; this._kulcs = this.kulcs(); try { if (l.length) localStorage.setItem(this._kulcs, JSON.stringify(l)); else localStorage.removeItem(this._kulcs); } catch (e) { /* marad memóriában */ } frissitJelveny(true); },
    db() { return this.lista().length; },
    van(t, id) {
      const l = this.lista();
      if (this._halmazLista !== l) { this._halmaz = new Set(l.map((x) => `${x.t}:${x.id}`)); this._halmazLista = l; }
      return this._halmaz.has(`${t}:${Number(id)}`);
    },
    valt(t, id, cimke) {
      const l = this.lista().slice();
      const i = l.findIndex((x) => x.t === t && x.id === Number(id));
      if (i >= 0) { l.splice(i, 1); this.ment(l); return false; }
      l.push({ t, id: Number(id), cimke: String(cimke || '') }); this.ment(l); return true;
    },
    torol(t, id) { this.ment(this.lista().filter((x) => !(x.t === t && x.id === Number(id)))); },
    hozzaad(tetelek) {
      const l = this.lista().slice();
      const index = new Map(l.map((y) => [`${y.t}:${y.id}`, y]));   // sok ezer sornál is gyors keresés
      let uj = 0, mar = 0;
      tetelek.forEach((x) => {
        const kulcs = `${x.t}:${Number(x.id)}`;
        const megl = index.get(kulcs);
        if (megl) { mar++; if (typeof x.e === 'string') megl.e = x.e; return; }   // már benne van – az egyenleg-jelölést átveszi
        const t = Object.assign({ t: x.t, id: Number(x.id), cimke: String(x.cimke || '') }, typeof x.e === 'string' ? { e: x.e } : {}, x.q ? { q: x.q } : {});
        l.push(t); index.set(kulcs, t); uj++;
      });
      this.ment(l);
      return { uj, mar };
    },
    urit() { this.ment([]); },
  };
  window.addEventListener('storage', (e) => { if (e.key && e.key.startsWith('banina_nyomtat_')) { Kosar._mem = null; frissitJelveny(true); frissitNyomtatGombok(); } });
  /** Sor végi „nyomtatáshoz” gomb */
  function nyomtatGomb(t, id, cimke, extraOszt) {
    const a = Kosar.van(t, id);
    return `<button class="btn btn-outline btn-sm btn-ikon nyomtat${a ? ' aktiv' : ''}${extraOszt ? ' ' + extraOszt : ''}" type="button" data-act="nyomtat-valt" data-t="${t}" data-id="${id}" data-cimke="${esc(cimke)}" title="${a ? 'Kivétel a nyomtatásból' : 'Nyomtatáshoz'}" aria-label="${a ? 'Kivétel a nyomtatásból' : 'Nyomtatáshoz'}" aria-pressed="${a ? 'true' : 'false'}">${I.print}</button>`;
  }
  function frissitNyomtatGombok() {
    $$('[data-act=nyomtat-valt]').forEach((b) => { const a = Kosar.van(b.dataset.t, b.dataset.id); b.classList.toggle('aktiv', a); b.title = a ? 'Kivétel a nyomtatásból' : 'Nyomtatáshoz'; b.setAttribute('aria-label', b.title); b.setAttribute('aria-pressed', a ? 'true' : 'false'); });
  }
  /** A nyomtató-FAB jelvénye (piros kör, fehér szám) + menü számláló */
  function frissitJelveny(anim) {
    const n = Kosar.db();
    $$('.fab-nyomtat').forEach((f) => {
      f.classList.toggle('inaktiv', n === 0);
      f.title = n ? `${n} sor nyomtatása PDF-be` : 'Nyomtatási kosár üres – jelölj ki sorokat a nyomtató gombbal';
      f.setAttribute('aria-label', f.title);
      const j = $('.jelveny', f);
      if (j) { j.textContent = n ? String(n) : ''; if (anim && n) { j.classList.remove('pop'); void j.offsetWidth; j.classList.add('pop'); } }
    });
    $$('.fab-urit').forEach((b) => b.classList.toggle('rejtett', n === 0));
    $$('[data-kosar-db]').forEach((el) => { el.textContent = n ? String(n) : ''; });
  }
  /** PDF kérés: rejtett űrlap új lapra (a böngésző PDF-nézője nyitja meg) */
  function pdfKuldes(lista) {
    const f = document.createElement('form');
    f.method = 'post'; f.action = 'pdf.php'; f.target = '_blank'; f.style.display = 'none';
    const csrf = document.createElement('input'); csrf.type = 'hidden'; csrf.name = 'csrf'; csrf.value = App.csrf || '';
    const t = document.createElement('input'); t.type = 'hidden'; t.name = 'tetelek'; t.value = JSON.stringify(lista.map((x) => Object.assign({ t: x.t, id: x.id }, typeof x.e === 'string' ? { e: x.e } : {}, x.q ? { q: x.q } : {})));
    f.appendChild(csrf); f.appendChild(t);
    document.body.appendChild(f);
    f.submit();
    setTimeout(() => { if (f.parentNode) f.parentNode.removeChild(f); }, 1500);
  }
  /** Az Összevetés oldal teljes lekérdezése kosár-tételként – a PDF-ben saját, formázott szakasz lesz belőle.
   *  Az azonosító a lekérdezésből készül (FNV-1a): ugyanaz a cég + pénznem + időszak mindig ugyanaz a tétel. */
  function lekerdezesId(kulcs) {
    let h = 2166136261;
    for (let i = 0; i < kulcs.length; i++) { h ^= kulcs.charCodeAt(i); h = Math.imul(h, 16777619); }
    return (h >>> 1) || 1;
  }
  function osszevetesTetel(q, cegNev) {
    return { t: 'osszevetes', id: lekerdezesId(`${q.ceg}|${q.pn}|${q.tol}|${q.ig}`), cimke: `${cegNev} · ${q.pn} · teljesítés ${fmtDatum(q.tol)} – ${fmtDatum(q.ig)}`, q };
  }
  /** Az állapot vizsgálat kosár-tételként (1.19): a PDF-ben saját szakasz – a vizsgált napi egyenleg és a számlák az akkori állapotukkal */
  function allapotTetel(q, cimke) {
    return { t: 'allapot', id: lekerdezesId(`${q.i}|${q.ceg}|${q.kotes}|${q.tol}|${q.ig}|${q.nap}`), cimke, q };
  }
  /** Időszak-szűrő sáv (tól–ig + dátummező) – a szűrt sorok egy gombbal a nyomtatási kosárba */
  // allapot (1.19): ÁLLAPOT VIZSGÁLAT – a kelt szerint az időszakba eső számlák a VIZSGÁLT IDŐPONT-beli állapotukkal
  const IDOSZAK_MEZOK = { kelt: 'Kelt', teljesites: 'Teljesítés', hatarido: 'Határidő', allapot: 'Állapot vizsgálat' };
  /** Az időszak-szűrés címkéje: „Kelt 2026.01.01. – 2026.01.31.”, állapot vizsgálatnál „… · 2026.01.14. napi állapot” */
  const idoszakCimke = (e) => (e.mezo === 'allapot' ? `Kelt ${fmtDatum(e.tol)} – ${fmtDatum(e.ig)} · ${fmtDatum(e.nap)} napi állapot` : `${IDOSZAK_MEZOK[e.mezo]} ${fmtDatum(e.tol)} – ${fmtDatum(e.ig)}`);
  function idoszakSav(o) {
    const sz = o.ertek || {};
    const ev = App.ma.slice(0, 4);
    const allapot = sz.mezo === 'allapot';
    return `<div class="kartya idoszak-sav${o.aktiv ? ' aktiv' : ''}" data-idoszak="${esc(o.kulcs)}">
      <div class="cim">${I.calendar} ${esc(o.cim || 'Számlák időszak szerint')}</div>
      <div class="mezok${allapot ? ' allapot' : ''}">
        <div class="mezo"><label>Dátum</label><select name="mezo" data-act="idoszak-mezo">${Object.keys(IDOSZAK_MEZOK).map((k) => `<option value="${k}" ${(sz.mezo || 'kelt') === k ? 'selected' : ''}>${IDOSZAK_MEZOK[k]}</option>`).join('')}</select></div>
        <div class="mezo"><label><span class="${allapot ? '' : 'rejtett'}" data-allapot-mutat>Kelt </span>-tól</label><input type="date" name="tol" value="${esc(sz.tol || ev + '-01-01')}"></div>
        <div class="mezo"><label>-ig</label><input type="date" name="ig" value="${esc(sz.ig || App.ma)}"></div>
        <div class="mezo${allapot ? '' : ' rejtett'}" data-allapot-mutat><label>Vizsgált időpont</label><input type="date" name="nap" value="${esc(sz.nap || App.ma)}"></div>
        <button class="btn btn-sm" type="button" data-act="${esc(o.act)}" ${o.data || ''}>${I.filter} Szűrés</button>
      </div>
      <div class="allapot-sugo${allapot ? '' : ' rejtett'}" data-allapot-mutat>A <b>kelt</b> szerint az időszakba eső számlák azzal az állapottal, ami a <b>vizsgált időpontban</b> volt: fizetetlen, fizetve / beszámítva, vagy még nem létezett. Az egyenleg is erre a napra szól, a fül MIND-re vált.</div>
      <div class="eredmeny" data-idoszak-eredmeny>${o.eredmeny || ''}</div>
    </div>`;
  }
  /** A dátummező váltásakor az állapot vizsgálat mezői (vizsgált időpont, magyarázat) megjelennek / eltűnnek */
  function idoszakMezoValt(sel) {
    const sav = sel.closest('.idoszak-sav');
    const a = sel.value === 'allapot';
    $$('[data-allapot-mutat]', sav).forEach((x) => x.classList.toggle('rejtett', !a));
    $('.mezok', sav).classList.toggle('allapot', a);
  }
  function idoszakErtek(sav) {
    const mezo = $('[name=mezo]', sav).value, tol = $('[name=tol]', sav).value, ig = $('[name=ig]', sav).value;
    if (!tol || !ig) throw new Error('Add meg a tól–ig dátumot!');
    if (tol > ig) throw new Error('A kezdő dátum nem lehet későbbi a záró dátumnál!');
    if (mezo !== 'allapot') return { mezo, tol, ig };
    const nap = $('[name=nap]', sav).value;
    if (!nap) throw new Error('Add meg a vizsgált időpontot!');
    return { mezo, tol, ig, nap };
  }
  /** Az időszak-sáv mezőinek pillanatnyi (még nem alkalmazott) értékei – újrarajzolás után visszaírhatók */
  const idoszakUrlap = (sav) => (sav ? { mezo: $('[name=mezo]', sav).value, tol: $('[name=tol]', sav).value, ig: $('[name=ig]', sav).value, nap: $('[name=nap]', sav).value } : null);
  const idoszakDatum = (s, mezo) => (mezo === 'teljesites' ? s.teljesites_datum : mezo === 'hatarido' ? s.fizetesi_hatarido : s.kelt);
  /** Szűrt tételek a kosárba + visszajelzés */
  function idoszakKosarba(tetelek, cimke) {
    if (!tetelek.length) return toast('Nincs a szűrésnek megfelelő számla.', 'hiba');
    if (tetelek[0].t === 'allapot') {
      const ra = Kosar.hozzaad(tetelek);
      kosarLapFrissit();
      return toast(`${I.print} Az állapot vizsgálat ${ra.mar ? 'már benne volt a' : 'bekerült a'} nyomtatási kosárba.<br><small>${esc(cimke)}</small><br><small>A PDF-ben saját szakasz: a vizsgált napi <b>egyenleg</b> és a számlák az akkori állapotukkal.</small><br><small>${Kosar.db()} sor a kosárban – a jobb alsó nyomtató gombbal készíthetsz PDF-et</small>`, 'siker', 6000);
    }
    const r = Kosar.hozzaad(tetelek);
    frissitNyomtatGombok();
    const egyenleg = tetelek.some((t) => t.e !== undefined);
    toast(`${I.print} ${r.uj} számla a nyomtatási kosárba került${r.mar ? ` (${r.mar} már benne volt)` : ''}${cimke ? `<br><small>${esc(cimke)}</small>` : ''}${egyenleg ? '<br><small><b>EGYENLEG</b> készül a PDF-ben (összes forgalom · fizetett · nyitott)</small>' : ''}<br><small>${Kosar.db()} sor a kosárban – a jobb alsó nyomtató gombbal készíthetsz PDF-et</small>`, 'siker', 5000);
  }
  /** Kötés / kimenő lista: kliensoldali szűrés a betöltött számlákon */
  function idoszakSzuresLista(sav, szamlak, tipus) {
    const e = idoszakErtek(sav);
    const talalat = szamlak.filter((s) => { const d = idoszakDatum(s, e.mezo); return d >= e.tol && d <= e.ig; });
    const ids = new Set(talalat.map((s) => s.id));
    let latszik = 0;
    $$('.szamla-lista [data-szamla-id]').forEach((k) => { const on = ids.has(Number(k.dataset.szamlaId)); k.classList.toggle('rejtett', !on); if (on) latszik++; });
    const ossz = {};
    talalat.forEach((s) => { ossz[s.penznem] = (ossz[s.penznem] || 0) + Number(s.osszeg); });
    const egyenleg = !!$('[data-egyenleg]', sav);
    const cimke = `${IDOSZAK_MEZOK[e.mezo]} ${fmtDatum(e.tol)} – ${fmtDatum(e.ig)}`;
    $('[data-idoszak-eredmeny]', sav).innerHTML = `<span><b>${talalat.length}</b> számla (${esc(IDOSZAK_MEZOK[e.mezo].toLowerCase())} ${fmtDatum(e.tol)} – ${fmtDatum(e.ig)})${Object.keys(ossz).length ? ' · ' + Object.keys(ossz).map((p) => fmtOsszeg(ossz[p], p)).join(' · ') : ''}</span>
      <button class="btn btn-sarga btn-sm" type="button" data-act="idoszak-kosarba" ${talalat.length ? '' : 'disabled'}>${I.print} ${egyenleg ? `Mind a kosárba – egyenleg (${talalat.length})` : `Mind a kosárba (${talalat.length})`}</button>
      <button class="btn btn-outline btn-sm" type="button" data-act="idoszak-torol">${I.x} Szűrés törlése</button>`;
    sav.dataset.talalat = JSON.stringify(talalat.map((s) => Object.assign({ t: tipus, id: s.id, cimke: `${s.kod} · „${s.szamlaszam}”` }, egyenleg ? { e: cimke } : {})));
    sav.dataset.cimke = cimke;
  }
  let kosarLap = null;
  function kosarLapTartalom() {
    const l = Kosar.lista();
    const csoport = {};
    l.forEach((x) => { (csoport[x.t] = csoport[x.t] || []).push(x); });
    const sorrend = Object.keys(KOSAR_TIPUS).filter((t) => csoport[t]);
    return `${l.length ? `<div class="kosar-lista">${sorrend.map((t) => `<div class="kosar-csoport"><div class="kosar-tipus">${esc(KOSAR_TIPUS[t])} <span>${csoport[t].length}</span></div>${csoport[t].map((x) => `<div class="kosar-sor"><span class="cimke">${esc(x.cimke || (KOSAR_TIPUS[t] + ' #' + x.id))}${typeof x.e === 'string' ? ' <span class="badge FIZETENDO" title="Egyenleg készül a PDF-ben">egyenleg</span>' : ''}</span><button class="btn btn-outline piros btn-sm btn-ikon" type="button" data-act="kosar-ki" data-t="${t}" data-id="${x.id}" title="Kivétel" aria-label="Kivétel">${I.x}</button></div>`).join('')}</div>`).join('')}</div>`
      : `<div class="ures">${URES}A kosár üres.<br><small>A listákban a sorok végén lévő nyomtató gombbal gyűjthetsz össze sorokat, majd a jobb alsó nyomtató gombbal készíthetsz belőlük PDF-et.</small></div>`}
      <div class="lablec">
        <button class="btn btn-outline piros" type="button" data-act="nyomtat-urit" ${l.length ? '' : 'disabled'}>${I.trash} Ürítés</button>
        <button class="btn" type="button" data-act="nyomtat-pdf" ${l.length ? '' : 'disabled'}>${I.print} PDF készítése${l.length ? ` (${l.length})` : ''}</button>
      </div>`;
  }
  function nyomtatKosarLap() {
    kosarLap = modal({ cim: 'Nyomtatási kosár', osztaly: 'kosar', html: kosarLapTartalom(), onBezar: () => { kosarLap = null; } });
  }
  function kosarLapFrissit() { if (kosarLap) kosarLap.tartalom.innerHTML = kosarLapTartalom(); }

  // ======================================================= utalási „kosár”
  // Az utoljára használt NYITOTT utalás (amihez számlát adtál): bankkártya-gomb a jobb alsó sarokban,
  // a nyomtató és a + gomb felett. Bárhonnan megnyitható előnézet – az oldal nem navigál el.
  // Felhasználónként, localStorage-ban; ha az utalás már nem nyitott, a gomb eltűnik.
  const UtalasKosar = {
    kulcs() { return 'banina_utalas_' + (App.user ? App.user.felhasznalonev : 'vendeg'); },
    get() { try { const j = JSON.parse(localStorage.getItem(this.kulcs()) || 'null'); return j && Number(j.id) > 0 && !j.lezarva ? j : null; } catch (e) { return null; } },
    set(u) {
      if (u && u.lezarva) { this.torol(); return; }     // lelakatolt utalás nem gyűjtő: a bankkártyás gomb „elengedi”
      const regi = this.get() || {};
      const a = { id: Number(u.id), uid: u.uid || regi.uid || '', penznem: u.penznem || regi.penznem || '', ceg_nev: u.ceg_nev || (regi.id === Number(u.id) ? regi.ceg_nev : '') || '', ceg_id: Number(u.ceg_id) || (regi.id === Number(u.id) ? Number(regi.ceg_id) || 0 : 0), osszeg: Number(u.osszeg) || 0, db: Number(u.db) || 0, lezarva: u.lezarva !== undefined ? !!u.lezarva : (regi.id === Number(u.id) ? !!regi.lezarva : false) };
      try { localStorage.setItem(this.kulcs(), JSON.stringify(a)); } catch (e) { /* privát mód */ }
      this.frissit(true);
    },
    torol(id) { const a = this.get(); if (!a || (id && a.id !== Number(id))) return; try { localStorage.removeItem(this.kulcs()); } catch (e) { /* mindegy */ } this.frissit(); },
    /** Friss utalás-sor alapján: ha az aktuális gyűjtő közben lelakatolták vagy már nem nyitott, elengedjük. Vissza: elengedtük-e */
    egyeztet(u) {
      const a = this.get();
      if (!a || !u || a.id !== Number(u.id)) return false;
      if (u.lezarva || (u.statusz && u.statusz !== 'NYITOTT')) { this.torol(); return true; }
      return false;
    },
    html() {
      const a = this.get();
      if (!a) return '';
      const cim = `Aktuális utalás: ${a.uid}${a.osszeg ? ' · ' + fmtOsszeg(a.osszeg, a.penznem) : ''}${a.lezarva ? ' · LELAKATOLVA' : ''} – kattints a tartalmához`;
      return `<button class="fab fab-utalas${a.lezarva ? ' lakat' : ''}" type="button" data-act="utalas-kosar" aria-label="${esc(cim)}" title="${esc(cim)}">${I.card}<span class="jelveny" aria-hidden="true">${a.db || ''}</span>${a.lezarva ? `<span class="lakat-jel" aria-hidden="true">${I.lock}</span>` : ''}</button>`;
    },
    /** A gomb újrarajzolása az aktuális oldalon */
    frissit(anim) {
      const regi = $('.fab-utalas');
      const a = this.get();
      if (!a) { if (regi) regi.remove(); document.body.classList.remove('van-utalas-fab'); fabRendez(); return; }
      const html = this.html();
      if (regi) regi.outerHTML = html; else if ($('#app')) $('#app').insertAdjacentHTML('beforeend', html);
      document.body.classList.add('van-utalas-fab');
      fabRendez();
      if (anim) { const j = $('.fab-utalas .jelveny'); if (j) { j.classList.remove('pop'); void j.offsetWidth; j.classList.add('pop'); } }
    },
  };
  /** A gyűjtő gombok (utalás, bank) egymás fölé rendezése: a szint = hány gomb van alattuk (+, nyomtató, alsó sáv) */
  function fabRendez() {
    let szint = ($('.fab:not(.fab-nyomtat):not(.fab-utalas):not(.fab-bank)') ? 1 : 0) + ($('.fab-nyomtat') ? 1 : 0) + ($('.also-sav') ? 1 : 0);
    ['.fab-utalas', '.fab-bank'].forEach((sel) => {
      const f = $(sel);
      if (!f) return;
      f.classList.remove('sz0', 'sz1', 'sz2', 'sz3', 'sz4');
      f.classList.add('sz' + Math.min(4, szint));
      szint++;
    });
  }

  // ===================================================== banki megfeleltetés gyűjtő
  // A kimenő számlák kerek gombjával kijelölt, még NYITOTT számlák – bankkivonati azonosítóhoz adásra várnak.
  // Bank-épület gomb a jobb alsó sarokban (a többi gyűjtő felett), felhasználónként, localStorage-ban;
  // több cég számlája is gyűjthető, a megfeleltetés után a kosár kiürül.
  const BANK_KOSAR_MAX = 200;
  const BankKosar = {
    _mem: null, _kulcs: null,
    kulcs() { return 'banina_bank_' + (App.user ? App.user.felhasznalonev : 'vendeg'); },
    lista() {
      const k = this.kulcs();
      if (this._mem && this._kulcs === k) return this._mem;
      let l = [];
      try { const j = JSON.parse(localStorage.getItem(k) || '[]'); if (Array.isArray(j)) l = j.filter((t) => t && Number(t.id) > 0).map((t) => ({ id: Number(t.id), kod: String(t.kod || ''), szamlaszam: String(t.szamlaszam || ''), ceg_nev: String(t.ceg_nev || ''), ceg_id: Number(t.ceg_id) || 0, penznem: String(t.penznem || ''), hatralek: Number(t.hatralek) || 0, fizetesi_hatarido: String(t.fizetesi_hatarido || '') })); } catch (e) { /* privát mód */ }
      this._mem = l; this._kulcs = k;
      return l;
    },
    ment(l, anim) { this._mem = l; this._kulcs = this.kulcs(); try { if (l.length) localStorage.setItem(this._kulcs, JSON.stringify(l)); else localStorage.removeItem(this._kulcs); } catch (e) { /* marad memóriában */ } this.frissit(anim); },
    db() { return this.lista().length; },
    ids() { return this.lista().map((x) => x.id); },
    van(id) { return this.lista().some((x) => x.id === Number(id)); },
    /** Kijelölés be/ki – s: a számla sora (kimeno_szamlak). Vissza: bent van-e utána */
    valt(s) {
      const l = this.lista().slice();
      const i = l.findIndex((x) => x.id === Number(s.id));
      if (i >= 0) { l.splice(i, 1); this.ment(l, true); return false; }
      if (l.length >= BANK_KOSAR_MAX) throw new Error(`A banki gyűjtőbe legfeljebb ${BANK_KOSAR_MAX} számla kerülhet.`);
      l.push({ id: Number(s.id), kod: s.kod, szamlaszam: s.szamlaszam, ceg_nev: s.ceg_nev || '', ceg_id: Number(s.ceg_id) || 0, penznem: s.penznem, hatralek: Number(s.hatralek != null ? s.hatralek : s.osszeg) || 0, fizetesi_hatarido: s.fizetesi_hatarido || '' });
      this.ment(l, true); return true;
    },
    torol(id) { this.ment(this.lista().filter((x) => x.id !== Number(id))); },
    urit() { this.ment([]); },
    osszegek() { const o = {}; this.lista().forEach((x) => { o[x.penznem] = (o[x.penznem] || 0) + x.hatralek; }); return o; },
    html() {
      const n = this.db();
      if (!n) return '';
      const o = this.osszegek();
      const cim = `Banki megfeleltetés: ${n} számla${Object.keys(o).length ? ' · ' + Object.keys(o).map((p) => fmtOsszeg(o[p], p)).join(' · ') : ''} – kattints a listához és a bankazonosítóhoz adáshoz`;
      return `<button class="fab fab-bank" type="button" data-act="bank-kosar" aria-label="${esc(cim)}" title="${esc(cim)}">${I.bank}<span class="jelveny" aria-hidden="true">${n}</span></button>`;
    },
    /** A gomb + a kártyák kijelölő gombjainak frissítése az aktuális oldalon (újrarajzolás nélkül) */
    frissit(anim) {
      const regi = $('.fab-bank');
      const n = this.db();
      if (!n) { if (regi) regi.remove(); document.body.classList.remove('van-bank-fab'); }
      else {
        const html = this.html();
        if (regi) regi.outerHTML = html; else if ($('#app') && $('.topbar')) $('#app').insertAdjacentHTML('beforeend', html);
        document.body.classList.add('van-bank-fab');
        if (anim) { const j = $('.fab-bank .jelveny'); if (j) { j.classList.remove('pop'); void j.offsetWidth; j.classList.add('pop'); } }
      }
      fabRendez();
      $$('[data-act=kimeno-jelol]').forEach((c) => { const on = this.van(c.dataset.szamla); c.checked = on; const l = c.closest('.kijelolo'); if (l) l.classList.toggle('bejelolt', on); });
      $$('[data-act=kimeno-jelol-gomb]').forEach((g) => { g.innerHTML = this.van(g.dataset.szamla) ? I.x + ' Kijelölés törlése' : I.bank + ' Bankazonosítóhoz'; });
      $$('[data-bank-kosar-db]').forEach((el) => { el.textContent = n ? String(n) : ''; });
    },
  };
  window.addEventListener('storage', (e) => { if (e.key && e.key.startsWith('banina_bank_')) { BankKosar._mem = null; BankKosar.frissit(); } });
  /** A gyűjtött számlák listája + bankazonosítóhoz adás – navigáció nélkül */
  async function bankKosarModal() {
    if (!BankKosar.db()) { toast(`${I.bank} A banki gyűjtő üres.<br><small>A kimenő számlák elején lévő kerek gombbal jelöld ki a bankkivonattal megfeleltetni kívánt számlákat.</small>`, '', 5000); return; }
    let valtozott = false;
    const m = modal({ cim: `${I.bank} Banki megfeleltetés <small>gyűjtő</small>`, osztaly: 'bank-kosar-modal', html: '<div class="toltes"></div>', onBezar: () => { if (valtozott) render(); } });
    const rajzol = async () => {
      const ids = BankKosar.ids();
      if (!ids.length) { m.tartalom.innerHTML = `<div class="ures">${URES}A gyűjtő üres.<br><small>A kimenő számlák elején lévő kerek gombbal jelöld ki a számlákat, amelyeket egy bankkivonati azonosítóhoz rendelsz – FIZETVE státuszt kapnak.</small></div><div class="lablec"><button class="btn btn-outline" type="button" data-m="ok">Bezárás</button></div>`; $('[data-m=ok]', m.el).addEventListener('click', () => m.bezar()); return; }
      let sorok = null;
      try { sorok = (await api('kimeno_szamlak_lista', { ids })).szamlak; } catch (e) { /* offline: a mentett adatokkal rajzolunk */ }
      let kiesett = [];
      if (sorok) {
        // ami közben FIZETVE lett vagy törölték, kikerül a gyűjtőből
        const friss = sorok.filter((x) => x.statusz === 'NYITOTT');
        const frissIds = new Set(friss.map((x) => x.id));
        kiesett = BankKosar.lista().filter((x) => !frissIds.has(x.id));
        if (kiesett.length) { valtozott = true; BankKosar.ment(BankKosar.lista().filter((x) => frissIds.has(x.id))); }
        BankKosar.ment(friss.map((x) => ({ id: x.id, kod: x.kod, szamlaszam: x.szamlaszam, ceg_nev: x.ceg_nev, ceg_id: x.ceg_id, penznem: x.penznem, hatralek: x.hatralek, fizetesi_hatarido: x.fizetesi_hatarido })));
      }
      const l = BankKosar.lista();
      const cegek = {};
      l.forEach((x) => { (cegek[x.ceg_nev] = cegek[x.ceg_nev] || []).push(x); });
      const o = BankKosar.osszegek();
      m.tartalom.innerHTML = `
        ${kiesett.length ? `<div class="figyelem-doboz">${kiesett.length} számla kikerült a gyűjtőből, mert közben már nem NYITOTT (fizetve lett vagy törölték): ${kiesett.map((x) => `<span class="mono">${esc(x.kod)}</span>`).join(', ')}.</div>` : ''}
        <div class="uk-fej"><div class="uk-osszeg">${Object.keys(o).length ? Object.keys(o).map((p) => fmtOsszeg(o[p], p)).join(' · ') : '–'}</div><div class="uk-meta">${l.length} nyitott számla · ${Object.keys(cegek).length} cég · egy bankkivonati azonosítóhoz</div></div>
        <div class="reszletes uk-lista">${l.length ? Object.keys(cegek).map((cn) => `
          <div class="kotes-sor"><a class="kod" href="#/kimeno/ceg/${cegek[cn][0].ceg_id}" data-act="nav" data-href="#/kimeno/ceg/${cegek[cn][0].ceg_id}">${esc(cn)}</a><span>${cegek[cn].length} db</span></div>
          ${cegek[cn].map((x) => `<div class="szamla-sor"><div class="bal"><a class="k" href="#/kimeno/ceg/${x.ceg_id}?szamla=${x.id}" data-act="nav" data-href="#/kimeno/ceg/${x.ceg_id}?szamla=${x.id}">${esc(x.kod)}</a><span class="szsz">„${esc(x.szamlaszam)}”</span>${x.fizetesi_hatarido ? `<span class="kicsi szurke">határidő ${fmtDatum(x.fizetesi_hatarido)}</span>` : ''}</div><span class="o${negOszt(x.hatralek)}">${fmtOsszeg(x.hatralek, x.penznem)}</span><button class="btn btn-outline piros btn-sm btn-ikon" type="button" data-bk-ki="${x.id}" title="Kivétel a gyűjtőből" aria-label="Kivétel a gyűjtőből">${I.x}</button></div>`).join('')}`).join('') : `<div class="ures">A gyűjtő üres.</div>`}
        </div>
        <div class="kicsi szurke" style="margin-top:8px">A gyűjtőbe a kimenő számlák elején lévő kerek gombbal teszel számlát – bármelyik cégtől. A bankazonosítóhoz adás után a számlák FIZETVE státuszt kapnak, és a gyűjtő kiürül.</div>
        <div class="lablec"><button class="btn btn-outline" type="button" data-m="ok">Bezárás</button><button class="btn btn-outline piros" type="button" data-act="bank-kosar-urit" ${l.length ? '' : 'disabled'}>${I.trash} Ürítés</button><button class="btn btn-zold" type="button" data-act="bank-kosar-hozzarendel" ${l.length ? '' : 'disabled'}>${I.bank} Bankazonosítóhoz adás (${l.length})</button></div>`;
      $('[data-m=ok]', m.el).addEventListener('click', () => m.bezar());
    };
    m.el.addEventListener('click', async (e) => {
      const ki = e.target.closest('[data-bk-ki]');
      if (ki) { BankKosar.torol(Number(ki.dataset.bkKi)); valtozott = true; await rajzol(); return; }
      if (e.target.closest('[data-act=bank-kosar-urit]')) { BankKosar.urit(); valtozott = true; await rajzol(); return; }
      if (e.target.closest('[data-act=bank-kosar-hozzarendel]')) { const ids = BankKosar.ids(); if (!ids.length) return; valtozott = false; m.bezar(); bankHozzarendelModal(ids); }
    });
    await rajzol();
  }
  /** Az aktuális utalás tartalma egy ablakban – navigáció nélkül */
  async function utalasKosarModal() {
    const a = UtalasKosar.get();
    if (!a) return;
    let valtozott = false;
    const m = modal({ cim: `${I.card} Aktuális utalás <small class="mono">${esc(a.uid)}</small>`, osztaly: 'utalas-kosar-modal', html: '<div class="toltes"></div>', onBezar: () => { if (valtozott) render(); } });
    const rajzol = async () => {
      try {
        const d = await api('utalas', { id: a.id });
        const u = d.utalas;
        if (u.statusz !== 'NYITOTT' || u.lezarva) {
          UtalasKosar.torol();
          m.tartalom.innerHTML = `<div class="info-doboz">${u.lezarva ? `Ez az utalás (<b class="mono">${esc(u.uid)}</b>) <b>le van lakatolva</b> – a gyűjtés befejeződött, ezért a bankkártyás gomb elengedte. Ha mégis gyűjteni akarsz bele, nyisd ki a lakatot az utalás oldalán, és nyomd meg az „Ehhez gyűjtök” gombot.` : `Ez az utalás (<b class="mono">${esc(u.uid)}</b>) már <b>${esc(STATUSZ[u.statusz] || u.statusz)}</b> státuszú – nincs nyitott utalási kosár.`} Amint egy számlát utaláshoz adsz, vagy egy nyitott utalásnál megnyomod az „Ehhez gyűjtök” gombot, a gomb újra megjelenik.</div>
            <div class="lablec"><button class="btn btn-outline" type="button" data-m="ok">Bezárás</button><a class="btn" href="#/utalas/${u.id}" data-act="nav" data-href="#/utalas/${u.id}">Utalás megnyitása</a></div>`;
          $('[data-m=ok]', m.el).addEventListener('click', () => m.bezar());
          return;
        }
        UtalasKosar.set({ id: u.id, uid: u.uid, penznem: u.penznem, ceg_nev: u.ceg_nev, ceg_id: u.ceg_id, osszeg: u.osszeg, db: u.db, lezarva: u.lezarva });
        let masik = [];
        try { masik = (await api('utalasok', { statusz: 'NYITOTT' })).utalasok.filter((x) => x.id !== u.id && !x.lezarva); } catch (e) { /* lista nélkül is megy */ }
        const link = (h) => `href="${h}" data-act="nav" data-href="${h}"`;
        m.tartalom.innerHTML = `
          <div class="uk-fej">
            <div class="uk-osszeg${negOszt(u.osszeg)}">${fmtOsszeg(u.osszeg, u.penznem)}</div>
            <div class="uk-meta">${badge(u.statusz)} ${badge(u.utalas_mod)} ${lakatBadge(u)} <a ${link(`#/bejovo/ceg/${u.ceg_id}`)}>${esc(u.ceg_nev)}</a> · ${u.db} számla${u.hatarertek != null ? ` · limit ${fmtOsszeg(u.hatarertek, u.penznem)}` : ''}</div>
            ${jog('ir') ? `<div class="gomb-sor" style="justify-content:center;margin-top:10px"><button class="btn btn-sm btn-fekete" type="button" data-uk-lakat="1" ${u.db ? '' : 'disabled'} title="A gyűjtés befejezése: az utalás lelakatolódik (NYITOTT marad), és a bankkártyás gomb elengedi">${I.lock} Lelakatolás – a gyűjtés kész</button></div>` : ''}
          </div>
          <div class="reszletes uk-lista">${d.kotesek.length ? d.kotesek.map((k) => `
            <div class="kotes-sor"><a class="kod" ${link(`#/bejovo/ceg/${u.ceg_id}/kotes/${k.kotes_pk}`)}>${esc(k.kotes_kod)}${k.kotes_megnevezes ? `<small>${esc(k.kotes_megnevezes)}</small>` : ''}</a><span class="${negOszt(k.osszeg)}">${fmtOsszeg(k.osszeg, u.penznem)}</span></div>
            ${k.szamlak.map((sz) => `<div class="szamla-sor"><div class="bal"><a class="k" ${link(`#/bejovo/ceg/${u.ceg_id}/kotes/${k.kotes_pk}?szamla=${sz.id}`)}>– ${esc(sz.k)}</a><span class="szsz">„${esc(sz.szamlaszam)}”</span><span class="kicsi szurke">határidő ${fmtDatum(sz.fizetesi_hatarido)}</span>${Number(sz.reszt) > 0 ? `<span class="kicsi szurke">eredeti ${fmtOsszeg(sz.osszeg, u.penznem)} · részt. −${fmtOsszeg(sz.reszt, u.penznem)}</span>` : ''}</div><span class="o${negOszt(sz.hatralek)}">${fmtOsszeg(sz.hatralek, u.penznem)}</span>${jog('ir') && !u.lezarva ? `<button class="btn btn-outline piros btn-sm btn-ikon" type="button" data-uk-ki="${sz.id}" data-kod="${esc(sz.kod)}" title="Kivétel az utalásból" aria-label="Kivétel az utalásból">${I.x}</button>` : ''}</div>`).join('')}`).join('') : `<div class="ures">Az utalás még üres – a kötések / számlák melletti UTALÁSHOZ gombbal adhatsz hozzá tételeket.</div>`}
            <div class="osszesen-sor"><span>ÖSSZESEN</span><span class="${negOszt(u.osszeg)}">${fmtOsszeg(u.osszeg, u.penznem)}</span></div>
          </div>
          <div class="kicsi szurke" style="margin-top:8px">A bankkártyás gomb ehhez az utaláshoz gyűjt: az UTALÁSHOZ gombok ide teszik a számlát, ha a számla cége és pénzneme egyezik. A lelakatolással a gyűjtés véget ér, és a gomb eltűnik. Ez az ablak nem navigál el.</div>
          ${masik.length ? `<details class="uk-masik"><summary>${I.transfer} Másik nyitott utaláshoz gyűjtök… <span class="szurke">(${masik.length})</span></summary><div class="sorkoz" style="margin-top:8px">${masik.slice(0, 30).map((x) => `<button class="btn btn-outline btn-blokk btn-sm" type="button" data-uk-valaszt="${x.id}" style="justify-content:space-between"><span><span class="mono">${esc(x.uid)}</span> · ${esc(x.ceg_nev)}</span><span>${fmtOsszeg(x.osszeg, x.penznem)} · ${x.db} db</span></button>`).join('')}</div></details>` : ''}
          <div class="lablec"><button class="btn btn-outline" type="button" data-m="ok">Bezárás</button><a class="btn btn-zold" ${link(`#/utalas/${u.id}`)}>${I.transfer} Utalás megnyitása</a></div>`;
        $('[data-m=ok]', m.el).addEventListener('click', () => m.bezar());
      } catch (e) {
        if (/nem található/.test(e.message)) UtalasKosar.torol();
        m.tartalom.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div><div class="lablec"><button class="btn btn-outline" type="button" data-m="ok">Bezárás</button></div>`;
        $('[data-m=ok]', m.el).addEventListener('click', () => m.bezar());
      }
    };
    m.el.addEventListener('click', async (e) => {
      const lak = e.target.closest('[data-uk-lakat]');
      if (lak) {
        lak.disabled = true;
        try {
          const r = await api('utalas_lezar', { utalas_id: a.id, lezar: true });
          UtalasKosar.torol(r.utalas.id);                // a gyűjtés kész: a bankkártyás gomb elengedi az utalást
          valtozott = true; m.bezar();
          toast(`${I.lock} <b class="mono">${esc(r.utalas.uid)}</b> lelakatolva – a gyűjtés befejezve, a státusz NYITOTT marad.<br><small>A bankkártyás gomb elengedte; ha újra gyűjtenél, nyisd ki a lakatot és nyomd meg az „Ehhez gyűjtök” gombot.</small>`, 'siker', 5000);
        } catch (err) { hibaToast(err); lak.disabled = false; }
        return;
      }
      const v = e.target.closest('[data-uk-valaszt]');
      if (v) {
        try { await utalasKosarValaszt(Number(v.dataset.ukValaszt)); a.id = UtalasKosar.get().id; valtozott = true; await rajzol(); } catch (err) { hibaToast(err); }
        return;
      }
      const b = e.target.closest('[data-uk-ki]');
      if (!b) return;
      b.disabled = true;
      try {
        const r = await api('utalas_szamla_eltavolit', { szamla_id: Number(b.dataset.ukKi) });
        valtozott = true;
        toast(`${esc(r.kod)} kivéve az utalásból (${esc(r.uid)}) – utalás összege: ${fmtOsszeg(r.osszeg)}`);
        await rajzol();
      } catch (err) { hibaToast(err); b.disabled = false; }
    });
    await rajzol();
  }
  /** A bankkártyás gyűjtő átállítása egy másik nyitott utalásra (Minden utalás / cég utalásai / kosár ablak) */
  async function utalasKosarValaszt(id) {
    const d = await api('utalas', { id });
    const u = d.utalas;
    if (u.statusz !== 'NYITOTT') throw new Error(`Az utalás (${u.uid}) már ${STATUSZ[u.statusz] || u.statusz} – csak nyitott utaláshoz lehet gyűjteni.`);
    if (u.lezarva) throw new Error(`Az utalás (${u.uid}) le van lakatolva – előbb nyisd ki a lakatot, ha gyűjteni akarsz bele.`);
    UtalasKosar.set({ id: u.id, uid: u.uid, penznem: u.penznem, ceg_nev: u.ceg_nev, ceg_id: u.ceg_id, osszeg: u.osszeg, db: u.db, lezarva: false });
    store.set(`aktivUtalas_${u.ceg_id}_${u.penznem}`, String(u.id));
    toast(`${I.card} Mostantól ehhez gyűjtök: <b class="mono">${esc(u.uid)}</b> · ${esc(u.ceg_nev)}<br><small>az UTALÁSHOZ gombok ide teszik a(z) ${esc(u.ceg_nev)} ${esc(u.penznem)} számláit</small>`, 'siker', 4500);
    return u;
  }
  // ================================================================= router
  function nav(h) { location.hash = h; }
  function route() {
    const h = location.hash.replace(/^#/, '') || '/';
    const [p, q] = h.split('?');
    const path = p.split('/').filter(Boolean);
    const query = {};
    (q || '').split('&').filter(Boolean).forEach((kv) => { const [k, v] = kv.split('='); query[decodeURIComponent(k)] = decodeURIComponent(v || ''); });
    return { path, query };
  }
  function qs(o) { const p = Object.keys(o).filter((k) => o[k] !== '' && o[k] != null).map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(o[k])); return p.length ? '?' + p.join('&') : ''; }
  /**
   * Egy query-kulcs kivétele a címsorból újratöltés / hashchange nélkül (history.replaceState).
   * A ?szamla= csak a számlához ugráskor kell (fülváltás + kiemelés) – ha bent maradna, minden újrarajzolás
   * visszakényszerítené a fület a számla státuszára, és nem lehetne másik fülre váltani (1.12-es javítás).
   */
  function queryTorol(kulcs) {
    const r = route();
    if (!(kulcs in r.query)) return;
    delete r.query[kulcs];
    try { history.replaceState(null, '', location.pathname + location.search + '#/' + r.path.join('/') + qs(r.query)); } catch (e) { /* mindegy */ }
  }

  // ================================================================= váz
  const CSONTVAZ = '<div class="csontvaz" aria-busy="true"><div class="cs cim"></div><div class="cs"></div><div class="cs"></div><div class="cs"></div></div>';
  const URES = '<img class="ures-kep" src="assets/favicon.svg" alt="">';
  function shell(o) {
    const app = $('#app');
    if (o.fab && !jog('ir')) o.fab = null;            // Üzletkötő: nincs felviteli gomb
    if (o.alsoSav && !jog('ir')) { o.alsoSav = ''; o.fabEmelt = false; }
    const fab = o.fab ? `<button class="fab ${o.fabEmelt ? 'emelt' : ''}" type="button" data-act="${esc(o.fab.act)}" ${o.fab.data || ''} aria-label="${esc(o.fab.cim)}" title="${esc(o.fab.cim)}">${I[o.fab.ikon]}</button>` : '';
    const n = Kosar.db();
    const nyomtatFab = o.vissza ? `<button class="fab fab-nyomtat${n ? '' : ' inaktiv'}${o.fab ? ' felett' : ''}${o.fabEmelt ? ' emelt' : ''}" type="button" data-act="nyomtat-pdf" aria-label="${n ? n + ' sor nyomtatása PDF-be' : 'Nyomtatási kosár üres – jelölj ki sorokat a nyomtató gombbal'}" title="${n ? n + ' sor nyomtatása PDF-be' : 'Nyomtatási kosár üres – jelölj ki sorokat a nyomtató gombbal'}">${I.print}<span class="jelveny" aria-hidden="true">${n || ''}</span></button>` : '';
    // piros X a nyomtató gomb bal alsó részén (külön gomb – gombba gomb nem ágyazható): a kosár azonnali ürítése
    const nyomtatUrit = nyomtatFab ? `<button class="fab-urit${n ? '' : ' rejtett'}${o.fab ? ' felett' : ''}${o.fabEmelt ? ' emelt' : ''}" type="button" data-act="nyomtat-urit" aria-label="Kosár ürítése" title="A nyomtatási kosár ürítése – minden sor kikerül">${I.x}</button>` : '';
    // gyűjtő gombok a nyomtató és a + gomb felett: utalási kosár (bankkártya), banki megfeleltetés (bank-épület)
    const utalasFab = UtalasKosar.html();
    const bankFab = BankKosar.html();
    app.innerHTML = `
      <header class="topbar">
        ${o.vissza ? `<a class="ikon-gomb" href="${esc(o.vissza)}" aria-label="Vissza">${I.back}</a>` : '<span class="ikon-hely"></span>'}
        <a class="cim" href="#/" title="Főoldal"><span class="logo">${I.logo}</span><span class="cim-szoveg"><span class="nev">Banina<b>PRO</b></span>${o.alcim ? `<span class="alcim">${o.alcim}</span>` : ''}</span></a>
        <span class="tolt"></span>
        <span class="felh">${esc(App.user ? App.user.felhasznalonev : '')}</span>
        <button class="ikon-gomb" type="button" data-act="menu" aria-label="Menü">${I.menu}</button>
      </header>
      <main class="nezet">${o.html || CSONTVAZ}</main>
      ${fab}
      ${nyomtatFab}
      ${nyomtatUrit}
      ${utalasFab}
      ${bankFab}
      ${o.alsoSav || ''}`;
    document.body.classList.toggle('van-utalas-fab', !!utalasFab);
    document.body.classList.toggle('van-bank-fab', !!bankFab);
    fabRendez();
    return $('main', app);
  }
  function nyitMenu() {
    const root = $('#modal-root');
    const d = document.createElement('div');
    d.innerHTML = `<div class="menu-hatter"></div><nav class="menu">
      <div class="felh-sor">${I.user} ${esc(App.user.nev || App.user.felhasznalonev)} · ${esc(szerepNev())}</div>
      <a href="#/">${I.home} Főoldal</a>
      <a href="#/bejovo">${I.in} Bejövő számlák</a>
      <a href="#/kimeno">${I.out} Kimenő számlák</a>
      <a href="#/osszevetes">${I.scale} Összevetés / Összesítő</a>
      <a href="#/utalasok">${I.transfer} Minden utalás</a>
      <a href="#/hataridok">${I.calendar} Fizetési határidők</a>
      ${jog('ir') ? `<a href="#/import">${I.sheet} Excel import (régi adatok)</a>` : ''}
      <button type="button" data-act="nyomtat-kosar">${I.print} Nyomtatási kosár <span class="menu-szam" data-kosar-db>${Kosar.db() || ''}</span></button>
      <div class="elvalaszto"></div>
      ${App.user.admin ? `<a href="#/admin">${I.gear} Admin (felhasználók, napló)</a>` : ''}
      <a href="#/profil">${I.key} Profil · eszközeim, jelszó</a>
      <button type="button" data-act="kilepes">${I.logout} Kijelentkezés</button>
    </nav>`;
    root.appendChild(d);
    const bezar = () => { if (d.parentNode) d.parentNode.removeChild(d); };
    $('.menu-hatter', d).addEventListener('click', bezar);
    $$('a,button', d).forEach((a) => a.addEventListener('click', bezar));
  }

  // ========================================================= WebAuthn / QR
  const WA = INIT.webauthn || {};
  const b64u = {
    enc(buf) { const u = new Uint8Array(buf); let s = ''; for (let i = 0; i < u.length; i++) s += String.fromCharCode(u[i]); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); },
    dec(str) { let t = String(str).replace(/-/g, '+').replace(/_/g, '/'); while (t.length % 4) t += '='; const bin = atob(t); const u = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i); return u.buffer; },
  };
  const waTamogatott = () => !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.get);
  async function waPlatformVan() {
    try { return waTamogatott() && await window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable(); } catch (e) { return false; }
  }
  function waHiba(e) {
    const n = e && e.name;
    if (n === 'NotAllowedError') return 'A biometrikus azonosítás megszakadt vagy időtúllépés történt. Próbáld újra.';
    if (n === 'InvalidStateError') return 'Ez az eszköz már regisztrálva van ehhez a fiókhoz.';
    if (n === 'SecurityError') return 'Biztonsági hiba: a WebAuthn csak HTTPS-en és a beállított domainen működik (RP_ID: ' + (WA.rp_id || '?') + ').';
    if (n === 'NotSupportedError') return 'Ez a böngésző/eszköz nem támogatja a passkey-t.';
    return (e && e.message) || 'Ismeretlen WebAuthn hiba.';
  }
  async function passkeyLetrehoz(opciok) {
    const pk = Object.assign({}, opciok, {
      challenge: b64u.dec(opciok.challenge),
      user: Object.assign({}, opciok.user, { id: b64u.dec(opciok.user.id) }),
      excludeCredentials: (opciok.excludeCredentials || []).map((c) => Object.assign({}, c, { id: b64u.dec(c.id) })),
    });
    const cred = await navigator.credentials.create({ publicKey: pk });
    const r = cred.response;
    return { id: cred.id, rawId: b64u.enc(cred.rawId), type: cred.type, response: { clientDataJSON: b64u.enc(r.clientDataJSON), attestationObject: b64u.enc(r.attestationObject), transports: r.getTransports ? r.getTransports() : [] } };
  }
  async function passkeyAlair(opciok) {
    const pk = Object.assign({}, opciok, {
      challenge: b64u.dec(opciok.challenge),
      allowCredentials: (opciok.allowCredentials || []).map((c) => Object.assign({}, c, { id: b64u.dec(c.id) })),
    });
    const cred = await navigator.credentials.get({ publicKey: pk });
    const r = cred.response;
    return { id: cred.id, rawId: b64u.enc(cred.rawId), type: cred.type, response: { clientDataJSON: b64u.enc(r.clientDataJSON), authenticatorData: b64u.enc(r.authenticatorData), signature: b64u.enc(r.signature), userHandle: r.userHandle ? b64u.enc(r.userHandle) : null } };
  }
  const eszkozNevJavaslat = () => { const ua = navigator.userAgent; if (/iPhone/i.test(ua)) return 'iPhone'; if (/iPad/i.test(ua)) return 'iPad'; if (/Android/i.test(ua)) return 'Android telefon'; if (/Macintosh/i.test(ua)) return 'Mac'; if (/Windows/i.test(ua)) return 'Windows PC'; return 'Eszköz'; };
  const fmtMp = (mp) => `${Math.floor(mp / 60)}:${String(mp % 60).padStart(2, '0')}`;
  const QR = { poll: null, ido: null };
  function qrLeallit() { clearInterval(QR.poll); clearInterval(QR.ido); QR.poll = QR.ido = null; }

  function loginKeret(belso) {
    document.body.classList.remove('van-utalas-fab');
    $('#app').innerHTML = `<div class="belepes"><div class="doboz" data-belepes>
      <span class="logo">${I.logo}</span>
      <h1>Banina<b>PRO</b></h1>${belso}</div></div>`;
  }

  /** QR-kódos, jelszó nélküli belépés indítása (felhasználónév után) */
  async function qrBelepesInditas(nev) {
    qrLeallit();
    const doboz = $('[data-belepes]');
    doboz.querySelector('[data-belepes-belso]').innerHTML = '<div class="toltes"></div>';
    let r;
    try { r = await api('qr_kerelem', { felhasznalonev: nev }); }
    catch (e) {
      if (e.kod === 'QR_KIKAPCSOLVA') { App.qrBelepes = false; viewLogin('jelszo', nev); toast(esc(e.message), '', 6000); return; }
      doboz.querySelector('[data-belepes-belso]').innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div><button class="btn btn-outline btn-blokk" type="button" data-act="belepes-vissza">Vissza</button>`; return; }
    const platform = await waPlatformVan();
    let hatra = r.lejarat_mp;
    doboz.querySelector('[data-belepes-belso]').innerHTML = `
      <p class="qr-felh">${I.user} <b>${esc(nev)}</b> <a href="#" data-act="belepes-vissza" class="kicsi">másik</a></p>
      <div class="qr-cim">Olvasd be a telefonoddal</div>
      <div class="qr-kep" data-qr-token="${esc(r.token)}">${window.BaninaQR ? window.BaninaQR.svg(r.url) : ''}</div>
      <div class="qr-kod">Egyeztető szám: <b>${esc(r.kod)}</b></div>
      <div class="qr-allapot" data-qr-allapot><span class="pont"></span> Várakozás a telefonos jóváhagyásra… <span class="qr-ido" data-qr-ido>${fmtMp(hatra)}</span></div>
      <p class="kicsi szurke kozepre" style="margin:8px 0 0">A telefonon a beolvasás után Face ID / ujjlenyomat kell. A kód 1× használható, ${Math.round(r.lejarat_mp / 60)} percig érvényes.</p>
      ${platform ? `<button class="btn btn-outline zold btn-blokk" type="button" data-act="belepes-helyi" data-nev="${esc(nev)}" style="margin-top:12px">${I.key}<span class="btn-szoveg">Belépés ezen az eszközön<small>Face ID · Touch ID · Windows Hello</small></span></button>` : ''}
      <div class="qr-lablec"><a href="#" data-act="belepes-jelszo" data-nev="${esc(nev)}">Jelszóval (csak ha még nincs regisztrált eszköz)</a></div>`;
    const allapot = $('[data-qr-allapot]', doboz);
    const veg = (html, ujra) => { qrLeallit(); allapot.innerHTML = html + (ujra ? ` <button class="btn btn-sm btn-sarga" type="button" data-act="belepes-qr-ujra" data-nev="${esc(nev)}">Új kód</button>` : ''); $('.qr-kep', doboz).classList.add('lejart'); };
    QR.ido = setInterval(() => { hatra = Math.max(0, hatra - 1); const el = $('[data-qr-ido]', doboz); if (el) el.textContent = fmtMp(hatra); if (hatra <= 0) veg('Lejárt a kód.', true); }, 1000);
    QR.poll = setInterval(async () => {
      try {
        const a = await api('qr_allapot', { token: r.token });
        if (a.statusz === 'BELEPVE') { qrLeallit(); belepve(a); hataridoAdat = null; App.cegek = null; toast(`Beléptél${a.eszkoz ? ' – jóváhagyta: ' + esc(a.eszkoz) : ''}`, 'siker'); render(); }
        else if (a.statusz === 'EXPIRED') veg('Lejárt a kód.', true);
        else if (a.statusz === 'DENIED') veg('A telefonon elutasítottad a belépést.', true);
      } catch (e) { if (e.kod === 'TOKEN') veg(esc(e.message), true); }
    }, 1500);
  }

  /** Belépés ugyanazon az eszközön passkey-jel */
  async function helyiPasskeyBelepes(nev) {
    const o = await api('passkey_belepes_opciok', { felhasznalonev: nev });
    if (!o.van_passkey) throw new Error('Ehhez a felhasználóhoz ezen az eszközön nincs regisztrált passkey. Olvasd be a QR-kódot a telefonoddal, vagy lépj be jelszóval és regisztráld ezt az eszközt a Profil menüben.');
    const asr = await passkeyAlair(o.opciok);
    const r = await api('passkey_belepes', { token: o.token, assertion: asr });
    qrLeallit(); belepve(r); hataridoAdat = null; App.cegek = null; toast('Beléptél passkey-jel', 'siker'); render();
  }

  // ================================================================ nézetek
  function viewLogin(mod, nev) {
    qrLeallit();
    const biztonsagos = WA.biztonsagos !== false;
    // 1.14: ha a szerver léptetett ki (bezárt böngésző, csend, tétlenség), megmondjuk, miért – a név előtöltve
    const lejart = App.lejart && App.lejart.oka ? `<div class="info-doboz lejart-doboz">${I.lock}<span>${esc(App.lejart.oka)} <b>Lépj be újra.</b></span></div>` : '';
    nev = nev || (App.lejart && App.lejart.nev && App.lejart.nev !== '-' ? App.lejart.nev : '');
    if (!App.qrBelepes) mod = 'jelszo';   // 1.15: az admin kikapcsolta a QR-kódos belépést
    if (mod === 'jelszo') {
      loginKeret(`<div data-belepes-belso><p>${App.qrBelepes ? 'Belépés jelszóval <span class="kicsi">(csak amíg nincs regisztrált eszköz)</span>' : 'Számla-nyilvántartás · belépés jelszóval'}</p>${lejart}
        ${!App.qrBelepes && INIT.hiba ? `<div class="hiba-doboz">${esc(INIT.hiba)}</div>` : ''}
        <form data-form="belepes" autocomplete="off">
          <div class="mezo"><label>Felhasználónév</label><input type="text" name="felhasznalonev" value="${esc(nev || '')}" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" required ${nev ? '' : 'autofocus'}></div>
          <div class="mezo"><label>Jelszó</label>${jelszoMezo('jelszo', { autofocus: !!nev })}</div>
          <div class="hiba-doboz rejtett" data-hiba></div>
          <button class="btn btn-blokk" type="submit">Belépés</button>
          ${App.qrBelepes ? '<div class="qr-lablec"><a href="#" data-act="belepes-vissza">Vissza a QR-kódos belépéshez</a></div>' : ''}
        </form></div>`);
      return;
    }
    loginKeret(`<div data-belepes-belso><p>Számla-nyilvántartás · jelszó nélküli belépés</p>${lejart}
      ${INIT.hiba ? `<div class="hiba-doboz">${esc(INIT.hiba)}</div>` : ''}
      ${!biztonsagos ? '<div class="hiba-doboz">A biometrikus (passkey) belépéshez HTTPS szükséges – az oldal most nem biztonságos kapcsolaton fut.</div>' : ''}
      <form data-form="belepes-nev">
        <div class="mezo"><label>Felhasználónév</label><input type="text" name="felhasznalonev" value="${esc(nev || '')}" autocomplete="username webauthn" autocapitalize="none" autocorrect="off" required autofocus></div>
        <div class="hiba-doboz rejtett" data-hiba></div>
        <button class="btn btn-blokk" type="submit">${I.key} Belépés</button>
        <p class="kicsi szurke kozepre" style="margin:12px 0 0">A Belépés után egy QR-kód jelenik meg: olvasd be a telefonoddal, majd hagyd jóvá Face ID-val / ujjlenyomattal.</p>
        <div class="qr-lablec"><a href="#" data-act="belepes-jelszo">Jelszóval (csak ha még nincs regisztrált eszköz)</a></div>
      </form></div>`);
  }

  /** TELEFON: belépés jóváhagyása (a QR beolvasása után nyílik meg) */
  async function viewQrJovahagyas(token) {
    qrLeallit();
    loginKeret('<div data-belepes-belso><div class="toltes"></div></div>');
    const box = $('[data-belepes-belso]');
    let info;
    try { info = await api('qr_info', { token }); }
    catch (e) { box.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div><a class="btn btn-outline btn-blokk" href="#/">Vissza</a>`; return; }
    const fej = `<div class="jov-cim">Belépés jóváhagyása</div>
      <div class="jov-adat"><span>Felhasználó</span><b>${esc(info.felhasznalonev)}</b></div>
      <div class="jov-adat"><span>Kérő gép</span><b>${esc(info.keres_eszkoz)}</b></div>
      <div class="jov-adat"><span>IP-cím</span><b>${esc(info.keres_ip || '?')}</b></div>`;
    if (info.statusz !== 'PENDING') {
      const sz = { EXPIRED: 'Ez a belépési kód lejárt. Kérj újat a gépen.', USED: 'Ez a belépés már megtörtént.', APPROVED: 'Ez a kérés már jóvá van hagyva.', DENIED: 'Ezt a kérést elutasítottad.' }[info.statusz] || 'A kérés nem érvényes.';
      box.innerHTML = `${fej}<div class="info-doboz" style="margin-top:14px">${esc(sz)}</div>`; return;
    }
    if (!waTamogatott()) { box.innerHTML = `${fej}<div class="hiba-doboz" style="margin-top:14px">Ez a böngésző nem támogatja a biometrikus (passkey) azonosítást. iPhone-on Safari (iOS 16+), Androidon Chrome ajánlott.</div>`; return; }
    if (!info.van_passkey) { box.innerHTML = `${fej}<div class="hiba-doboz" style="margin-top:14px">Ehhez a felhasználónévhez nincs regisztrált telefon/eszköz. Kérj <b>eszköz-regisztrációs kódot</b> az admintól, vagy – ha jelszóval be tudsz lépni – a Profil menüben regisztráld ezt a telefont.</div><div class="jov-adat"><span>Egyeztető szám a gépen</span><b>${esc(info.kod)}</b></div>`; return; }
    box.innerHTML = `${fej}
      <div class="qr-kod-nagy" title="Ennek a számnak egyeznie kell a gép képernyőjén látható számmal">${esc(info.kod)}</div>
      <p class="kicsi szurke kozepre">Ez a szám egyezik a gép képernyőjén látható egyeztető számmal? Csak akkor hagyd jóvá! <span data-jov-ido>${fmtMp(info.lejar_mp)}</span></p>
      <div class="hiba-doboz rejtett" data-hiba></div>
      <button class="btn btn-blokk" type="button" data-act="qr-jovahagy" data-token="${esc(token)}" style="min-height:58px">${I.check}<span class="btn-szoveg">Jóváhagyás<small>Face ID · ujjlenyomat · eszközzár</small></span></button>
      <button class="btn btn-outline piros btn-blokk" type="button" data-act="qr-elutasit" data-token="${esc(token)}" style="margin-top:10px">Nem én vagyok – elutasítás</button>`;
    QR.opciok = info.opciok;
    let hatra = info.lejar_mp;
    QR.ido = setInterval(() => { hatra = Math.max(0, hatra - 1); const el = $('[data-jov-ido]'); if (el) el.textContent = fmtMp(hatra); if (hatra <= 0) { qrLeallit(); box.innerHTML = `${fej}<div class="info-doboz" style="margin-top:14px">Ez a belépési kód lejárt. Kérj újat a gépen.</div>`; } }, 1000);
  }

  /** TELEFON: eszköz regisztrálása admin által kiadott kóddal */
  async function viewRegisztral(token) {
    qrLeallit();
    loginKeret('<div data-belepes-belso><div class="toltes"></div></div>');
    const box = $('[data-belepes-belso]');
    let info;
    try { info = await api('regisztracio_info', { token }); }
    catch (e) { box.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div><a class="btn btn-outline btn-blokk" href="#/">Vissza</a>`; return; }
    if (info.statusz !== 'PENDING') { box.innerHTML = `<div class="jov-cim">Eszköz regisztrálása</div><div class="info-doboz">${info.statusz === 'USED' ? 'Ezzel a kóddal már regisztráltak egy eszközt.' : 'Ez a regisztrációs kód lejárt vagy érvénytelen. Kérj újat az admintól.'}</div>`; return; }
    if (!waTamogatott()) { box.innerHTML = `<div class="jov-cim">Eszköz regisztrálása</div><div class="hiba-doboz">Ez a böngésző nem támogatja a passkey-t. iPhone-on Safari (iOS 16+), Androidon Chrome ajánlott.</div>`; return; }
    box.innerHTML = `<div class="jov-cim">Eszköz regisztrálása</div>
      <div class="jov-adat"><span>Felhasználó</span><b>${esc(info.felhasznalonev)}${info.nev ? ' · ' + esc(info.nev) : ''}</b></div>
      <p class="kicsi szurke">Ezután ezzel a telefonnal, Face ID-val / ujjlenyomattal tudod jóváhagyni a belépéseidet. A kód még <span data-jov-ido>${fmtMp(info.lejar_mp)}</span> percig érvényes.</p>
      <div class="mezo"><label>Az eszköz neve</label><input type="text" name="eszkoz_nev" value="${esc(eszkozNevJavaslat())}" maxlength="100"></div>
      <div class="hiba-doboz rejtett" data-hiba></div>
      <button class="btn btn-blokk" type="button" data-act="regisztral" data-token="${esc(token)}" style="min-height:58px">${I.key}<span class="btn-szoveg">Regisztrálás<small>Face ID · ujjlenyomat · eszközzár</small></span></button>`;
    QR.opciok = info.opciok;
    let hatra = info.lejar_mp;
    QR.ido = setInterval(() => { hatra = Math.max(0, hatra - 1); const el = $('[data-jov-ido]'); if (el) el.textContent = fmtMp(hatra); }, 1000);
  }

  function viewHome() {
    shell({ html: '' });
    $('main').className = '';
    $('main').innerHTML = `<div class="fooldal">
      <div class="udv"><div class="szia">Szia, ${esc((App.user.nev || App.user.felhasznalonev).split(' ').pop())}!</div><div class="datum">${esc(maiDatumSzoveg())}</div></div>
      <div class="felso">
        <a class="csempe narancs" href="#/bejovo"><span class="csip-szam" data-szam="fizetendo_db" title="FIZETENDŐ bejövő számlák"></span><span class="ikon">${I.in}</span><span>Bejövő<br>számlák</span></a>
        <a class="csempe sarga" href="#/osszevetes"><span class="ikon">${I.scale}</span><span>Összevetés /<br>Összesítő</span></a>
        <a class="csempe zold" href="#/kimeno"><span class="csip-szam" data-szam="kimeno_nyitott_db" title="NYITOTT kimenő számlák"></span><span class="ikon">${I.out}</span><span>Kimenő<br>számlák</span></a>
      </div>
      <div class="also">
        <a class="pill-gomb" href="#/utalasok">${I.transfer} Minden utalás <span class="csip-szam" data-szam="nyitott_utalas_db" title="nyitott utalások"></span></a>
        <a class="pill-gomb" href="#/hataridok">${I.calendar} Fizetési határidők <span class="csip-szam piros" data-szam="lejart_db" title="lejárt határidejű számlák"></span></a>
      </div>
      ${keresoDoboz()}
    </div>`;
    api('osszefoglalo').then((d) => { $$('.csip-szam[data-szam]').forEach((el) => { const v = d[el.dataset.szam]; el.textContent = v ? String(v) : ''; }); }).catch(() => {});
    keresoVissza();
  }

  // ================================================================ KERESŐ
  /** Főoldali multifunkciós kereső: 3 karaktertől él, javaslatokat ad, csak kattintásra ugrik. */
  const KERESO_MIN = 3;
  const KERESO_TIPUS = {
    bejovo: { cim: 'Bejövő számlák', egy: 'BEJÖVŐ SZÁMLA', ikon: 'in', oszt: 'narancs' },
    kimeno: { cim: 'Kimenő számlák', egy: 'KIMENŐ SZÁMLA', ikon: 'out', oszt: 'zold' },
    ceg: { cim: 'Cégek', egy: 'CÉG', ikon: 'factory', oszt: 'sotet' },
    kotes: { cim: 'Kötések', egy: 'KÖTÉS', ikon: 'rope', oszt: 'sarga' },
    utalas: { cim: 'Utalások', egy: 'UTALÁS', ikon: 'transfer', oszt: 'sotet' },
  };
  const KERESO_SEGIT = 'Számlaszám, azonosító (pl. 2026-EUR-000001-K0001), cégnév, régi K, összeg (pl. 55 EUR) vagy dátum (pl. 2026.03.15) – elég egy részlet is.';
  function keresoDoboz() {
    return `<div class="kereso-doboz" data-kereso>
      <div class="kereso-mezo">
        <span class="ikon">${I.search}</span>
        <input type="search" placeholder="Kereső" aria-label="Kereső" autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" maxlength="100" enterkeyhint="search" data-kereso-input value="${esc(App.kereso.q)}">
        <button class="torol ${App.kereso.q ? '' : 'rejtett'}" type="button" data-act="kereso-torol" aria-label="Keresés törlése" title="Törlés">${I.x}</button>
      </div>
      <div class="kereso-segit" data-kereso-segit>${KERESO_SEGIT}</div>
      <div class="kereso-talalatok rejtett" data-kereso-talalatok role="listbox" aria-label="Találatok"></div>
    </div>`;
  }
  /** Ékezet- és kis/nagybetű-független kiemelés: a talált részlet <mark>-ba kerül */
  const hajt = (s) => String(s == null ? '' : s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  function kiemelSzoveg(s, q) {
    s = String(s == null ? '' : s);
    const fq = hajt(q).trim();
    if (!fq) return esc(s);
    const chars = Array.from(s);
    let folded = ''; const map = [];
    chars.forEach((ch, i) => { const f = hajt(ch); for (const c of f) { folded += c; for (let k = 0; k < c.length; k++) map.push(i); } });
    const idx = folded.indexOf(fq);
    if (idx < 0) return esc(s);
    const a = map[idx], b = map[Math.min(idx + fq.length - 1, map.length - 1)] + 1;
    return esc(chars.slice(0, a).join('')) + '<mark>' + esc(chars.slice(a, b).join('')) + '</mark>' + esc(chars.slice(b).join(''));
  }
  /** A találati lista visszaállítása, ha a főoldalra visszalépünk (a kereső „emlékszik”) */
  function keresoVissza() {
    const inp = $('[data-kereso-input]');
    if (!inp) return;
    if (App.kereso.q && App.kereso.adat) keresoRajzol(App.kereso.adat);
    else if (App.kereso.q && App.kereso.q.length >= KERESO_MIN) keresoFut(App.kereso.q, true);
  }
  let keresoIdo = null;
  function keresoInput(inp) {
    const q = inp.value.replace(/\s+/g, ' ').trimStart();
    App.kereso.q = q;
    const torol = $('[data-act=kereso-torol]'); if (torol) torol.classList.toggle('rejtett', !q);
    clearTimeout(keresoIdo);
    const segit = $('[data-kereso-segit]'), box = $('[data-kereso-talalatok]');
    if (q.trim().length < KERESO_MIN) {
      App.kereso.adat = null;
      if (box) { box.classList.add('rejtett'); box.innerHTML = ''; }
      if (segit) segit.innerHTML = q.trim().length ? `Még <b>${KERESO_MIN - q.trim().length}</b> karakter, és jönnek a találatok…` : KERESO_SEGIT;
      return;
    }
    if (segit) segit.innerHTML = `<span class="kereso-toltes"></span> Keresés: „${esc(q.trim())}”…`;
    keresoIdo = setTimeout(() => keresoFut(q.trim()), 260);
  }
  async function keresoFut(q, csendes) {
    const my = ++App.kereso.sorszam;
    try {
      const d = await api('kereses', { q });
      if (my !== App.kereso.sorszam) return;           // közben tovább gépelt – ez már elavult
      if (($('[data-kereso-input]') || {}).value === undefined) return;
      App.kereso.adat = d;
      keresoRajzol(d);
    } catch (e) {
      if (my !== App.kereso.sorszam) return;
      const segit = $('[data-kereso-segit]');
      if (segit) segit.innerHTML = `<span class="negativ">${esc(e.message)}</span>`;
      if (!csendes) hibaToast(e);
    }
  }
  function keresoSor(x, q) {
    const t = KERESO_TIPUS[x.tipus] || KERESO_TIPUS.bejovo;
    let fo = '', al = '', harmadik = '';
    if (x.tipus === 'bejovo' || x.tipus === 'kimeno') {
      fo = `<span class="kod">${kiemelSzoveg(x.cim, q)}</span> ${badge(x.statusz)}${x.archiv ? '<span class="badge ARCHIV">ARCHÍV</span>' : ''}`;
      al = `<span class="szsz">„${kiemelSzoveg(x.szamlaszam, q)}”</span><span class="pont">·</span><span>${kiemelSzoveg(x.ceg_nev, q)}</span>`;
      harmadik = `<b class="${negOszt(x.osszeg)}">${fmtOsszeg(x.osszeg, x.penznem)}</b>${Number(x.reszt) > 0 && nyitottSzamla(x) ? `<span class="pont">·</span><span>hátralék <b>${fmtOsszeg(x.hatralek, x.penznem)}</b></span>` : ''}<span class="pont">·</span><span>kelt ${fmtDatum(x.datum)}</span>${(x.statusz === 'FIZETVE' || x.statusz === 'BESZAMITVA') && x.fizetve_datum ? `<span class="pont">·</span><span>${x.tipus === 'kimeno' ? 'fizetve' : 'utalva'} ${fmtDatum(x.fizetve_datum)}</span>` : ''}${x.regi_k ? `<span class="pont">·</span><span>régi K: ${kiemelSzoveg(x.regi_k, q)}</span>` : ''}`;
    } else if (x.tipus === 'ceg') {
      const irany = x.irany === 'kimeno' ? `${I.out} Kimenő számlái${x.kimeno_db ? ` (${x.kimeno_db})` : ''}` : `${I.in} Bejövő számlái${x.kotes_db ? ` (${x.kotes_db} kötés)` : ''}`;
      fo = `<span class="nev">${kiemelSzoveg(x.cim, q)}</span>${x.aktiv ? '' : ' <span class="badge">inaktív</span>'}`;
      al = `<span class="irany ${x.irany === 'kimeno' ? 'zold' : 'narancs'}">${irany}</span>${x.partnerkod ? `<span class="pont">·</span><span class="mono">P${kiemelSzoveg(x.partnerkod, q)}</span>` : ''}`;
    } else if (x.tipus === 'kotes') {
      fo = `<span class="kod">${kiemelSzoveg(x.cim, q)}</span>${x.archiv ? ' <span class="badge ARCHIV">ARCHÍV</span>' : ''}`;
      al = `${x.megnevezes ? `<span>${kiemelSzoveg(x.megnevezes, q)}</span><span class="pont">·</span>` : ''}<span>${kiemelSzoveg(x.ceg_nev, q)}</span>`;
      harmadik = `<span>${x.szamla_db} számla</span><span class="pont">·</span><b class="${negOszt(x.osszeg)}">${fmtOsszeg(x.osszeg, x.penznem)}</b>${x.regi_kod ? `<span class="pont">·</span><span>régi kötés ID: ${kiemelSzoveg(x.regi_kod, q)}</span>` : ''}`;
    } else if (x.tipus === 'utalas') {
      fo = `<span class="kod">${kiemelSzoveg(x.cim, q)}</span> ${badge(x.statusz)}`;
      al = `<span>${kiemelSzoveg(x.ceg_nev, q)}</span>`;
      harmadik = `<span>${x.szamla_db} számla</span><span class="pont">·</span><b>${fmtOsszeg(x.osszeg, x.penznem)}</b>${x.utalva_datum ? `<span class="pont">·</span><span>utalva ${fmtDatum(x.utalva_datum)}</span>` : ''}`;
    }
    const talalat = x.talalat && !/^(azonosító|számlaszám|név)$/.test(x.talalat) ? `<span class="kereso-talalt">${I.check} ${kiemelSzoveg(x.talalat, q)}</span>` : '';
    return `<a class="kereso-sor ${t.oszt}" href="${esc(x.href)}" role="option" data-act="kereso-ugras" data-tipus="${esc(x.tipus)}" data-id="${x.id}" data-cim="${esc(x.cim)}">
      <span class="tip"><span class="ikon">${I[t.ikon]}</span><span class="cimke">${t.egy}</span></span>
      <span class="test"><span class="fo">${fo}</span><span class="al">${al}</span>${harmadik ? `<span class="al harmadik">${harmadik}</span>` : ''}${talalat}</span>
      <span class="nyil">${I.chev}</span>
    </a>`;
  }
  function keresoRajzol(d) {
    const box = $('[data-kereso-talalatok]'), segit = $('[data-kereso-segit]');
    if (!box) return;
    const q = d.q || '';
    box.dataset.q = q;
    const tipusok = Object.keys(KERESO_TIPUS).filter((t) => d.talalatok && d.talalatok[t] && d.talalatok[t].db);
    if (!tipusok.length) {
      box.classList.remove('rejtett');
      box.innerHTML = `<div class="kereso-ures">${URES}<b>Nincs találat erre: „${esc(q)}”</b><small>Próbáld rövidebb részlettel – pl. csak a számlaszám néhány számjegyével, a cég nevének elejével, vagy egy pontos összeggel (55 EUR).</small></div>`;
      if (segit) segit.innerHTML = KERESO_SEGIT;
      return;
    }
    const ert = d.ertelmezes || {};
    const magyarazat = [ert.datum ? `dátumként is: ${esc(ert.datum)}` : '', ert.osszeg ? `összegként is: ${esc(ert.osszeg)}` : ''].filter(Boolean).join(' · ');
    if (segit) segit.innerHTML = `<b>${d.osszes}</b> találat erre: „${esc(q)}”${magyarazat ? ` <span class="szurke">(${magyarazat})</span>` : ''} – <b>kattints</b> arra, amelyikhez ugrani szeretnél.`;
    box.classList.remove('rejtett');
    box.innerHTML = tipusok.map((t) => {
      const g = d.talalatok[t], c = KERESO_TIPUS[t];
      return `<div class="kereso-csoport ${c.oszt}"><div class="fej">${I[c.ikon]} ${c.cim} <span class="db">${g.db}${g.tobb ? '+' : ''}</span>${g.tobb ? `<span class="tobb">csak az első ${g.lista.length} látszik – írj be többet a pontosításhoz</span>` : ''}</div>${g.lista.map((x) => keresoSor(x, q)).join('')}</div>`;
    }).join('');
  }
  function keresoBillentyu(e) {
    const box = $('[data-kereso-talalatok]');
    if (!box || box.classList.contains('rejtett')) { if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); } return; }
    const sorok = $$('.kereso-sor', box);
    if (!sorok.length) { if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); } return; }
    const akt = sorok.findIndex((s) => s.classList.contains('aktiv'));
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const uj = e.key === 'ArrowDown' ? Math.min(sorok.length - 1, akt + 1) : Math.max(0, akt - 1);
      sorok.forEach((s, i) => s.classList.toggle('aktiv', i === uj));
      sorok[uj].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (akt >= 0) sorok[akt].click();            // csak a nyilakkal kiválasztott sorra ugrik – magától soha
      else e.target.blur();                        // telefonon: a billentyűzet eltűnik, a találatok látszanak
    } else if (e.key === 'Escape') {
      sorok.forEach((s) => s.classList.remove('aktiv'));
    }
  }

  // ------------------------------------------------------------ BEJÖVŐ: cégek
  async function viewBejovoCegek() {
    const main = shell({ alcim: 'Bejövő számlák · Cégek', vissza: '#/', fab: { act: 'ceg-uj', ikon: 'factory', cim: 'Új cég' } });
    try {
      const cegek = await cegekBetolt(true);
      main.innerHTML = `<h1>Bejövő számlák <small>· válassz céget</small></h1>
        ${cegek.length ? '' : `<div class="ures">${URES}Még nincs cég. A jobb alsó gyár-gombbal hozz létre egyet.</div>`}
        ${cegek.map((c) => `<div class="kartya kattinthato" data-act="nav" data-href="#/bejovo/ceg/${c.id}">
          <div class="fejsor"><div class="balra">${avatar(c.nev)}<div style="min-width:0"><div class="cim">${esc(c.nev)}</div>
            <div class="alsor"><span>${c.kotes_db} kötés (${c.nyitott_kotes_db} nyitott)</span>${c.nyitott_utalas_db ? `<span>${c.nyitott_utalas_db} nyitott utalás</span>` : ''}${c.partnerkod ? `<span class="mono" title="Partnerkód">P${esc(c.partnerkod)}</span>` : ''}</div></div></div>
            <div class="osszeg">${c.be_EUR ? `<div class="${negOszt(c.be_EUR)}">${fmtOsszeg(c.be_EUR, 'EUR')}</div>` : ''}${c.be_HUF ? `<div class="${negOszt(c.be_HUF)}">${fmtOsszeg(c.be_HUF, 'HUF')}</div>` : ''}${!c.be_EUR && !c.be_HUF ? '<small>nincs fizetendő</small>' : '<small>fizetendő</small>'}</div>
            <span class="nyil">${I.chev}</span></div>
          <div class="muveletek"><span class="tolt"></span><button class="btn btn-outline btn-sm" type="button" data-act="ceg-szerk" data-id="${c.id}">${I.edit} ${jog('ir') ? 'Szerkesztés' : 'Megjegyzés'}</button>${nyomtatGomb('ceg', c.id, c.nev)}</div>
        </div>`).join('')}`;
    } catch (e) { main.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }

  function haladSav(k) {
    if (!k.db) return '';
    const pct = k.teljes > 0 ? Math.max(0, Math.min(100, Math.round((k.fizetett / k.teljes) * 100))) : (k.statusz === 'FIZETETT' ? 100 : 0);
    return `<div class="halad" title="Kifizetve: ${pct}%"><span style="width:${pct}%"></span></div><div class="halad-cimke"><span>kifizetve ${pct}%</span><span>${k.db - k.nyitott_db} / ${k.db} számla rendezve</span></div>`;
  }
  // -------------------------------------------------------- BEJÖVŐ: kötések
  /** A kötések oldal füle számla-szinten (időszak-szűrésnél): darab + összeg egy kötés / pénznem összesítőjéből */
  const KOTES_FUL_SZAMLA = {
    NYITOTT: (o) => ({ db: o.nyitott_db, osszeg: o.nyitott }),
    FIZETETT: (o) => ({ db: o.fizetett_db, osszeg: o.fizetett_szamlak }),
    MIND: (o) => ({ db: o.db, osszeg: o.teljes }),
  };
  const KOTES_FUL_NEV = { NYITOTT: 'nyitott ', FIZETETT: 'fizetett / beszámított ', MIND: '' };
  /**
   * A cég egyenlege a kötések oldal tetején, pénznemenként – a státusz-fül és az időszak-szűrő szerint.
   * Állapot vizsgálatnál (isz.mezo = 'allapot') a VIZSGÁLT NAPI egyenleg (a kötés és a kimenő oldal is ezt mutatja).
   */
  function cegEgyenlegDoboz(eg, ful, isz, cim) {
    const pnek = Object.keys(eg || {});
    const allapot = !!isz && isz.mezo === 'allapot';
    const sor = (c, v, pn, oszt) => `<div class="ce-sor ${oszt}"><span>${c}</span><b class="${negOszt(v).trim()}">${fmtOsszeg(v, pn)}</b></div>`;
    const nemLetezett = (e) => (allapot && e.nem_letezett_db ? ` · ${e.nem_letezett_db} még nem létezett` : '');
    const blokk = (pn) => {
      const e = eg[pn];
      if (ful === 'NYITOTT') return `<div class="ce-pn" data-pn="${esc(pn)}">${sor(allapot ? 'Fizetetlen összesen' : 'Nyitott összesen', e.nyitott, pn, 'fo nyitott')}<div class="ce-meta">${e.nyitott_db} ${allapot ? 'fizetetlen' : 'nyitott'} számla${e.utalas_alatt ? ` · ebből utalás alatt ${fmtOsszeg(e.utalas_alatt, pn)}` : ''}${allapot && e.reszt ? ` · részteljesítés után` : ''}</div></div>`;
      if (ful === 'FIZETETT' || ful === 'FIZETVE') return `<div class="ce-pn" data-pn="${esc(pn)}">${sor('Fizetett összesen', e.fizetett, pn, 'fo fizetett')}<div class="ce-meta">${e.fizetett_db} fizetett / beszámított számla${e.reszt ? ` · ebből részteljesítés ${fmtOsszeg(e.reszt, pn)}` : ''}</div></div>`;
      return `<div class="ce-pn harom" data-pn="${esc(pn)}">${sor('Teljes forgalom', e.teljes, pn, 'fo')}${sor(allapot ? 'Fizetetlen' : 'Nyitott', e.nyitott, pn, 'nyitott')}${sor('Fizetett', e.fizetett, pn, 'fizetett')}<div class="ce-meta">${e.db} számla: ${e.nyitott_db} ${allapot ? 'fizetetlen' : 'nyitott'} · ${e.fizetett_db} fizetett / beszámított${nemLetezett(e)}</div></div>`;
    };
    const hatokor = !isz ? 'teljes időszak' : allapot ? `<b>${fmtDatum(isz.nap)}</b> napi állapot · kelt ${fmtDatum(isz.tol)} – ${fmtDatum(isz.ig)}` : `${esc(IDOSZAK_MEZOK[isz.mezo])} szerint: ${fmtDatum(isz.tol)} – ${fmtDatum(isz.ig)}`;
    return `<div class="kartya ceg-egyenleg${allapot ? ' allapot' : ''}">
      <div class="ce-fej"><span class="ce-cim">${I.scale} ${esc(cim || 'A cég egyenlege')}</span><span class="ce-hatokor">${hatokor}</span></div>
      ${pnek.length ? pnek.map(blokk).join('') : `<div class="ce-meta">${isz ? 'Ebben az időszakban nincs számla.' : 'Még nincs számla.'}</div>`}
    </div>`;
  }
  /** Állapot vizsgálat: egy számla sora – a vizsgált napi állapot, a kelt, a tényleges fizetési nap és a vizsgált napi hátralék */
  function allapotSor(s, nap, link, tipus) {
    const nincs = s.allapot === 'MEG_NEM_LETEZETT';
    const fizetetlen = s.allapot === 'FIZETETLEN';
    const ertek = fizetetlen ? s.hatralek_napon : s.osszeg;
    const fiz = s.fizetve_datum ? `<span class="fiz-nap${s.fizetve_datum <= nap ? ' korabban' : ''}" title="${s.fizetve_becsult ? 'A régi, importált számlának nincs fizetési dátuma – a fizetési határidő számít' : 'A tényleges fizetés napja'}">${s.fizetve_becsult ? 'határidő ~' : 'fizetve'} ${fmtDatum(s.fizetve_datum)}</span>` : '';
    return `<div class="szamla-sor${nincs ? ' nem-letezett' : ''}" data-allapot-sor="${s.id}"><div class="bal"><a class="k" href="${link(s)}" title="Ugrás a számlához">${esc(s.kod)}</a><span class="szsz">„${esc(s.szamlaszam)}”</span>${badge(s.allapot)}<span class="kicsi szurke">kelt ${fmtDatum(s.kelt)}</span>${fiz}${fizetetlen && s.reszt_napon > 0 ? `<span class="kicsi szurke">eredeti ${fmtOsszeg(s.osszeg, s.penznem)} · részt. −${fmtOsszeg(s.reszt_napon, s.penznem)}</span>` : ''}</div><span class="o${negOszt(ertek)}">${fmtOsszeg(ertek, s.penznem)}</span>${nyomtatGomb(tipus, s.id, `${s.kod} · „${s.szamlaszam}”`)}</div>`;
  }
  /**
   * Állapot vizsgálat eredménye a szűrő-sávban (mindhárom oldalon): összegzés, „Állapot a kosárba” (egy tétel: a teljes
   * vizsgálat – a PDF-ben a vizsgált napi egyenleggel), Szűrés törlése, és a fül szerinti számlák listája.
   */
  function allapotEredmeny(d, ful, o) {
    const lista = d.szamlak.filter(ALLAPOT_FUL[ful] || ALLAPOT_FUL.MIND);
    const db = { FIZETETLEN: 0, rendezett: 0, MEG_NEM_LETEZETT: 0 };
    d.szamlak.forEach((s) => { db[ALLAPOT_RENDEZETT(s) ? 'rendezett' : s.allapot]++; });
    const ossz = {};
    lista.forEach((s) => { if (s.allapot !== 'MEG_NEM_LETEZETT') ossz[s.penznem] = (ossz[s.penznem] || 0) + Number(s.allapot === 'FIZETETLEN' ? s.hatralek_napon : s.osszeg); });
    const max = 3000;
    return `<span><b>${lista.length}</b> számla${o.extra || ''} · <b>${fmtDatum(d.nap)}</b> napi állapot: ${db.FIZETETLEN} fizetetlen · ${db.rendezett} fizetett · ${db.MEG_NEM_LETEZETT} még nem létezett${Object.keys(ossz).length ? ' · ' + Object.keys(ossz).map((p) => fmtOsszeg(ossz[p], p)).join(' · ') : ''}</span>
      <button class="btn btn-sarga btn-sm" type="button" data-act="idoszak-kosarba" ${d.szamlak.length ? '' : 'disabled'}>${I.print} Állapot a kosárba (${d.szamlak.length} számla)</button>
      <button class="btn btn-outline btn-sm" type="button" data-act="${o.torol}">${I.x} Szűrés törlése</button>
      ${lista.length > max ? `<div class="kicsi szurke">A lenti lista az első ${max} számlát mutatja, a PDF mindet tartalmazza.</div>` : ''}
      ${lista.length ? `<div class="reszletes idoszak-lista">${lista.slice(0, max).map((s) => allapotSor(s, d.nap, o.link, o.tipus)).join('')}</div>`
        : `<div class="kicsi szurke">${d.szamlak.length ? 'Ezen a fülön nincs számla – válts a MIND fülre.' : 'A kelt szerint ebben az időszakban nincs számla.'}</div>`}`;
  }
  /** Az állapot vizsgálat kosár-tétele + címkéje a szűrő-sáv adatai közé („Állapot a kosárba” gomb) */
  function allapotSavAdat(sav, d, nev) {
    if (!sav) return;
    const cimke = `${nev} · ${d.irany === 'BEJOVO' ? 'bejövő' : 'kimenő'} · ${idoszakCimke(d)}`;
    sav.dataset.talalat = JSON.stringify([allapotTetel({ i: d.irany, ceg: d.ceg.id, kotes: d.kotes ? d.kotes.id : 0, tol: d.tol, ig: d.ig, nap: d.nap }, cimke)]);
    sav.dataset.cimke = cimke;
  }
  /** Kötések oldal: az időszak-szűrés eredménye – a fül szerinti számlák (kosárba gomb + lista) */
  function kotesIdoszakEredmeny(sz, ful, cegId, kotesDb) {
    if (sz.mezo === 'allapot') return allapotEredmeny(sz, ful, { torol: 'idoszak-ceg-torol', tipus: 'bejovo', extra: ` · ${kotesDb} kötésben`, link: (s) => `#/bejovo/ceg/${cegId}/kotes/${s.kotes_pk}?szamla=${s.id}` });
    const db = Object.keys(sz.egyenleg).reduce((a, p) => a + KOTES_FUL_SZAMLA[ful](sz.egyenleg[p]).db, 0);
    const ossz = Object.keys(sz.osszegek).map((p) => fmtOsszeg(sz.osszegek[p], p)).join(' · ');
    const kosarba = (sz.osszes || sz.szamlak).length;
    return `<span><b>${db}</b> ${KOTES_FUL_NEV[ful]}számla · ${kotesDb} kötésben${ossz ? ' · ' + ossz : ''}</span>
      <button class="btn btn-sarga btn-sm" type="button" data-act="idoszak-kosarba" ${kosarba ? '' : 'disabled'}>${I.print} Mind a kosárba (${kosarba})</button>
      <button class="btn btn-outline btn-sm" type="button" data-act="idoszak-ceg-torol">${I.x} Szűrés törlése</button>
      ${sz.csonka ? `<div class="kicsi szurke">A lenti lista az első ${sz.szamlak.length} számlát mutatja, a „Mind a kosárba” mind a(z) ${kosarba} számlát beteszi.</div>` : ''}
      ${sz.szamlak.length ? `<div class="reszletes idoszak-lista">${sz.szamlak.map((s) => `<div class="szamla-sor"><div class="bal"><a class="k" href="#/bejovo/ceg/${cegId}/kotes/${s.kotes_pk}?szamla=${s.id}" title="Ugrás a számlához">${esc(s.kod)}</a><span class="szsz">„${esc(s.szamlaszam)}”</span>${badge(s.statusz)}<span class="kicsi szurke">${fmtDatum(idoszakDatum(s, sz.mezo))}</span></div><span class="o${negOszt(foErtek(s))}">${fmtOsszeg(foErtek(s), s.penznem)}</span>${nyomtatGomb('bejovo', s.id, `${s.kod} · „${s.szamlaszam}”`)}</div>`).join('')}</div>` : ''}`;
  }
  /**
   * Kötések oldal. Fül (NYITOTT / FIZETETT / MIND) + időszak = teljes szűrés: időszak nélkül a kötés státusza dönt;
   * időszakkal a fülnek megfelelő számlák az időszakban – csak az ilyet tartalmazó kötések látszanak, a nyomtatási
   * lista és a fejléc egyenlege is ezekből készül. helyben: újrarajzolás csontváz nélkül (fülváltás, szűrés).
   */
  let kotesekSorszam = 0;
  async function viewKotesek(cegId, helyben) {
    const sorsz = ++kotesekSorszam;
    const regi = helyben ? $('#app main.nezet') : null;
    const main = regi || shell({ alcim: 'Bejövő · Kötések', vissza: '#/bejovo', fab: { act: 'kotes-uj', ikon: 'rope', cim: 'Új kötés', data: `data-ceg="${cegId}"` } });
    if (regi) main.classList.add('frissul');
    try {
      const ful = App.kotesFul;
      const isz = App.kotesIdoszak && App.kotesIdoszak.cegId === cegId ? App.kotesIdoszak : null;
      // szűrés nélkül a dátummezőkbe beírt (még nem alkalmazott) értékek az újrarajzolás után is megmaradnak
      const regiSav = !isz && regi ? $('.idoszak-sav', regi) : null;
      const urlap = idoszakUrlap(regiSav);
      // állapot vizsgálatnál (1.19) a vizsgált napi állapot – ugyanazokkal az összesítő-mezőkkel, a fül a kliensen szűr
      const [k, c, sz] = await Promise.all([
        api('kotesek', { ceg_id: cegId }),
        api('ceg', { id: cegId }),
        isz ? (isz.mezo === 'allapot'
          ? api('allapot_vizsgalat', { irany: 'BEJOVO', ceg_id: cegId, tol: isz.tol, ig: isz.ig, nap: isz.nap })
          : api('bejovo_szamlak_idoszak', { ceg_id: cegId, mezo: isz.mezo, tol: isz.tol, ig: isz.ig, statusz: ful })).catch((e) => { App.kotesIdoszak = null; hibaToast(e); return null; }) : null,
      ]);
      if (sorsz !== kotesekSorszam) return;   // közben újabb rajzolás indult (pl. gyors fülváltás)
      const ny = c.nyitott_utalasok;
      const benne = sz ? (x, f) => !!sz.kotesek[x.id] && KOTES_FUL_SZAMLA[f](sz.kotesek[x.id]).db > 0 : (x, f) => KOTES_FUL[f](x);
      const kotesek = k.kotesek.filter((x) => benne(x, ful));
      const db = (f) => k.kotesek.filter((x) => benne(x, f)).length;
      const talalat = (x) => {
        if (!sz) return '';
        const t = KOTES_FUL_SZAMLA[ful](sz.kotesek[x.id]);
        if (sz.mezo === 'allapot') {
          const o = sz.kotesek[x.id];
          return `<div class="idoszak-talalat">${I.calendar}<span>Az időszakban: <b>${t.db}</b> ${KOTES_FUL_NEV[ful]}számla · ${fmtDatum(sz.nap)} napon fizetetlen: <b class="${negOszt(o.nyitott).trim()}">${fmtOsszeg(o.nyitott, x.penznem)}</b>${o.nem_letezett_db && ful === 'MIND' ? ` · ${o.nem_letezett_db} még nem létezett` : ''}</span></div>`;
        }
        return `<div class="idoszak-talalat">${I.calendar}<span>Az időszakban: <b>${t.db}</b> ${KOTES_FUL_NEV[ful]}számla · <b class="${negOszt(t.osszeg).trim()}">${fmtOsszeg(t.osszeg, x.penznem)}</b></span></div>`;
      };
      main.innerHTML = `<h1>${esc(k.ceg.nev)} <small>· kötések</small></h1>
        ${k.kotesek.length ? cegEgyenlegDoboz(sz ? sz.egyenleg : k.egyenleg, ful, sz ? isz : null) : ''}
        <div class="gomb-sor" style="margin-bottom:10px">
          <a class="btn btn-fekete" href="#/bejovo/ceg/${cegId}/utalasok">${I.transfer} UTALÁSOK${ny.length ? ` (${ny.length} nyitott)` : ''}</a>
          ${jog('ir') ? `<button class="btn btn-outline narancs" type="button" data-act="utalas-uj-menu" data-ceg="${cegId}">${I.plus} Új utalás</button>` : ''}
        </div>
        ${ny.length ? `<div class="csipek">${ny.map((u) => `<a class="csip narancs" href="#/utalas/${u.id}" title="${u.lezarva ? 'Nyitott utalás – lelakatolva (a gyűjtés befejezve)' : 'Nyitott utalás'}">${u.lezarva ? I.lock + ' ' : ''}${esc(u.uid)} · ${fmtOsszeg(u.osszeg, u.penznem)}${(UtalasKosar.get() || {}).id === u.id ? ' ' + I.card : ''}</a>`).join('')}</div>` : ''}
        ${k.kotesek.length ? statuszFulek([['NYITOTT', 'NYITOTT', `${db('NYITOTT')} kötés`], ['FIZETETT', 'FIZETETT', `${db('FIZETETT')} kötés`], ['MIND', 'MIND', `${db('MIND')} kötés`]], ful, 'kotes-ful') : ''}
        ${k.kotesek.length ? idoszakSav({ kulcs: 'ceg', cim: `A cég ${KOTES_FUL_NEV[ful]}számlái időszak szerint (minden kötésből) → nyomtatási kosárba`, act: 'idoszak-ceg', data: `data-ceg="${cegId}"`, ertek: sz ? isz : urlap, aktiv: !!sz, eredmeny: sz ? kotesIdoszakEredmeny(sz, ful, cegId, kotesek.length) : '' }) : ''}
        ${k.kotesek.length ? '' : `<div class="ures">${URES}Ennek a cégnek még nincs kötése. A jobb alsó gombbal hozz létre egyet.</div>`}
        ${k.kotesek.length && !kotesek.length ? (sz
          ? `<div class="ures">${URES}Ebben az időszakban nincs ${KOTES_FUL_NEV[ful]}számlát tartalmazó kötés.<br><small>Válts fület vagy időszakot, vagy töröld a szűrést.</small></div>`
          : `<div class="ures">${URES}Nincs ${ful === 'NYITOTT' ? 'nyitott' : 'fizetett'} kötés ennél a cégnél.<br><small>A fenti fülekkel válthatsz: NYITOTT · FIZETETT · MIND.</small></div>`) : ''}
        ${kotesek.map((x) => `<div class="kartya" data-kotes-id="${x.id}">
          <div class="fejsor" data-act="nav" data-href="#/bejovo/ceg/${cegId}/kotes/${x.id}" style="cursor:pointer">
            <div><div class="cim"><span class="kod">${esc(x.kod)}</span> ${badge(x.penznem)} ${badge(x.statusz)}${x.archiv ? ' <span class="badge ARCHIV" title="Excel importból létrejött archív kötés – a számlái kötésbe helyezhetők">ARCHÍV</span>' : ''}</div>
              <div class="alsor">${x.megnevezes ? `<span>${esc(x.megnevezes)}</span>` : ''}${x.regi_kod ? `<span title="Régi rendszerbeli kötés ID">régi ID: <b>${esc(x.regi_kod)}</b></span>` : ''}<span>${x.db} számla</span></div></div>
            <div class="osszeg${negOszt(x.teljes)}">${fmtOsszeg(x.teljes, x.penznem)}<br><small>teljes érték</small></div>
            <span class="nyil">${I.chev}</span></div>
          ${talalat(x)}
          <div class="osszesites">
            <div><span>Fizetendő</span><b class="${negOszt(x.fizetendo)}">${fmtOsszeg(x.fizetendo)}</b></div>
            <div><span>Utalás alatt</span><b>${fmtOsszeg(x.utalas_alatt)}</b></div>
            <div><span>Fizetett</span><b>${fmtOsszeg(x.fizetett)}</b></div>
            <div><span>Teljes</span><b>${fmtOsszeg(x.teljes)}</b></div>
          </div>
          ${haladSav(x)}
          <div class="muveletek">
            <a class="btn btn-outline btn-sm" href="#/bejovo/ceg/${cegId}/kotes/${x.id}">Megnyitás ${I.chev}</a>
            <span class="tolt"></span>
            ${jog('ir') ? `<button class="btn btn-sm" type="button" data-act="utalashoz-kotes" data-kotes="${x.id}" data-ceg="${cegId}" data-penznem="${x.penznem}" data-kod="${esc(x.kod)}" ${x.fizetendo_db ? '' : 'disabled'}>${I.transfer} UTALÁSHOZ${x.fizetendo_db ? ` (${x.fizetendo_db})` : ''}</button>` : ''}
            <span class="gombcsoport">${naploGomb('KOTES', x.id, x.kod)}
            ${nyomtatGomb('kotes', x.id, x.kod + (x.megnevezes ? ' · ' + x.megnevezes : ''))}</span>
          </div>
          ${modositoSor(x)}
        </div>`).join('')}`;
      main.classList.remove('frissul');
      const sav = $('.idoszak-sav', main);
      if (sav && sz && sz.mezo === 'allapot') allapotSavAdat(sav, sz, k.ceg.nev);
      else if (sav && sz) {
        sav.dataset.talalat = JSON.stringify((sz.osszes || sz.szamlak).map((s) => ({ t: 'bejovo', id: s.id, cimke: `${s.kod} · „${s.szamlaszam}”` })));
        sav.dataset.cimke = `${k.ceg.nev} · ${KOTES_FUL_NEV[ful]}számlák · ${IDOSZAK_MEZOK[sz.mezo]} ${fmtDatum(sz.tol)} – ${fmtDatum(sz.ig)}`;
      }
    } catch (e) {
      if (sorsz !== kotesekSorszam) return;
      main.classList.remove('frissul');
      main.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`;
    }
  }
  /** A kötések oldal újrarajzolása helyben (fülváltás, időszak-szűrés): nincs csontváz-villanás, a görgetés marad */
  function kotesekFrissit() {
    const p = route().path;
    return p[0] === 'bejovo' && p[1] === 'ceg' && p.length === 3 ? viewKotesek(Number(p[2]), true) : render();
  }

  // -------------------------------------------------------- BEJÖVŐ: számlák
  function bejovoSzamlaKartya(s, o) {
    o = o || {};
    const jel = !!o.kijelolheto;
    const bejelolt = jel && App.archivKijeloles.has(s.id);
    const utalasLink = s.utalas_uid ? `<a href="#/utalas/${s.utalas_id}" class="mono kicsi" title="Utalás megnyitása">${esc(s.utalas_uid)}</a>` : '';
    let statusz = '';
    if (s.statusz === 'UTALASHOZ_ADVA') statusz = `<span class="badge UTALASHOZ_ADVA link" title="Utalás: ${esc(s.utalas_uid || '')}" data-act="uid-mutat" data-uid="${esc(s.utalas_uid || '')}" data-utalas="${s.utalas_id || ''}">UTALÁSHOZ ADVA</span>`;
    else statusz = badge(s.statusz);
    const lezart = s.statusz === 'FIZETVE' || s.statusz === 'BESZAMITVA';
    return `<div class="kartya${bejelolt ? ' bejelolt' : ''}" data-szamla-id="${s.id}">
      <div class="fejsor">${jel && jog('ir') ? `<label class="kijelolo ${bejelolt ? 'bejelolt' : ''}" title="Kijelölés kötésbe helyezéshez"><input type="checkbox" data-act="archiv-jelol" data-szamla="${s.id}" ${bejelolt ? 'checked' : ''}>${I.check}</label>` : ''}<div style="min-width:0;flex:1"><div class="cim"><span class="kod">${esc(s.k)}</span> · „${esc(s.szamlaszam)}”</div>
        ${o.kotes ? `<div class="alsor"><span class="mono">${esc(s.kotes_kod)}</span>${s.ceg_nev ? `<span>${esc(s.ceg_nev)}</span>` : ''}</div>` : ''}
        <div class="alsor"><span>Kelt: ${fmtDatum(s.kelt)}</span><span>Telj.: ${fmtDatum(s.teljesites_datum)}</span><span>Határidő: <b>${fmtDatum(s.fizetesi_hatarido)}</b>${!lezart ? ` <span class="badge ${hataridoOszt(s.fizetesi_hatarido)}">${esc(hataridoSzoveg(s.fizetesi_hatarido))}</span>` : ''}</span>${fizetveSzoveg(s, 'Utalva')}</div>
        ${s.beszam || s.regi_k ? `<div class="alsor">${s.beszam ? `<span>BESZÁM: <b>${esc(s.beszam)}</b></span>` : ''}${s.regi_k ? `<span title="Régi rendszerbeli K azonosító">régi K: <b class="mono">${esc(s.regi_k)}</b></span>` : ''}</div>` : ''}
        ${resztSor(s)}
        ${s.megjegyzes ? `<div class="alsor">${megjSzoveg(s.megjegyzes)}</div>` : ''}</div>
        <div class="osszeg${negOszt(foErtek(s))}${Number(s.reszt) > 0 && !lezart ? ' hatralek' : ''}">${fmtOsszeg(foErtek(s), s.penznem)}${Number(s.reszt) > 0 && !lezart ? '<small>hátralék</small>' : ''}</div></div>
      <div class="muveletek">${statusz} ${(lezart || s.statusz === 'UTALASHOZ_ADVA') && utalasLink ? utalasLink : ''}<span class="tolt"></span>
        ${s.statusz === 'FIZETENDO' && jog('ir') ? `<button class="btn btn-sm" type="button" data-act="utalashoz-szamla" data-szamla="${s.id}" data-ceg="${s.ceg_id}" data-penznem="${s.penznem}">${I.transfer} UTALÁSHOZ</button>` : ''}
        ${s.statusz === 'UTALASHOZ_ADVA' && jog('ir') ? `<button class="btn btn-outline btn-sm" type="button" data-act="utalasbol-ki" data-szamla="${s.id}">${I.x} Ki az utalásból</button>` : ''}
        <span class="gombcsoport">${naploGomb('BEJOVO', s.id, `${s.kod} · „${s.szamlaszam}”`)}
        <button class="btn btn-outline btn-sm btn-ikon" type="button" data-act="bejovo-szerk" data-szamla="${s.id}" title="${jog('ir') ? 'Szerkesztés' : 'BESZÁM / megjegyzés'}" aria-label="${jog('ir') ? 'Szerkesztés' : 'BESZÁM / megjegyzés'}">${I.edit}</button>
        ${s.statusz === 'FIZETENDO' && jog('torol') ? `<button class="btn btn-outline piros btn-sm btn-ikon" type="button" data-act="bejovo-torol" data-szamla="${s.id}" data-kod="${esc(s.kod)}" title="Törlés" aria-label="Törlés">${I.trash}</button>` : ''}
        ${nyomtatGomb('bejovo', s.id, `${s.kod} · „${s.szamlaszam}”`)}</span>
      </div>
      ${modositoSor(s)}</div>`;
  }
  /**
   * A kötés / kimenő oldal állapot vizsgálata (1.19), ha erre az oldalra szól. Egy konkrét számlához ugráskor (?szamla=)
   * kilép belőle – a számla kártyája csak a normál listában látszik.
   */
  function oldalAllapot(kulcs, id, query) {
    const a = App.allapotSzuro;
    if (!a || a.kulcs !== kulcs || a.id !== id) return null;
    if (query && query.szamla) { App.allapotSzuro = null; return null; }
    return a;
  }
  const allapotLekeres = (be) => api('allapot_vizsgalat', be).catch((e) => { App.allapotSzuro = null; hibaToast(e); return null; });
  function archivAlsoSav() {
    const n = App.archivKijeloles.size;
    return `<div class="also-sav"><button class="btn btn-zold" type="button" data-act="archiv-athelyez" ${n ? '' : 'disabled'}>${I.rope} ${n} számla áthelyezése kötésbe</button></div>`;
  }
  async function viewSzamlak(cegId, kotesId, query) {
    if (App.archivKotesId !== kotesId) { App.archivKijeloles = new Set(); App.archivKotesId = kotesId; }
    let main = shell({ alcim: 'Bejövő · Számlák', vissza: `#/bejovo/ceg/${cegId}`, fab: { act: 'bejovo-uj', ikon: 'plus', cim: 'Új számla', data: `data-kotes="${kotesId}"` } });
    try {
      const asz = oldalAllapot('kotes', kotesId, query);
      const [d, av] = await Promise.all([
        api('bejovo_szamlak', { kotes_id: kotesId }),
        asz ? allapotLekeres({ irany: 'BEJOVO', kotes_id: kotesId, tol: asz.tol, ig: asz.ig, nap: asz.nap }) : null,
      ]);
      const k = d.kotes;
      // ha egy konkrét számlához ugrunk (kereső, utalás, link), a fül átvált arra, ahol a számla látszik
      if (query && query.szamla) {
        const cel = d.szamlak.find((s) => String(s.id) === String(query.szamla));
        if (cel && App.bejovoFul !== 'MIND' && !BEJOVO_FUL[App.bejovoFul](cel)) App.bejovoFul = BEJOVO_FUL.NYITOTT(cel) ? 'NYITOTT' : 'FIZETVE';
      }
      const ful = App.bejovoFul;
      const lista = d.szamlak.filter(BEJOVO_FUL[ful] || BEJOVO_FUL.MIND);
      // állapot vizsgálatnál a fülek a vizsgált napi állapot szerint számolnak és szűrnek
      const dbNy = av ? av.szamlak.filter(ALLAPOT_FUL.NYITOTT).length : d.szamlak.filter(BEJOVO_FUL.NYITOTT).length;
      const dbFiz = av ? av.szamlak.filter(ALLAPOT_FUL.FIZETVE).length : d.szamlak.filter(BEJOVO_FUL.FIZETVE).length;
      const dbMind = av ? av.szamlak.length : d.szamlak.length;
      if (k.archiv) {
        const ids = new Set(d.szamlak.map((s) => s.id));
        App.archivKijeloles.forEach((id) => { if (!ids.has(id)) App.archivKijeloles.delete(id); });
        main = shell({ alcim: 'Bejövő · Archív kötés', vissza: `#/bejovo/ceg/${cegId}`, fab: { act: 'bejovo-uj', ikon: 'plus', cim: 'Új számla', data: `data-kotes="${kotesId}"` }, fabEmelt: true, alsoSav: archivAlsoSav() });
      }
      main.innerHTML = `<h1><span class="mono">${esc(k.kod)}</span> <small>· ${esc(k.ceg_nev)}</small></h1>
        <div class="alcim-sor">${badge(k.penznem)} ${badge(k.statusz)}${k.archiv ? ' <span class="badge ARCHIV">ARCHÍV</span>' : ''} ${k.megnevezes ? ' · ' + esc(k.megnevezes) : ''}${k.regi_kod ? ` · régi ID: <b>${esc(k.regi_kod)}</b>` : ''}${k.megjegyzes ? '<br>' + megjSzoveg(k.megjegyzes) : ''}</div>
        ${k.archiv ? `<div class="info-doboz">Ez az <b>archív kötés</b> az Excel importból jött létre (${esc(k.ceg_nev)}, ${k.penznem}). A számlák elején lévő <b>kerek gombbal</b> jelöld ki azokat, amelyeket egy rendes kötésbe akarsz tenni, majd nyomd meg az alsó zöld gombot – a számlák a mostani rendszer szerinti teljes azonosítót kapják (kötés ID + K sorszám), a régi rendszerbeli kötés ID-t pedig megadhatod a kötésen.</div>` : ''}
        <div class="kartya"><div class="osszesites" style="margin-top:0">
          <div><span>Fizetendő</span><b class="${negOszt(k.fizetendo)}">${fmtOsszeg(k.fizetendo, k.penznem)}</b></div>
          <div><span>Utalás alatt</span><b>${fmtOsszeg(k.utalas_alatt, k.penznem)}</b></div>
          <div><span>Fizetett${k.reszt > 0 ? ' <small title="Ebből részteljesítés">(részt. ' + fmtOsszeg(k.reszt) + ')</small>' : ''}</span><b>${fmtOsszeg(k.fizetett, k.penznem)}</b></div>
          <div><span>Teljes</span><b>${fmtOsszeg(k.teljes, k.penznem)}</b></div></div>
          ${haladSav(k)}
          <div class="muveletek">
            ${jog('ir') ? `<button class="btn btn-sm" type="button" data-act="utalashoz-kotes" data-kotes="${k.id}" data-ceg="${cegId}" data-penznem="${k.penznem}" data-kod="${esc(k.kod)}" ${k.fizetendo_db ? '' : 'disabled'}>${I.transfer} Minden FIZETENDŐ utaláshoz${k.fizetendo_db ? ` (${k.fizetendo_db})` : ''}</button>` : ''}
            <span class="tolt"></span>
            <span class="gombcsoport">${naploGomb('KOTES', k.id, k.kod)}
            <button class="btn btn-outline btn-sm btn-ikon" type="button" data-act="kotes-szerk" data-kotes="${k.id}" title="${jog('ir') ? 'Kötés szerkesztése' : 'Megjegyzés'}" aria-label="${jog('ir') ? 'Kötés szerkesztése' : 'Megjegyzés'}">${I.edit}</button>
            ${k.db === 0 && jog('torol') ? `<button class="btn btn-outline piros btn-sm btn-ikon" type="button" data-act="kotes-torol" data-kotes="${k.id}" data-ceg="${cegId}" data-kod="${esc(k.kod)}" title="Kötés törlése" aria-label="Kötés törlése">${I.trash}</button>` : ''}
            ${nyomtatGomb('kotes', k.id, k.kod + (k.megnevezes ? ' · ' + k.megnevezes : ''))}</span>
          </div>
          ${modositoSor(k)}</div>
        ${av ? cegEgyenlegDoboz(av.egyenleg, ful, av, 'A kötés egyenlege') : ''}
        ${d.szamlak.length ? statuszFulek([['NYITOTT', 'NYITOTT', `${dbNy} db`], ['FIZETVE', 'FIZETVE', `+ beszámítva · ${dbFiz} db`], ['MIND', 'MIND', `${dbMind} db`]], ful, 'bejovo-ful') : ''}
        ${av ? idoszakSav({ kulcs: 'kotes', cim: 'Számlák időszak szerint → nyomtatási kosárba', act: 'idoszak-szures', data: 'data-lista="kotes"', ertek: av, aktiv: true, eredmeny: allapotEredmeny(av, ful, { torol: 'allapot-torol', tipus: 'bejovo', link: (s) => `#/bejovo/ceg/${cegId}/kotes/${kotesId}?szamla=${s.id}` }) })
          : d.szamlak.length ? idoszakSav({ kulcs: 'kotes', cim: 'Számlák időszak szerint → nyomtatási kosárba', act: 'idoszak-szures', data: 'data-lista="kotes"', ertek: App.idoszakUrlap && App.idoszakUrlap.kulcs === 'kotes' ? App.idoszakUrlap : null }) : ''}
        ${av ? '' : `<div class="lista-fej"><span class="cim">Számlák</span><span class="darab">${ful === 'MIND' || lista.length === d.szamlak.length ? `${d.szamlak.length} db` : `${lista.length} / ${d.szamlak.length} db`}</span></div>
        <div class="szamla-lista">${lista.length ? lista.map((s) => bejovoSzamlaKartya(s, { kijelolheto: k.archiv })).join('') : d.szamlak.length ? `<div class="ures">${URES}Nincs ${ful === 'NYITOTT' ? 'nyitott (fizetendő / utalás alatt)' : 'fizetett / beszámított'} számla ebben a kötésben.<br><small>A fenti fülekkel válthatsz: NYITOTT · FIZETVE · MIND.</small></div>` : `<div class="ures">${URES}Még nincs számla ebben a kötésben. A jobb alsó + gombbal rögzíts egyet.</div>`}</div>`}`;
      App.idoszakLista = { tipus: 'bejovo', szamlak: lista };
      App.idoszakUrlap = null;
      if (av) allapotSavAdat($('.idoszak-sav', main), av, k.ceg_nev);
      if (k.archiv && jog('ir')) { const s = $('.also-sav'); if (s) s.outerHTML = archivAlsoSav(); }
      kiemel(query && query.szamla);
      if (query && query.szamla) queryTorol('szamla');
    } catch (e) { main.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }
  function kiemel(id) {
    if (!id) return;
    const el = $(`[data-szamla-id="${id}"]`);
    if (el) { el.classList.add('kiemelt'); setTimeout(() => el.scrollIntoView({ block: 'center', behavior: 'smooth' }), 50); }
  }

  // ---------------------------------------------------- BEJÖVŐ: cég utalásai
  function utalasKartya(u, mutatCeg) {
    UtalasKosar.egyeztet(u);
    const akt = UtalasKosar.get();
    const aktualis = !!(akt && akt.id === u.id);
    const gyujtheto = u.statusz === 'NYITOTT' && !u.lezarva && jog('ir');
    return `<div class="kartya kattinthato${aktualis ? ' aktualis-utalas' : ''}" data-act="nav" data-href="#/utalas/${u.id}">
      <div class="fejsor osszeg-alul"><div class="info" style="min-width:0"><div class="cim"><span class="kod">${esc(u.uid)}</span>${lakatBadge(u)}${aktualis ? `<span class="badge aktualis" title="A bankkártyás gomb ehhez az utaláshoz gyűjt">${I.card} AKTUÁLIS GYŰJTŐ</span>` : ''}</div>
        <div class="alsor">${badge(u.statusz)} ${badge(u.utalas_mod)} ${mutatCeg ? `<span>${esc(u.ceg_nev)}</span>` : ''}<span>${u.db} számla</span>${u.hatarertek != null ? `<span>limit: ${fmtOsszeg(u.hatarertek, u.penznem)}</span>` : ''}${u.utalva_datum ? `<span>utalva: ${fmtDatum(u.utalva_datum)}</span>` : ''}${u.utalva_at ? `<span title="Ekkor került UTALVA státuszba (${esc(u.utalta_nev || '?')})">UTALVA státusz: ${fmtIdo(u.utalva_at)}</span>` : ''}</div></div>
        <div class="osszeg${negOszt(u.osszeg)}">${fmtOsszeg(u.osszeg, u.penznem)}</div>${nyomtatGomb('utalas', u.id, u.uid)}<span class="nyil">${I.chev}</span></div>
      ${gyujtheto || aktualis ? `<div class="muveletek"><span class="tolt"></span>${aktualis ? `<button class="btn btn-outline btn-sm" type="button" data-act="utalas-kosar" title="Az aktuális gyűjtő tartalma">${I.card} Gyűjtő megnyitása</button>` : `<button class="btn btn-outline narancs btn-sm" type="button" data-act="utalas-kosar-valaszt" data-utalas="${u.id}" title="A bankkártyás gomb mostantól ehhez az utaláshoz gyűjti a számlákat">${I.card} Ehhez gyűjtök</button>`}</div>` : ''}</div>`;
  }
  async function viewCegUtalasok(cegId) {
    const main = shell({ alcim: 'Bejövő · Utalások', vissza: `#/bejovo/ceg/${cegId}`, fab: { act: 'utalas-uj-menu', ikon: 'transfer', cim: 'Új utalás', data: `data-ceg="${cegId}"` } });
    try {
      const [d, c] = await Promise.all([api('utalasok', { ceg_id: cegId }), api('ceg', { id: cegId })]);
      const ful = App.utalasFul;
      const lista = d.utalasok.filter((u) => ful === 'MIND' || u.statusz === ful);
      main.innerHTML = `<h1>${esc(c.ceg.nev)} <small>· utalások</small></h1>
        <div class="gomb-sor" style="margin-bottom:8px">
          ${jog('ir') ? `<button class="btn btn-outline narancs" type="button" data-act="utalas-manual" data-ceg="${cegId}">${I.plus} Manuális utalás</button>
          <button class="btn btn-sarga" type="button" data-act="utalas-hatarertek" data-ceg="${cegId}">${I.filter} Határértékes utalás</button>` : ''}
        </div>
        <div class="fulek" style="--n:3;--i:${['NYITOTT', 'UTALVA', 'MIND'].indexOf(ful)}">${['NYITOTT', 'UTALVA', 'MIND'].map((f) => `<button type="button" data-act="utalas-ful" data-ful="${f}" class="${ful === f ? 'aktiv' : ''}">${f === 'MIND' ? 'Mind' : STATUSZ[f]}</button>`).join('')}</div>
        ${lista.length ? lista.map((u) => utalasKartya(u, false)).join('') : `<div class="ures">${URES}Nincs ${ful === 'MIND' ? '' : STATUSZ[ful].toLowerCase() + ' '}utalás.</div>`}`;
    } catch (e) { main.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }

  // ---------------------------------------------------------- UTALÁS nézet
  async function viewUtalas(id) {
    const main = shell({ alcim: 'Utalás összesítő', vissza: '#/utalasok' });
    try {
      const d = await api('utalas', { id });
      const u = d.utalas;
      UtalasKosar.egyeztet(u);                          // ha közben lelakatolták / teljesítették, a gyűjtő elengedi
      const nyitott = u.statusz === 'NYITOTT';
      main.innerHTML = `
        <div class="utalas-fej">
          <div class="uid">${esc(u.uid)}</div>
          <div class="vegosszeg${negOszt(u.osszeg)}" data-countup="${u.osszeg}" data-penznem="${u.penznem}">${fmtOsszeg(u.osszeg, u.penznem)}</div>
          <div class="meta">${badge(u.statusz)} ${badge(u.utalas_mod)} ${lakatBadge(u)} <a href="#/bejovo/ceg/${u.ceg_id}">${esc(u.ceg_nev)}</a> · ${u.db} számla${u.hatarertek != null ? ` · limit ${fmtOsszeg(u.hatarertek, u.penznem)}` : ''}</div>
          <div class="meta kicsi">létrehozva ${fmtIdo(u.letrehozva)} (${esc(u.letrehozta_nev || '')})${u.lezarva ? ` · lelakatolta ${esc(u.lezarta_nev || '')} ${fmtIdo(u.lezarva_at)}` : ''}${u.utalva_datum ? ` · utalva ${fmtDatum(u.utalva_datum)}` : ''}${u.utalva_at ? ` · <span data-utalva-at>UTALVA státusz: <b>${fmtIdo(u.utalva_at)}</b> (${esc(u.utalta_nev || '?')})</span>` : (u.utalva_datum ? ` (${esc(u.utalta_nev || '')})` : '')}${u.banki_hivatkozas ? ` · hiv.: ${esc(u.banki_hivatkozas)}` : ''}</div>
          ${u.megjegyzes ? `<div class="meta kicsi">${megjSzoveg(u.megjegyzes)}</div>` : ''}
        </div>
        <div class="gomb-sor" style="justify-content:center;margin:8px 0 6px">
          ${nyitott && !u.lezarva && jog('ir') ? `<button class="btn btn-outline narancs btn-sm" type="button" data-act="utalas-szamla-valaszt" data-utalas="${u.id}" data-ceg="${u.ceg_id}" data-penznem="${u.penznem}">${I.plus} Számla hozzáadása</button>` : ''}
          ${nyitott && jog('ir') ? `<button class="btn btn-sm ${u.lezarva ? 'btn-outline' : 'btn-fekete'}" type="button" data-act="utalas-lakat" data-utalas="${u.id}" data-uid="${esc(u.uid)}" data-lezar="${u.lezarva ? '0' : '1'}" ${u.db || u.lezarva ? '' : 'disabled'} title="${u.lezarva ? 'A lakat kinyitása – újra lehet számlát hozzáadni / kivenni' : 'A gyűjtés befejezése: lelakatolás – a státusz NYITOTT marad, de nem lehet számlát hozzáadni / kivenni'}">${u.lezarva ? I.unlock + ' Lakat kinyitása' : I.lock + ' Lelakatolás'}</button>` : ''}
          ${nyitott && jog('ir') ? `<button class="btn btn-zold btn-sm" type="button" data-act="utalas-utalva" data-utalas="${u.id}" data-uid="${esc(u.uid)}" data-osszeg="${u.osszeg}" data-penznem="${u.penznem}" ${u.db ? '' : 'disabled'}>${I.check} Utalás teljesítve</button>` : ''}
          ${nyitott && !u.lezarva && jog('torol') ? `<button class="btn btn-outline piros btn-sm" type="button" data-act="utalas-torol" data-utalas="${u.id}" data-uid="${esc(u.uid)}">${I.trash} Törlés</button>` : ''}
          ${nyitott && !u.lezarva && jog('ir') ? ((UtalasKosar.get() || {}).id === u.id ? `<span class="badge aktualis" title="A bankkártyás gomb ehhez az utaláshoz gyűjt">${I.card} AKTUÁLIS GYŰJTŐ</span>` : `<button class="btn btn-outline btn-sm" type="button" data-act="utalas-kosar-valaszt" data-utalas="${u.id}" title="A bankkártyás gomb mostantól ehhez az utaláshoz gyűjt">${I.card} Ehhez gyűjtök</button>`) : ''}
          ${!nyitott && jog('torol') ? `<button class="btn btn-outline btn-sm" type="button" data-act="utalas-visszanyit" data-utalas="${u.id}" data-uid="${esc(u.uid)}">Visszanyitás</button>` : ''}
          <button class="btn btn-outline btn-sm btn-ikon" type="button" data-act="utalas-szerk" data-utalas="${u.id}" title="Megjegyzés" aria-label="Megjegyzés">${I.edit}</button>
          ${nyomtatGomb('utalas', u.id, u.uid)}
        </div>
        <h2>Részletes lista</h2>
        <div class="reszletes kartya">
          ${d.kotesek.length ? d.kotesek.map((k) => `
            <div class="kotes-sor"><a class="kod" href="#/bejovo/ceg/${u.ceg_id}/kotes/${k.kotes_pk}">${esc(k.kotes_kod)}${k.kotes_megnevezes ? `<small>${esc(k.kotes_megnevezes)}</small>` : ''}</a><span class="${negOszt(k.osszeg)}">${fmtOsszeg(k.osszeg, u.penznem)}</span></div>
            ${k.szamlak.map((s) => `<div class="szamla-sor"><div class="bal"><a class="k" href="#/bejovo/ceg/${u.ceg_id}/kotes/${k.kotes_pk}?szamla=${s.id}">– ${esc(s.k)}</a><span class="szsz">„${esc(s.szamlaszam)}”</span>${badge(s.statusz)}${Number(s.reszt) > 0 ? `<span class="kicsi szurke" title="Részteljesítés után">eredeti ${fmtOsszeg(s.osszeg, u.penznem)} · részt. −${fmtOsszeg(s.reszt, u.penznem)}</span>` : ''}</div><span class="o${negOszt(s.hatralek)}">${fmtOsszeg(s.hatralek, u.penznem)}</span>${nyitott && !u.lezarva && jog('ir') ? `<button class="btn btn-outline piros btn-sm btn-ikon" type="button" data-act="utalasbol-ki" data-szamla="${s.id}" title="Kivétel az utalásból" aria-label="Kivétel az utalásból">${I.x}</button>` : ''}${nyomtatGomb('bejovo', s.id, `${k.kotes_kod}-${s.k} · „${s.szamlaszam}”`)}</div>`).join('')}
          `).join('') : `<div class="ures">Az utalás még üres. A kötések / számlák melletti UTALÁSHOZ gombbal, vagy a fenti „Számla hozzáadása” gombbal adhatsz hozzá tételeket.</div>`}
          ${u.lezarva ? `<div class="info-doboz lakat-info">${I.lock}<span><b>Lelakatolva</b> – a gyűjtés befejeződött, a lista nem módosítható (a státusz NYITOTT marad). Az utalás teljesíthető; ha mégis kell bele számla, nyisd ki a lakatot.</span></div>` : ''}
          <div class="osszesen-sor"><span>ÖSSZESEN</span><span class="${negOszt(u.osszeg)}">${fmtOsszeg(u.osszeg, u.penznem)}</span></div>
        </div>`;
      animSzamok(main);
    } catch (e) { main.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }

  // --------------------------------------------------------- MINDEN UTALÁS
  async function viewMindenUtalas() {
    const main = shell({ alcim: 'Minden utalás', vissza: '#/' });
    try {
      const sz = App.mindenUtalasSzuro;
      const [d, cegek] = await Promise.all([api('utalasok', { statusz: sz.statusz || undefined, penznem: sz.penznem || undefined, ceg_id: sz.ceg || undefined }), cegekBetolt()]);
      const ossz = {};
      d.utalasok.forEach((u) => { if (u.statusz === 'NYITOTT') ossz[u.penznem] = (ossz[u.penznem] || 0) + u.osszeg; });
      main.innerHTML = `<h1>Minden utalás</h1>
        <div class="fulek" style="--n:3;--i:${['NYITOTT', 'UTALVA', ''].indexOf(sz.statusz)}">${[['NYITOTT', 'Nyitott'], ['UTALVA', 'Utalva'], ['', 'Mind']].map(([v, f]) => `<button type="button" data-act="mu-szuro" data-k="statusz" data-v="${v}" class="${sz.statusz === v ? 'aktiv' : ''}">${f}</button>`).join('')}</div>
        <div class="csipek">${[['', 'Minden pénznem'], ['HUF', 'HUF'], ['EUR', 'EUR']].map(([v, f]) => `<button type="button" class="csip ${sz.penznem === v ? 'aktiv' : ''}" data-act="mu-szuro" data-k="penznem" data-v="${v}">${f}</button>`).join('')}</div>
        <div class="mezo"><select data-act="mu-ceg"><option value="">Minden cég</option>${cegek.map((c) => `<option value="${c.id}" ${String(sz.ceg) === String(c.id) ? 'selected' : ''}>${esc(c.nev)}</option>`).join('')}</select></div>
        ${Object.keys(ossz).length ? `<div class="info-doboz">Nyitott utalások összesen: ${Object.keys(ossz).map((p) => `<b>${fmtOsszeg(ossz[p], p)}</b>`).join(' · ')}</div>` : ''}
        ${d.utalasok.length ? d.utalasok.map((u) => utalasKartya(u, true)).join('') : `<div class="ures">${URES}Nincs a szűrésnek megfelelő utalás.</div>`}`;
    } catch (e) { main.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }

  // ------------------------------------------------------------ KIMENŐ: cégek
  async function viewKimenoCegek() {
    const main = shell({ alcim: 'Kimenő számlák · Cégek', vissza: '#/', fab: { act: 'ceg-uj', ikon: 'factory', cim: 'Új cég' } });
    try {
      const cegek = await cegekBetolt(true);
      main.innerHTML = `<h1>Kimenő számlák <small>· válassz céget</small></h1>
        ${cegek.length ? '' : `<div class="ures">${URES}Még nincs cég. A jobb alsó gyár-gombbal hozz létre egyet.</div>`}
        ${cegek.map((c) => `<div class="kartya kattinthato" data-act="nav" data-href="#/kimeno/ceg/${c.id}">
          <div class="fejsor"><div class="balra">${avatar(c.nev)}<div style="min-width:0"><div class="cim">${esc(c.nev)}</div><div class="alsor"><span>${c.kimeno_nyitott_db} nyitott kimenő számla</span>${c.partnerkod ? `<span class="mono" title="Partnerkód">P${esc(c.partnerkod)}</span>` : ''}</div></div></div>
            <div class="osszeg">${c.ki_EUR ? `<div>${fmtOsszeg(c.ki_EUR, 'EUR')}</div>` : ''}${c.ki_HUF ? `<div>${fmtOsszeg(c.ki_HUF, 'HUF')}</div>` : ''}<small>${c.ki_EUR || c.ki_HUF ? 'ennyivel tartozik' : 'nincs tartozása'}</small></div>
            <span class="nyil">${I.chev}</span></div>
          <div class="muveletek"><span class="tolt"></span><button class="btn btn-outline btn-sm" type="button" data-act="ceg-szerk" data-id="${c.id}">${I.edit} ${jog('ir') ? 'Szerkesztés' : 'Megjegyzés'}</button>${nyomtatGomb('ceg', c.id, c.nev)}</div>
        </div>`).join('')}`;
    } catch (e) { main.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }

  // ---------------------------------------------------------- KIMENŐ: számlák
  function kimenoSzamlaKartya(s, kijelolheto) {
    const nyitott = s.statusz === 'NYITOTT';
    const jel = kijelolheto && nyitott && jog('ir');
    const bejelolt = BankKosar.van(s.id);
    return `<div class="kartya" data-szamla-id="${s.id}">
      <div class="fejsor">
        ${jel ? `<label class="kijelolo ${bejelolt ? 'bejelolt' : ''}" title="A banki gyűjtőbe (bankkivonati azonosítóhoz adás)"><input type="checkbox" data-act="kimeno-jelol" data-szamla="${s.id}" ${bejelolt ? 'checked' : ''}>${I.check}</label>` : ''}
        <div style="min-width:0;flex:1"><div class="cim"><span class="kod">${esc(s.kod)}</span> · „${esc(s.szamlaszam)}”</div>
          <div class="alsor"><span>Kelt: ${fmtDatum(s.kelt)}</span><span>Telj.: ${fmtDatum(s.teljesites_datum)}</span><span>Határidő: <b>${fmtDatum(s.fizetesi_hatarido)}</b>${nyitott ? ` <span class="badge ${hataridoOszt(s.fizetesi_hatarido)}">${esc(hataridoSzoveg(s.fizetesi_hatarido))}</span>` : ''}</span></div>
          ${!nyitott ? `<div class="alsor">${fizetveSzoveg(s, 'Fizetve')}<span>bank: <b>${esc(s.banki_azonosito || '')}</b></span></div>` : ''}
          ${resztSor(s)}
          ${s.megjegyzes ? `<div class="alsor">${megjSzoveg(s.megjegyzes)}</div>` : ''}</div>
        <div class="osszeg${negOszt(foErtek(s))}${Number(s.reszt) > 0 && nyitott ? ' hatralek' : ''}">${fmtOsszeg(foErtek(s), s.penznem)}${Number(s.reszt) > 0 && nyitott ? '<small>hátralék</small>' : ''}</div></div>
      <div class="muveletek">${badge(s.statusz)}<span class="tolt"></span>
        ${jel ? `<button class="btn btn-outline btn-sm" type="button" data-act="kimeno-jelol-gomb" data-szamla="${s.id}">${bejelolt ? I.x + ' Kijelölés törlése' : I.bank + ' Bankazonosítóhoz'}</button>` : ''}
        ${!nyitott && jog('torol') ? `<button class="btn btn-outline btn-sm" type="button" data-act="kimeno-visszavon" data-szamla="${s.id}" data-kod="${esc(s.kod)}">Fizetés visszavonása</button>` : ''}
        <span class="gombcsoport">${naploGomb('KIMENO', s.id, `${s.kod} · „${s.szamlaszam}”`)}
        <button class="btn btn-outline btn-sm btn-ikon" type="button" data-act="kimeno-szerk" data-szamla="${s.id}" title="${jog('ir') ? 'Szerkesztés' : 'Megjegyzés'}" aria-label="${jog('ir') ? 'Szerkesztés' : 'Megjegyzés'}">${I.edit}</button>
        ${nyitott && jog('torol') ? `<button class="btn btn-outline piros btn-sm btn-ikon" type="button" data-act="kimeno-torol" data-szamla="${s.id}" data-kod="${esc(s.kod)}" title="Törlés" aria-label="Törlés">${I.trash}</button>` : ''}
        ${nyomtatGomb('kimeno', s.id, `${s.kod} · „${s.szamlaszam}”`)}</span>
      </div>
      ${modositoSor(s)}</div>`;
  }
  async function viewKimenoSzamlak(cegId, query) {
    const main = shell({ alcim: 'Kimenő · Számlák', vissza: '#/kimeno', fab: { act: 'kimeno-uj', ikon: 'plus', cim: 'Új kimenő számla', data: `data-ceg="${cegId}"` } });
    try {
      const asz = oldalAllapot('kimeno', cegId, query);
      const [d, av] = await Promise.all([
        api('kimeno_szamlak', { ceg_id: cegId }),
        asz ? allapotLekeres({ irany: 'KIMENO', ceg_id: cegId, tol: asz.tol, ig: asz.ig, nap: asz.nap }) : null,
      ]);
      App.kimenoSorok = {}; d.szamlak.forEach((s) => { App.kimenoSorok[s.id] = s; });
      // ami itt már nem nyitott, kikerül a banki gyűjtőből
      const nyitottIds = new Set(d.szamlak.filter((s) => s.statusz === 'NYITOTT').map((s) => s.id));
      const cegIds = new Set(d.szamlak.map((s) => s.id));
      if (BankKosar.lista().some((x) => cegIds.has(x.id) && !nyitottIds.has(x.id))) BankKosar.ment(BankKosar.lista().filter((x) => !cegIds.has(x.id) || nyitottIds.has(x.id)));
      // ha egy konkrét számlához ugrunk (kereső, link), a fül átvált arra, ahol a számla látszik – egyszer, utána a query kikerül a címből
      if (query && query.szamla) {
        const cel = d.szamlak.find((s) => String(s.id) === String(query.szamla));
        if (cel && App.kimenoFul !== 'MIND' && cel.statusz !== App.kimenoFul) App.kimenoFul = cel.statusz;
      }
      const ful = App.kimenoFul;
      const lista = d.szamlak.filter((s) => ful === 'MIND' || s.statusz === ful);
      const t = d.tartozas;
      main.innerHTML = `<h1>${esc(d.ceg.nev)} <small>· kimenő számlák</small></h1>
        ${av ? cegEgyenlegDoboz(av.egyenleg, ful, av, 'A cég egyenlege') : `<div class="kartya"><div class="fejsor"><div><div class="cim">Ennyivel tartozik nekem</div><div class="alsor"><span>${t.EUR.nyitott_db + t.HUF.nyitott_db} nyitott számla</span><span>fizetve: ${fmtOsszeg(t.EUR.fizetve, 'EUR')} · ${fmtOsszeg(t.HUF.fizetve, 'HUF')}</span>${t.EUR.reszt > 0 || t.HUF.reszt > 0 ? `<span title="A nyitott számlákra érkezett részteljesítések">ebből részteljesítés: ${[t.EUR.reszt > 0 ? fmtOsszeg(t.EUR.reszt, 'EUR') : '', t.HUF.reszt > 0 ? fmtOsszeg(t.HUF.reszt, 'HUF') : ''].filter(Boolean).join(' · ')}</span>` : ''}</div></div>
          <div class="osszeg"><div>${fmtOsszeg(t.EUR.nyitott, 'EUR')}</div><div>${fmtOsszeg(t.HUF.nyitott, 'HUF')}</div></div></div></div>`}
        <div class="fulek fulek-egyenleg" style="--n:3;--i:${['NYITOTT', 'FIZETVE', 'MIND'].indexOf(ful)}">${['NYITOTT', 'FIZETVE', 'MIND'].map((f) => `<button type="button" data-act="kimeno-ful" data-ful="${f}" class="${ful === f ? 'aktiv' : ''}">${f === 'MIND' ? 'MIND <small>(egyenleg készítés)</small>' : STATUSZ[f]}</button>`).join('')}</div>
        ${ful === 'MIND' && !av ? `<div class="info-doboz egyenleg-info">${I.print}<span><b>Egyenleg készítése a partnernek:</b> szűrd az időszakot, nyomd meg a <b>Mind a kosárba – egyenleg</b> gombot, majd a jobb alsó nyomtató gombot. A PDF-ben a számlák alatt egy <b>EGYENLEG</b> táblázat mutatja: összes pénzforgalom · fizetett / beszámított · nyitott (még fizetendő). Egy korábbi nap egyenlegéhez válaszd a Dátum mezőben az <b>Állapot vizsgálat</b>-ot.</span></div>` : ''}
        ${!av && ful !== 'FIZETVE' && nyitottIds.size && jog('ir') ? `<div class="alcim-sor">Bankkivonattal megfeleltetés: jelöld ki a számlákat a <b>kerek gombbal</b> (a banki gyűjtőbe kerülnek), majd nyomd meg a jobb alsó <b>bank-épület</b> gombot.</div>` : ''}
        ${av || d.szamlak.length ? idoszakSav({ kulcs: 'kimeno', cim: ful === 'MIND' ? 'Egyenleg: számlák időszak szerint → nyomtatási kosárba' : 'Számlák időszak szerint → nyomtatási kosárba', act: 'idoszak-szures', data: `data-lista="kimeno"${ful === 'MIND' ? ' data-egyenleg="1"' : ''}`,
          ertek: av || (App.idoszakUrlap && App.idoszakUrlap.kulcs === 'kimeno' ? App.idoszakUrlap : null), aktiv: !!av, eredmeny: av ? allapotEredmeny(av, ful, { torol: 'allapot-torol', tipus: 'kimeno', link: (s) => `#/kimeno/ceg/${cegId}?szamla=${s.id}` }) : '' }) : ''}
        ${av ? '' : `<div class="szamla-lista">${lista.length ? lista.map((s) => kimenoSzamlaKartya(s, true)).join('') : `<div class="ures">${URES}Nincs ${ful === 'MIND' ? '' : STATUSZ[ful].toLowerCase() + ' '}kimenő számla.</div>`}</div>`}`;
      App.idoszakLista = { tipus: 'kimeno', szamlak: lista };
      App.idoszakUrlap = null;
      if (av) allapotSavAdat($('.idoszak-sav', main), av, d.ceg.nev);
      kiemel(query && query.szamla);
      if (query && query.szamla) queryTorol('szamla');
    } catch (e) { main.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }

  // --------------------------------------------------------------- HATÁRIDŐK
  let hataridoAdat = null;
  async function viewHataridok() {
    const main = shell({ alcim: 'Fizetési határidők', vissza: '#/' });
    try {
      if (!hataridoAdat) hataridoAdat = await api('hataridok');
      rajzolHataridok(main);
    } catch (e) { main.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }
  function rajzolHataridok(main) {
    main = main || $('main');
    const sz = App.hataridoSzuro;
    let t = hataridoAdat.tetelek;
    if (!sz.utalasAlatt) t = t.filter((x) => x.statusz !== 'UTALASHOZ_ADVA');
    if (sz.irany !== 'MIND') t = t.filter((x) => x.irany === sz.irany);
    if (sz.penznem) t = t.filter((x) => x.penznem === sz.penznem);
    if (sz.ceg) t = t.filter((x) => x.ceg_id === sz.ceg);
    const vanSzuro = sz.penznem || sz.ceg || sz.irany !== 'MIND';
    const ossz = {};
    t.forEach((x) => { const k = x.irany + ' ' + x.penznem; ossz[k] = (ossz[k] || 0) + foErtek(x); });
    main.innerHTML = `<h1>Fizetési határidők <small>· lejárat szerint</small></h1>
      <div class="csipek">
        ${[['MIND', 'Bejövő + kimenő'], ['BE', 'Csak bejövő'], ['KI', 'Csak kimenő']].map(([v, f]) => `<button type="button" class="csip ${sz.irany === v ? 'aktiv' : ''}" data-act="hat-irany" data-v="${v}">${f}</button>`).join('')}
        <button type="button" class="csip ${sz.utalasAlatt ? 'aktiv' : ''}" data-act="hat-utalasalatt">Utaláshoz adottak is</button>
      </div>
      ${vanSzuro ? `<div class="csipek">${sz.penznem ? `<button type="button" class="csip aktiv" data-act="hat-penznem" data-v="">${esc(sz.penznem)} pénznem <span class="x">×</span></button>` : ''}${sz.ceg ? `<button type="button" class="csip aktiv" data-act="hat-ceg" data-id="">${esc(sz.cegNev)} <span class="x">×</span></button>` : ''}<button type="button" class="csip narancs" data-act="hat-reset">${I.x} Alaphelyzet</button></div>` : ''}
      <div class="alcim-sor">${t.length} tétel${Object.keys(ossz).length ? ' · ' + Object.keys(ossz).sort().map((k) => `${k.startsWith('BE') ? 'fizetendő' : 'várható'} ${fmtOsszeg(ossz[k], k.slice(3))}`).join(' · ') : ''}. Kattints a <b>cégnévre</b> vagy a <b>pénznemre</b> a szűréshez, a <b>kötésre</b> / <b>számlaszámra</b> az ugráshoz.</div>
      <div class="hatarido-fej"><span>Határidő</span><span>Cég</span><span>Számlaszám</span><span>Összeg</span><span>Pénznem</span><span>Kötés / számla ID</span><span>Irány</span><span></span></div>
      ${t.length ? t.map((x) => {
        const link = x.irany === 'BE' ? `#/bejovo/ceg/${x.ceg_id}/kotes/${x.kotes_pk}` : `#/kimeno/ceg/${x.ceg_id}`;
        return `<div class="hatarido-sor ${hataridoOszt(x.hatarido)}">
          <div class="c-hat">${datumChip(x.hatarido)}<span class="datum-szoveg">${fmtDatum(x.hatarido)}<br>${esc(hataridoSzoveg(x.hatarido))}</span></div>
          <div class="c-ceg"><span class="kattint" data-act="hat-ceg" data-id="${x.ceg_id}" data-nev="${esc(x.ceg_nev)}" title="Szűrés erre a cégre">${esc(x.ceg_nev)}</span></div>
          <div class="c-szsz"><a href="${link}?szamla=${x.szamla_id}" title="Ugrás a számlához">„${esc(x.szamlaszam)}”</a>${x.statusz === 'UTALASHOZ_ADVA' ? ' ' + badge('UTALASHOZ_ADVA') : ''}</div>
          <div class="c-osszeg${negOszt(foErtek(x))}">${fmtOsszeg(foErtek(x))} <span class="kattint" data-act="hat-penznem" data-v="${x.penznem}" title="Szűrés erre a pénznemre">${x.penznem}</span>${Number(x.reszt) > 0 ? `<small class="hatralek-jel" title="Eredeti összeg ${fmtOsszeg(x.osszeg, x.penznem)}, részteljesítés ${fmtOsszeg(x.reszt, x.penznem)}">hátralék (eredeti ${fmtOsszeg(x.osszeg)})</small>` : ''}</div>
          <div class="c-penznem"><span class="badge ${x.penznem} link" data-act="hat-penznem" data-v="${x.penznem}" title="Szűrés erre a pénznemre">${x.penznem}</span></div>
          <div class="c-id">${x.irany === 'BE' ? `<span><a href="${link}" title="Ugrás a kötéshez">${esc(x.kotes_kod)}</a><a href="${link}?szamla=${x.szamla_id}" title="Ugrás a számlához">-${esc(x.kod.slice(-5))}</a></span>` : `<a href="${link}?szamla=${x.szamla_id}">${esc(x.kod)}</a>`}<span class="badge ${x.irany}">${x.irany === 'BE' ? 'BEJÖVŐ' : 'KIMENŐ'}</span></div>
          <div class="c-irany"><span class="badge ${x.irany}">${x.irany === 'BE' ? 'BEJÖVŐ' : 'KIMENŐ'}</span></div>
          <div class="c-ny">${nyomtatGomb(x.irany === 'BE' ? 'bejovo' : 'kimeno', x.szamla_id, `${x.kod} · „${x.szamlaszam}”`)}</div>
        </div>`;
      }).join('') : `<div class="ures">${URES}Nincs a szűrésnek megfelelő, még rendezetlen számla.</div>`}`;
  }
  function hataridoSzuroToast() {
    const sz = App.hataridoSzuro;
    const r = [];
    if (sz.penznem) r.push(`${sz.penznem} pénznemre`);
    if (sz.ceg) r.push(`CÉG-re: ${sz.cegNev}`);
    if (sz.irany !== 'MIND') r.push(sz.irany === 'BE' ? 'bejövő irányra' : 'kimenő irányra');
    toast(r.length ? `Szűrve ${esc(r.join(' és '))}` : 'Szűrés törölve – minden tétel látszik');
  }

  // -------------------------------------------------------------- ÖSSZEVETÉS
  async function viewOsszevetes(query) {
    const main = shell({ alcim: 'Összevetés / Összesítő', vissza: '#/' });
    try {
      const cegek = await cegekBetolt();
      const tab = query.tab === 'bank' ? 'bank' : 'egyenleg';
      const ev = App.ma.slice(0, 4);
      const q = { ceg: query.ceg || '', penznem: query.penznem || 'EUR', tol: query.tol || `${ev}-01-01`, ig: query.ig || App.ma };
      main.innerHTML = `<h1>Összevetés / Összesítő</h1>
        <div class="fulek" style="--n:2;--i:${tab === 'bank' ? 1 : 0}"><button type="button" class="${tab === 'egyenleg' ? 'aktiv' : ''}" data-act="ful-nav" data-href="#/osszevetes${qs({ ceg: q.ceg, penznem: q.penznem, tol: q.tol, ig: q.ig })}">Cég egyenlege</button><button type="button" class="${tab === 'bank' ? 'aktiv' : ''}" data-act="ful-nav" data-href="#/osszevetes?tab=bank">Banki kivonatok</button></div>
        <div data-tartalom></div>`;
      const box = $('[data-tartalom]', main);
      if (tab === 'bank') return rajzolBankKivonatok(box, query);
      box.innerHTML = `<div class="mezo"><label>Cég</label><select data-act="ov-ceg"><option value="">– válassz céget –</option>${cegek.map((c) => `<option value="${c.id}" ${String(q.ceg) === String(c.id) ? 'selected' : ''}>${esc(c.nev)}</option>`).join('')}</select></div>
        <div data-egyenleg></div>
        <div data-urlap class="${q.ceg ? '' : 'rejtett'}">
          <div class="mezo"><label>Pénznem</label><div class="valaszto">${['HUF', 'EUR'].map((p) => `<label><input type="radio" name="ov-penznem" value="${p}" ${q.penznem === p ? 'checked' : ''}><span>${p}</span></label>`).join('')}</div></div>
          <div class="mezo-sor"><div class="mezo"><label>Teljesítés dátuma -tól</label><input type="date" data-ov="tol" value="${esc(q.tol)}" required></div><div class="mezo"><label>-ig</label><input type="date" data-ov="ig" value="${esc(q.ig)}" required></div></div>
          <button class="btn btn-blokk" type="button" data-act="ov-lekerdez">${I.scale} Lekérdezés</button>
          <div data-reszletek></div>
        </div>`;
      if (q.ceg) {
        rajzolEgyenleg($('[data-egyenleg]', box), q.ceg);
        if (query.ceg && query.tol && query.ig && query.penznem) rajzolReszletek($('[data-reszletek]', box), q);
      }
    } catch (e) { main.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }
  async function rajzolEgyenleg(box, cegId) {
    box.innerHTML = '<div class="toltes"></div>';
    try {
      const d = await api('osszevetes_egyenleg', { ceg_id: cegId });
      const p = d.penznemek;
      const magyaraz = (v, pn) => (v > 0 ? `${fmtOsszeg(v, pn)} – ennyivel tartozom neki` : v < 0 ? `${fmtOsszeg(-v, pn)} – ennyivel tartozik nekem` : `0 ${pn} – rendezve`);
      let arf = '';
      if (d.arfolyam && d.osszesen) {
        const a = d.arfolyam;
        arf = `Árfolyam: 1 EUR = ${fmtOsszeg(a.eur_huf)} HUF (${esc(a.forras)}, ${fmtDatum(a.datum || '')}) · <b>összesen ≈ ${fmtOsszeg(d.osszesen.HUF, 'HUF')} ≈ ${fmtOsszeg(d.osszesen.EUR, 'EUR')}</b> ${d.osszesen.HUF > 0 ? '– ennyivel tartozom a cégnek' : d.osszesen.HUF < 0 ? '– ennyit várok a cégtől' : ''} (csak tájékoztató adat)`;
      } else {
        arf = 'Árfolyam jelenleg nem elérhető (MNB/ECB lekérés sikertelen és nincs kézi árfolyam beállítva).';
      }
      box.innerHTML = `<div class="egyenleg-fej kartya">
        <div class="cegnev">${esc(d.ceg.nev)}</div>
        <div class="osszegek">
          <div class="${p.EUR.egyenleg > 0 ? 'pozitiv' : p.EUR.egyenleg < 0 ? 'negativ' : ''}"><span data-countup="${p.EUR.egyenleg}" data-penznem="EUR">${fmtOsszeg(p.EUR.egyenleg, 'EUR')}</span><small>${esc(magyaraz(p.EUR.egyenleg, 'EUR'))}</small></div>
          <div class="${p.HUF.egyenleg > 0 ? 'pozitiv' : p.HUF.egyenleg < 0 ? 'negativ' : ''}"><span data-countup="${p.HUF.egyenleg}" data-penznem="HUF">${fmtOsszeg(p.HUF.egyenleg, 'HUF')}</span><small>${esc(magyaraz(p.HUF.egyenleg, 'HUF'))}</small></div>
        </div>
        <div class="osszesites" style="font-size:12px">
          <div><span>Bejövő nyitott EUR</span><b>${fmtOsszeg(p.EUR.bejovo_nyitott)}</b></div><div><span>Kimenő nyitott EUR</span><b>${fmtOsszeg(p.EUR.kimeno_nyitott)}</b></div>
          <div><span>Bejövő nyitott HUF</span><b>${fmtOsszeg(p.HUF.bejovo_nyitott)}</b></div><div><span>Kimenő nyitott HUF</span><b>${fmtOsszeg(p.HUF.kimeno_nyitott)}</b></div>
        </div>
        <div class="arfolyam">${arf}</div>
      </div>`;
      animSzamok(box);
    } catch (e) { box.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }
  async function rajzolReszletek(box, q) {
    box.innerHTML = '<div class="toltes"></div>';
    try {
      const d = await api('osszevetes_reszletek', { ceg_id: q.ceg, penznem: q.penznem, tol: q.tol, ig: q.ig });
      const pn = d.penznem;
      const b = d.bejovo, k = d.kimeno;
      box.innerHTML = `
        <div class="info-doboz" style="margin-top:14px"><b>${esc(d.ceg.nev)}</b> · ${pn} · teljesítés ${fmtDatum(d.tol)} – ${fmtDatum(d.ig)}<br>
          NYITOTT egyenleg: <b class="${negOszt(d.egyenleg_nyitott)}">${fmtOsszeg(d.egyenleg_nyitott, pn)}</b> · FIZETVE egyenleg: <b class="${negOszt(d.egyenleg_fizetve)}">${fmtOsszeg(d.egyenleg_fizetve, pn)}</b>
          <span class="kicsi szurke">(bejövő − kimenő; pozitív: én tartozom, negatív: ő tartozik)</span></div>
        <button class="btn btn-sarga btn-blokk" type="button" style="margin-top:10px" data-act="ov-kosarba-pdf" data-ceg="${d.ceg.id}" data-ceg-nev="${esc(d.ceg.nev)}" data-pn="${esc(pn)}" data-tol="${esc(d.tol)}" data-ig="${esc(d.ig)}" title="A teljes összevetés (egyenleg, bejövő és kimenő számlák) a nyomtatási kosárba, és PDF új lapon">${I.print} Teljes összevetés a kosárba + PDF</button>
        <div class="ketoszlop">
          <div>
            <div class="oszlop-fej be"><span>Bejövő számlák egyenleg összesen</span><span class="o">${fmtOsszeg(b.osszesites.osszes, pn)}</span></div>
            <div class="oszlop-test">
              <div class="reszossz"><span>Nyitott (fizetendő + utalás alatt)</span><b>${fmtOsszeg(b.osszesites.nyitott, pn)}</b></div>
              <div class="reszossz"><span>Fizetve / beszámítva</span><b>${fmtOsszeg(b.osszesites.fizetve, pn)}</b></div>
              <div class="reszletes">${b.kotesek.length ? b.kotesek.map((kt) => `
                <div class="kotes-sor"><a class="kod" href="#/bejovo/ceg/${d.ceg.id}/kotes/${kt.kotes_pk}">${esc(kt.kotes_kod)}${kt.kotes_megnevezes ? `<small>${esc(kt.kotes_megnevezes)}</small>` : ''}</a><span class="jobb"><span class="${negOszt(kt.osszeg)}">${fmtOsszeg(kt.osszeg, pn)}</span>${nyomtatGomb('kotes', kt.kotes_pk, kt.kotes_kod + (kt.kotes_megnevezes ? ' · ' + kt.kotes_megnevezes : ''))}</span></div>
                ${kt.szamlak.map((s) => `<div class="szamla-sor"><div class="bal"><a class="k" href="#/bejovo/ceg/${d.ceg.id}/kotes/${kt.kotes_pk}?szamla=${s.id}">${esc(s.k)}</a><span class="szsz">„${esc(s.szamlaszam)}”</span>${badge(s.statusz)}${s.utalas_uid ? `<span class="mono kicsi">${esc(s.utalas_uid)}</span>` : ''}${fizetveSzoveg(s, 'utalva')}${Number(s.reszt) > 0 && nyitottSzamla(s) ? `<span class="kicsi szurke">eredeti ${fmtOsszeg(s.osszeg, pn)} · részt. −${fmtOsszeg(s.reszt, pn)}</span>` : ''}</div><span class="o${negOszt(foErtek(s))}">${fmtOsszeg(foErtek(s), pn)}</span>${nyomtatGomb('bejovo', s.id, `${kt.kotes_kod}-${s.k} · „${s.szamlaszam}”`)}</div>`).join('')}
              `).join('') : '<div class="ures">Nincs bejövő számla az időszakban.</div>'}</div>
            </div>
          </div>
          <div>
            <div class="oszlop-fej ki"><span>Kimenő számlák egyenleg összesen</span><span class="o">${fmtOsszeg(k.osszesites.osszes, pn)}</span></div>
            <div class="oszlop-test">
              <div class="reszossz"><span>Nyitott</span><b>${fmtOsszeg(k.osszesites.nyitott, pn)}</b></div>
              <div class="reszossz"><span>Fizetve</span><b>${fmtOsszeg(k.osszesites.fizetve, pn)}</b></div>
              <div class="reszletes">${k.szamlak.length ? k.szamlak.map((s) => `
                <div class="kotes-sor"><a class="kod" href="#/kimeno/ceg/${d.ceg.id}?szamla=${s.id}">${esc(s.kod)}</a><span class="jobb"><span class="${negOszt(foErtek(s))}">${fmtOsszeg(foErtek(s), pn)}</span>${nyomtatGomb('kimeno', s.id, `${s.kod} · „${s.szamlaszam}”`)}</span></div>
                <div class="szamla-sor"><div class="bal"><span class="szsz">„${esc(s.szamlaszam)}”</span>${badge(s.statusz)}${fizetveSzoveg(s, 'fizetve')}${s.banki_azonosito ? `<span class="kicsi">bank: ${esc(s.banki_azonosito)}</span>` : ''}${Number(s.reszt) > 0 && nyitottSzamla(s) ? `<span class="kicsi szurke">eredeti ${fmtOsszeg(s.osszeg, pn)} · részt. −${fmtOsszeg(s.reszt, pn)}</span>` : ''}</div><span class="o${negOszt(foErtek(s))}">${fmtOsszeg(foErtek(s), pn)}</span></div>
              `).join('') : '<div class="ures">Nincs kimenő számla az időszakban.</div>'}</div>
            </div>
          </div>
        </div>`;
    } catch (e) { box.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }
  async function rajzolBankKivonatok(box, query) {
    const q = query.q || '';
    box.innerHTML = `<div class="kereso mezo"><input type="search" data-bank-q placeholder="Banki azonosító keresése…" value="${esc(q)}"><button class="btn" type="button" data-act="bank-keres">Keresés</button></div><div data-bank-lista><div class="toltes"></div></div>`;
    const lista = $('[data-bank-lista]', box);
    try {
      const d = await api('bank_kivonatok', { q });
      lista.innerHTML = d.kivonatok.length ? d.kivonatok.map((k) => `<div class="kartya kattinthato" data-act="bank-reszlet" data-id="${k.id}">
          <div class="fejsor osszeg-alul"><div class="info" style="min-width:0"><div class="cim">${I.bank} ${esc(k.azonosito)}</div><div class="alsor"><span>${fmtDatum(k.datum)}</span><span>${k.db} számla</span>${k.cegek ? `<span>${esc(k.cegek)}</span>` : ''}</div></div>
            <div class="osszeg">${k.osszeg_EUR ? `<div>${fmtOsszeg(k.osszeg_EUR, 'EUR')}</div>` : ''}${k.osszeg_HUF ? `<div>${fmtOsszeg(k.osszeg_HUF, 'HUF')}</div>` : ''}</div>${nyomtatGomb('kivonat', k.id, k.azonosito)}<span class="nyil">${I.chev}</span></div>
          <div data-bank-szamlak class="rejtett"></div></div>`).join('') : `<div class="ures">${URES}Nincs ${q ? 'a keresésnek megfelelő ' : ''}banki kivonat. Kimenő számla FIZETVE státuszba állításakor jön létre.</div>`;
    } catch (e) { lista.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }

  // ------------------------------------------------------------ EXCEL IMPORT
  const IMP_TIPUS = { BE: 'bejövő EUR', BH: 'bejövő HUF', KE: 'kimenő EUR', KH: 'kimenő HUF' };
  const IMP_ALLAPOT = { uj: ['ÚJ', 'FIZETVE'], letezik: ['MÁR MEGVAN', 'kesobb'], utkozik: ['MÁSHOL SZEREPEL', 'UTALASHOZ_ADVA'], dupla: ['DUPLA', 'kesobb'], hiba: ['HIBÁS', 'lejart'] };
  async function viewImport(query) {
    const main = shell({ alcim: 'Excel import · régi adatok', vissza: '#/' });
    if (!jog('ir')) { main.innerHTML = `<div class="hiba-doboz">Az Excel importhoz Rögzítő, Irodavezető vagy Admin jogosultság kell (a te szereped: ${esc(szerepNev())}).</div>`; return; }
    try {
      const token = query.token || '';
      if (!token) {
        App.importAllapot = null;
        const a = await api('import_allapot');
        main.innerHTML = `<h1>Excel import <small>· régi számlák felvitele</small></h1>
          <div class="kartya">
            <div class="cim" style="font-weight:800;font-size:16px">1. Töltsd fel az Excel fájlt (.xlsx)</div>
            <p class="kicsi" style="margin:8px 0 12px">A fájl felépítését a rendszer felismeri, minden sorát ellenőrzi, és <b>előnézetet</b> mutat – az adatbázisba csak a 2. lépésben, a te jóváhagyásod után kerül bármi. Kétféle fájlt fogad el:</p>
            <div class="import-oszlopok">
              <div class="fej">Számlanapló (könyvelőprogram exportja) – bejövő és kimenő, EUR és HUF vegyesen</div>
              <div><b>A</b> irány: BE = bejövő EUR, BH = bejövő HUF, KE = kimenő EUR, KH = kimenő HUF</div>
              <div><b>D</b> bejövő számlaszám (szabadkezes) · kimenőnél a számlaszám: <b>A-C/B</b> (pl. KE-41/2026)</div>
              <div><b>J</b> számla kelte · <b>K</b> teljesítés · <b>M</b> fizetési határidő</div>
              <div><b>N</b> partnerkód · <b>O</b> cég neve · <b>P</b> adószám (ha van)</div>
              <div><b>Z</b> számla összege (bruttó, devizában) · <b>AA</b> deviza (ellenőrzés) · <b>AB</b> megjegyzés</div>
              <div class="fej">Kötéskönyv – csak bejövő számlák, a régi kötések szerint</div>
              <div><b>A</b> régi kötés ID · <b>B</b> régi K azonosító · <b>C</b> cég neve · <b>D</b> megnevezés / ügylet</div>
              <div><b>E</b> számla kelte · <b>F</b> teljesítés · <b>G</b> számlaszám (szabadkezes)</div>
              <div><b>H</b> összeg EUR · <b>I</b> összeg HUF (amelyik ki van töltve) · <b>J</b> BESZÁM</div>
            </div>
            <div class="info-doboz">${a.mentes_kell ? `${I.db} <b>Az import előtt automatikusan teljes adatbázis-mentés készül</b> a DBBCKP mappába, így bármikor visszaállítható az import előtti állapot.` : `<b>Az import előtti automatikus adatbázis-mentés ki van kapcsolva</b> (admin beállítás).${a.admin ? ' Az Admin oldalon kapcsolhatod vissza.' : ''}`}</div>
            <div class="muveletek"><span class="tolt"></span><label class="btn" title="Excel-munkafüzet (.xlsx) kiválasztása">${I.sheet} Excel fájl kiválasztása…<input type="file" accept=".xlsx,.xlsm,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" data-act="import-fajl" hidden></label></div>
            <div class="kicsi szurke" style="margin-top:8px">Legfeljebb ${a.max_sor} adatsor / fájl, a tárhely feltöltési korlátja: ${esc(a.max_feltoltes)}. Régi .xls fájlt Excelben ments el .xlsx-ként.</div>
          </div>
          <div class="kartya">
            <div class="cim" style="font-weight:800;font-size:16px">Mi történik importáláskor?</div>
            <ul class="import-lista">
              <li>A cégeket <b>partnerkód</b> alapján ismerjük fel; ha nincs kód, a név alapján – <b>hasonló nevet is</b> (pl. „ASICA” = „Asica Group B.V.”, 55% feletti egyezés). A párosítást a 2. lépésben átnézheted és átállíthatod, a hasonló írásmódú neveket a rendszer összevonja, hogy ne jöjjön létre kétszer ugyanaz a cég.</li>
              <li><b>Kötéskönyvnél</b> a régi kötés ID-k szerint cégenként és pénznemenként kötések jönnek létre (régi ID-val), a számlák ezekbe kerülnek új K sorszámmal; a régi K azonosító a számlán megmarad.</li>
              <li>A <b>bejövő</b> számlák cégenként, pénznemenként és évenként egy <b>ARCHÍV kötésbe</b> kerülnek. Onnan később kijelölheted őket, és egy rendes kötésbe helyezheted (új azonosítóval, a régi kötés ID megadásával).</li>
              <li>A <b>kimenő</b> számlák a rendszer saját azonosítóját kapják (év-pénznem-sorszám), a számlaszámuk KE-…/év lesz.</li>
              <li>Amit már egyszer importáltál (ugyanaz a számlaszám ugyanannál a cégnél), az <b>„már megvan”</b> jelzést kap és kimarad – a fájl nyugodtan újra feltölthető.</li>
              <li>Ha két különböző cég ugyanazt a számlaszámot használja, az <b>ütközés</b>: választhatod, hogy kimarad, vagy a számlaszám végére kerül a partnerkód szögletes zárójelben.</li>
            </ul>
          </div>`;
        return;
      }
      if (!App.importAllapot || App.importAllapot.token !== token) {
        const d = await api('import_elemzes', { token });
        importAllapotInit(token, d.elemzes, d.mentes_kell);
      }
      // az alsó „Importálás” sáv a main-en KÍVÜL (a main animált – egy transzformált elem a fixed gyereket magához köti)
      const main2 = shell({ alcim: 'Excel import · régi adatok', vissza: '#/', fabEmelt: true, alsoSav: `<div class="also-sav"><button class="btn btn-zold" type="button" data-act="import-inditas" data-import-gomb>${I.check} Importálás</button></div>` });
      importOldalRajzol(main2);
    } catch (e) {
      if (e.kod === 'IMPORT_LEJART') { App.importAllapot = null; toast(e.message, 'hiba', 6000); nav('#/import'); return; }
      main.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`;
    }
  }
  function importAllapotInit(token, elemzes, mentesKell) {
    App.importAllapot = { token, elemzes, mentesKell, kivalasztott: new Set(), opciok: { bejovo_statusz: 'FIZETENDO', kimeno_statusz: 'NYITOTT', utkozes: 'enged', hatarido_nap: 30 }, szuro: 'mind', cegSzuro: 'bizonytalan' };
    elemzes.sorok.forEach((s) => { if (s.allapot === 'uj' || (s.allapot === 'utkozik' && utkozoValaszthato(s))) App.importAllapot.kivalasztott.add(s.i); });
  }
  /** Máshol már szereplő (ütköző) számlaszámú sor importálható-e a beállítás szerint */
  function utkozoValaszthato(s) {
    const m = App.importAllapot.opciok.utkozes;
    return m === 'enged' || (m === 'toldalek' && s.toldalek_szabad);
  }
  function importUtkozesAlap() {
    const st = App.importAllapot;
    st.elemzes.sorok.forEach((s) => { if (s.allapot === 'utkozik') { if (utkozoValaszthato(s)) st.kivalasztott.add(s.i); else st.kivalasztott.delete(s.i); } });
  }
  async function importFeltoltes(input) {
    const f = input.files && input.files[0];
    if (!f) return;
    input.value = '';
    if (!/\.xls[xm]$/i.test(f.name)) return toast('Csak .xlsx Excel-munkafüzet tölthető fel.', 'hiba');
    const m = modal({ bezarhato: false, osztaly: 'nagy-uzenet', html: `<div class="toltes"></div><div class="fo">Fájl elemzése…</div><div class="kis">${esc(f.name)} (${fmtMeret(f.size)}) – minden sort ellenőrzünk, az adatbázis még nem változik.</div>` });
    try {
      const fd = new FormData(); fd.append('fajl', f);
      const r = await fetch('import.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': App.csrf }, body: fd });
      let j = null; try { j = await r.json(); } catch (e) { throw new Error('Érvénytelen válasz a szervertől (HTTP ' + r.status + ') – lehet, hogy a fájl túl nagy a tárhely feltöltési korlátjához.'); }
      if (j.csrf) App.csrf = j.csrf;
      if (!j.ok) throw new Error(j.hiba || 'Az elemzés sikertelen');
      m.bezar();
      importAllapotInit(j.adat.token, j.adat.elemzes, j.adat.mentes_kell);
      nav('#/import?token=' + j.adat.token);
    } catch (e) { m.bezar(); hibaToast(e); }
  }
  const IMP_FORMATUM = { szamlanaplo: 'Számlanapló (könyvelőprogram export)', koteskonyv: 'Kötéskönyv (csak bejövő számlák)' };
  const IMP_MOD = { kod: 'partnerkód', nev: 'név', hasonlo: 'hasonló név', uj: 'új cég' };
  function importOldalRajzol(main) {
    const st = App.importAllapot;
    const el = st.elemzes;
    const o = el.osszesites;
    const t = el.tipusok;
    const kk = el.formatum === 'koteskonyv';
    const tipusok = Object.keys(t).filter((k) => t[k]).map((k) => `<span class="csip">${k} · ${IMP_TIPUS[k]}: <b>${t[k]}</b></span>`).join('');
    const vanBejovo = t.BE + t.BH > 0, vanKimeno = t.KE + t.KH > 0;
    main.innerHTML = `<h1>Excel import <small>· 2. lépés: ellenőrzés és jóváhagyás</small></h1>
      <div class="kartya">
        <div class="fejsor"><div style="min-width:0"><div class="cim">${I.sheet} ${esc(el.fajl)} <span class="szurke">·</span> ${esc(el.lap)}</div>
          <div class="alsor"><span><b>${esc(IMP_FORMATUM[el.formatum] || el.formatum)}</b></span><span>${el.sorok.length} adatsor</span><span>${el.kihagyott} nem adatsor kihagyva (fejléc, összesítő, üres)</span></div>
          <div class="alsor" data-import-cegossz></div></div>
          <button class="btn btn-outline btn-sm" type="button" data-act="import-uj">${I.x} Másik fájl</button></div>
        <div class="csipek" style="margin-top:10px">${tipusok}</div>
        <div class="csipek" data-import-chipek></div>
      </div>
      <div class="kartya import-cegek">
        <div class="fejsor"><div><div class="cim" style="font-weight:800;font-size:15px">Cégek egyeztetése <span class="szurke" data-import-cegdb></span></div>
          <div class="alsor"><span>A fájlban szereplő cégneveket a rendszer partnerkód, pontos vagy hasonló név (≥ 55%) alapján párosította a meglévő cégekhez, a hasonló írásmódú neveket összevonta. <b>Nézd át a bizonytalan (?) sorokat</b> – a legördülőből bármelyik céget választhatod, az új cég nevét átírhatod, egy névváltozatot a × jellel leválaszthatsz.</span></div></div></div>
        <div class="csipek import-cegszurok">${[['bizonytalan', 'Bizonytalan'], ['uj', 'Új cég'], ['db', 'Meglévő'], ['mind', 'Mind']].map(([v, f]) => `<button type="button" class="csip ${(st.cegSzuro || 'bizonytalan') === v ? 'aktiv' : ''}" data-act="import-cegszuro" data-v="${v}">${f}</button>`).join('')}</div>
        <div class="import-csoportok" data-import-csoportok></div>
      </div>
      <div class="kartya import-opciok">
        <div class="cim" style="font-weight:800;font-size:15px;margin-bottom:6px">Beállítások</div>
        ${vanBejovo ? `<div class="mezo"><label>Bejövő számlák státusza az importban</label><div class="valaszto"><label><input type="radio" name="bejovo_statusz" value="FIZETENDO" data-act="import-opcio" ${st.opciok.bejovo_statusz === 'FIZETENDO' ? 'checked' : ''}><span>FIZETENDŐ</span></label><label><input type="radio" name="bejovo_statusz" value="FIZETVE" data-act="import-opcio" ${st.opciok.bejovo_statusz === 'FIZETVE' ? 'checked' : ''}><span>FIZETVE (régi, rendezett)</span></label></div><div class="segit">FIZETVE-ként importálva nem jelennek meg a fizetési határidők között; negatív tétel BESZÁMÍTVA lesz.</div></div>` : ''}
        ${kk ? `<div class="mezo"><label>Fizetési határidő <span class="opc">(a kötéskönyvben nincs)</span></label><div class="mezo-sor" style="align-items:center"><span>a számla kelte +</span><input type="number" name="hatarido_nap" min="0" max="365" value="${Number(st.opciok.hatarido_nap) || 0}" data-act="import-opcio" style="max-width:110px"></div><div class="segit">nap – pl. 30. Ez csak a FIZETENDŐ-ként importált számláknál számít (határidő-lista).</div></div>` : ''}
        ${vanKimeno ? `<div class="mezo"><label>Kimenő számlák státusza az importban</label><div class="valaszto"><label><input type="radio" name="kimeno_statusz" value="NYITOTT" data-act="import-opcio" ${st.opciok.kimeno_statusz === 'NYITOTT' ? 'checked' : ''}><span>NYITOTT</span></label><label><input type="radio" name="kimeno_statusz" value="FIZETVE" data-act="import-opcio" ${st.opciok.kimeno_statusz === 'FIZETVE' ? 'checked' : ''}><span>FIZETVE (régi, rendezett)</span></label></div><div class="segit">FIZETVE esetén egy „IMPORT-dátum” banki azonosítót kapnak, fizetés dátuma = fizetési határidő.</div></div>` : ''}
        <div class="mezo" data-import-utkozes><label>Máshol már szereplő számlaszámok (<span data-import-utkozes-db>${o.utkozik}</span> sor)</label><div class="valaszto"><label><input type="radio" name="utkozes" value="enged" data-act="import-opcio" ${st.opciok.utkozes === 'enged' ? 'checked' : ''}><span>Importálás változatlanul</span></label><label><input type="radio" name="utkozes" value="kihagy" data-act="import-opcio" ${st.opciok.utkozes === 'kihagy' ? 'checked' : ''}><span>Kihagyás</span></label><label><input type="radio" name="utkozes" value="toldalek" data-act="import-opcio" ${st.opciok.utkozes === 'toldalek' ? 'checked' : ''}><span>Importálás toldalékkal</span></label></div><div class="segit">Ugyanaz a számlaszám másik cégnél / kimenő oldalon már szerepel. Egy kötésen belül egy számlaszám csak egyszer lehet, más kötésben megengedett – ezért alapból változatlanul importáljuk. Toldalékkal: a számlaszám végére kerül a partnerkód (ha nincs, a cégnév eleje), pl. <b>2026/0004 [10081]</b>.</div></div>
        ${kk ? `<div class="info-doboz" style="margin:6px 0 0">${I.rope} <b>Kötéskönyv:</b> a régi kötés ID-k (A oszlop) szerint <b>cégenként és pénznemenként</b> kötések jönnek létre a rendszerben (régi ID-val, a D oszlop megnevezésével), a számlák ezekbe kerülnek a rendszer szerinti új K sorszámmal; a régi K azonosító (B) a számlán megmarad. Ha egy régi kötés ID-hoz több cég tartozik (pl. áru + fuvar), cégenként külön kötés lesz ugyanazzal a régi ID-val. Régi ID nélküli sor az ARCHÍV kötésbe kerül. <span data-import-kotesossz></span></div>` : ''}
        <div class="info-doboz" style="margin:6px 0 0">${st.mentesKell ? `${I.db} Az import indításakor <b>automatikusan teljes adatbázis-mentés</b> készül (DBBCKP), utána adagonként, tranzakciókban kerülnek be a kiválasztott sorok.` : '<b>Figyelem:</b> az import előtti automatikus adatbázis-mentés ki van kapcsolva (admin beállítás).'}</div>
      </div>
      <div class="lista-fej"><span class="cim">Sorok</span><span class="darab" data-import-darab></span></div>
      <div class="csipek import-szurok">
        ${[['mind', 'Mind'], ['uj', 'Új'], ['utkozik', 'Máshol szerepel'], ['letezik', 'Már megvan'], ['hiba', 'Hibás / dupla'], ['figy', 'Figyelmeztetés']].map(([v, f]) => `<button type="button" class="csip ${st.szuro === v ? 'aktiv' : ''}" data-act="import-szuro" data-v="${v}">${f}</button>`).join('')}
        <span class="tolt"></span>
        <button type="button" class="csip" data-act="import-jelol-mind" data-mod="mind">Összes kijelölése</button>
        <button type="button" class="csip" data-act="import-jelol-mind" data-mod="semmi">Kijelölés törlése</button>
      </div>
      <div class="import-sorok" data-import-sorok></div>`;
    importOsszegzokRajzol();
    importCsoportokRajzol();
    importSorokRajzol();
  }
  function importOsszegzokRajzol() {
    const st = App.importAllapot, el = st.elemzes, o = el.osszesites, c = el.ceg_osszesites;
    const chip = $('[data-import-chipek]');
    if (chip) chip.innerHTML = `<span class="csip zold">új: <b>${o.uj}</b></span>${o.letezik ? `<span class="csip">már megvan: <b>${o.letezik}</b></span>` : ''}${o.utkozik ? `<span class="csip sarga">máshol szerepel: <b>${o.utkozik}</b></span>` : ''}${o.dupla ? `<span class="csip">dupla a fájlban: <b>${o.dupla}</b></span>` : ''}${o.hiba ? `<span class="csip piros">hibás: <b>${o.hiba}</b></span>` : ''}${o.figyelmeztetes ? `<span class="csip">figyelmeztetés: <b>${o.figyelmeztetes}</b></span>` : ''}`;
    const co = $('[data-import-cegossz]');
    if (co) co.innerHTML = `<span>cégcsoportok: <b>${el.csoportok.length}</b> (${c.letezik} meglévő céghez párosítva, <b>${c.uj} új cég</b>${c.bizonytalan ? `, <b class="negativ">${c.bizonytalan} bizonytalan</b>` : ''})</span>`;
    const cd = $('[data-import-cegdb]'); if (cd) cd.textContent = `· ${el.csoportok.length} csoport, ${el.nevek.length} névváltozat`;
    const ud = $('[data-import-utkozes-db]'); if (ud) ud.textContent = String(o.utkozik);
    const ub = $('[data-import-utkozes]'); if (ub) ub.classList.toggle('rejtett', !o.utkozik);
    const ko = $('[data-import-kotesossz]'); if (ko && el.kotesek_osszesites) ko.innerHTML = `Ebben a fájlban <b>${el.kotesek_osszesites.db}</b> kötés jön létre${el.kotesek_osszesites.meglevo ? `, ebből ${el.kotesek_osszesites.meglevo} már meglévő kötéshez csatlakozik` : ''}.`;
  }
  function importCsoportokRajzol() {
    const st = App.importAllapot, el = st.elemzes;
    const box = $('[data-import-csoportok]');
    if (!box) return;
    const szuro = st.cegSzuro || 'bizonytalan';
    const lista = el.csoportok.filter((cs) => szuro === 'mind' || (szuro === 'bizonytalan' && cs.bizonytalan) || (szuro === 'uj' && cs.cel.tipus !== 'db') || (szuro === 'db' && cs.cel.tipus === 'db'));
    const cegek = el.db_cegek.slice().sort((a, b) => a.nev.localeCompare(b.nev, 'hu'));
    box.innerHTML = lista.length ? lista.map((cs) => {
      const jel = {}; cs.jeloltek.forEach((j) => { jel[j.ceg_id] = j.pont; });
      const jeloltOpts = cs.jeloltek.map((j) => `<option value="db:${j.ceg_id}" ${cs.cel.tipus === 'db' && cs.cel.ceg_id === j.ceg_id ? 'selected' : ''}>${esc(j.nev)} · ${j.pont}%</option>`).join('');
      const tobbi = cegek.filter((c) => !jel[c.id]).map((c) => `<option value="db:${c.id}" ${cs.cel.tipus === 'db' && cs.cel.ceg_id === c.id ? 'selected' : ''}>${esc(c.nev)}${c.partnerkod ? ' · P' + esc(c.partnerkod) : ''}</option>`).join('');
      const masCsoport = el.csoportok.filter((x) => x.id !== cs.id && x.cel.tipus !== 'csoport').map((x) => `<option value="csoport:${x.id}" ${cs.cel.tipus === 'csoport' && cs.cel.id === x.id ? 'selected' : ''}>${esc(x.cel.tipus === 'db' ? (el.db_cegek.find((c) => c.id === x.cel.ceg_id) || {}).nev || x.uj_nev : x.uj_nev)}${x.cel.tipus === 'db' ? '' : ' (új)'}</option>`).join('');
      const j = cs.javaslat || {};
      const jelveny = cs.cel.tipus === 'db' ? `<span class="badge ${cs.bizonytalan ? 'UTALASHOZ_ADVA' : 'FIZETVE'}" title="Javaslat alapja">${cs.bizonytalan ? '?' : '✓'} ${esc(IMP_MOD[j.mod] || j.mod || '')}${j.pont ? ' ' + j.pont + '%' : ''}</span>` : `<span class="badge ${cs.bizonytalan ? 'UTALASHOZ_ADVA' : 'FIZETENDO'}">${cs.bizonytalan ? '? ' : ''}${cs.cel.tipus === 'csoport' ? 'ÖSSZEVONVA' : 'ÚJ CÉG'}</span>`;
      return `<div class="import-csoport ${cs.bizonytalan ? 'bizonytalan' : ''}" data-cs="${cs.id}">
        <div class="nevek">${cs.nevek.map((n) => `<span class="csip kicsi">${esc(n)}${cs.nevek.length > 1 ? `<button type="button" class="x" data-act="import-csoport-levalaszt" data-cs="${cs.id}" data-nev="${esc(n)}" title="Leválasztás külön csoportba">×</button>` : ''}</span>`).join('')}<span class="szurke kicsi">${cs.sorok} sor${cs.partnerkod ? ` · P${esc(cs.partnerkod)}` : ''}</span></div>
        <div class="cel">${jelveny}
          <select data-act="import-csoport-cel" data-cs="${cs.id}">
            <option value="uj" ${cs.cel.tipus === 'uj' ? 'selected' : ''}>＋ Új cég létrehozása</option>
            ${jeloltOpts ? `<optgroup label="Hasonló meglévő cégek">${jeloltOpts}</optgroup>` : ''}
            <optgroup label="Minden meglévő cég">${tobbi}</optgroup>
            ${masCsoport ? `<optgroup label="Ugyanaz, mint a fájl másik csoportja">${masCsoport}</optgroup>` : ''}
          </select>
          ${cs.cel.tipus === 'uj' ? `<input type="text" class="uj-nev" value="${esc(cs.uj_nev)}" data-act="import-csoport-nev" data-cs="${cs.id}" placeholder="Az új cég neve" title="Az új cég neve a rendszerben">` : ''}
        </div>
      </div>`;
    }).join('') : `<div class="ures kicsi">Nincs a szűrésnek megfelelő cégcsoport.${szuro === 'bizonytalan' ? ' Minden párosítás egyértelmű.' : ''}</div>`;
  }
  let importCsoportIdo = null;
  function importCsoportKuld() {
    clearTimeout(importCsoportIdo);
    importCsoportIdo = setTimeout(async () => {
      const st = App.importAllapot;
      const box = $('[data-import-csoportok]'); if (box) box.classList.add('frissul');
      try {
        const d = await api('import_csoportok', { token: st.token, csoportok: st.elemzes.csoportok.map((cs) => ({ id: cs.id, nevek: cs.nevek, uj_nev: cs.uj_nev, cel: cs.cel, bizonytalan: !!cs.bizonytalan })) });
        const regi = st.elemzes;
        st.elemzes = d.elemzes;
        // kijelölés megtartása, ahol lehet
        const kiv = new Set();
        d.elemzes.sorok.forEach((s) => { const valaszthato = s.allapot === 'uj' || (s.allapot === 'utkozik' && utkozoValaszthato(s)); if (valaszthato && (st.kivalasztott.has(s.i) || (regi.sorok[s.i] && regi.sorok[s.i].allapot !== 'uj' && s.allapot === 'uj'))) kiv.add(s.i); });
        st.kivalasztott = kiv;
        importOsszegzokRajzol(); importCsoportokRajzol(); importSorokRajzol();
      } catch (e) { hibaToast(e); }
      finally { const b2 = $('[data-import-csoportok]'); if (b2) b2.classList.remove('frissul'); }
    }, 400);
  }
  function importCsoport(id) { return App.importAllapot.elemzes.csoportok.find((c) => c.id === Number(id)); }
  function importCsoportCel(el) {
    const cs = importCsoport(el.dataset.cs); if (!cs) return;
    const v = el.value;
    if (v === 'uj') cs.cel = { tipus: 'uj' };
    else if (v.startsWith('db:')) cs.cel = { tipus: 'db', ceg_id: Number(v.slice(3)) };
    else if (v.startsWith('csoport:')) cs.cel = { tipus: 'csoport', id: Number(v.slice(8)) };
    cs.bizonytalan = false;
    importCsoportKuld();
  }
  function importCsoportNev(el) {
    const cs = importCsoport(el.dataset.cs); if (!cs) return;
    cs.uj_nev = el.value.trim() || cs.nevek[0];
    importCsoportKuld();
  }
  function importCsoportLevalaszt(el) {
    const st = App.importAllapot;
    const cs = importCsoport(el.dataset.cs); if (!cs || cs.nevek.length < 2) return;
    const nev = el.dataset.nev;
    cs.nevek = cs.nevek.filter((n) => n !== nev);
    if (cs.uj_nev === nev) cs.uj_nev = cs.nevek[0];
    const maxId = Math.max(0, ...st.elemzes.csoportok.map((c) => c.id));
    st.elemzes.csoportok.push({ id: maxId + 1, nevek: [nev], uj_nev: nev, partnerkod: '', adoszam: '', cel: { tipus: 'uj' }, javaslat: { tipus: 'uj', pont: 0, mod: 'uj' }, jeloltek: [], sorok: 0, bizonytalan: false });
    st.cegSzuro = 'mind';
    $$('.import-cegszurok .csip').forEach((c) => c.classList.toggle('aktiv', c.dataset.v === 'mind'));
    importCsoportKuld();
  }
  function importSorLathato(s) {
    const sz = App.importAllapot.szuro;
    if (sz === 'mind') return true;
    if (sz === 'hiba') return s.allapot === 'hiba' || s.allapot === 'dupla';
    if (sz === 'figy') return s.figyelmeztetes.length > 0;
    return s.allapot === sz;
  }
  function importSorokRajzol() {
    const st = App.importAllapot;
    const box = $('[data-import-sorok]');
    if (!box) return;
    const el = st.elemzes;
    const sorok = el.sorok.filter(importSorLathato);
    const csMap = {}; el.csoportok.forEach((c) => { csMap[c.id] = c; });
    const dbMap = {}; el.db_cegek.forEach((c) => { dbMap[c.id] = c; });
    const celNev = (cs) => { if (!cs) return null; const c = cs.cel.tipus === 'csoport' ? csMap[cs.cel.id] || cs : cs; return c.cel.tipus === 'db' ? (dbMap[c.cel.ceg_id] || {}).nev || '' : c.uj_nev; };
    const MAX = 500;
    box.innerHTML = (sorok.length ? sorok.slice(0, MAX).map((s) => {
      const [cimke, oszt] = IMP_ALLAPOT[s.allapot];
      const valaszthato = s.allapot === 'uj' || (s.allapot === 'utkozik' && utkozoValaszthato(s));
      const cs = s.csoport_id != null ? csMap[s.csoport_id] : null;
      const cn = celNev(cs);
      const ujCeg = cs && (cs.cel.tipus === 'csoport' ? (csMap[cs.cel.id] || cs).cel.tipus !== 'db' : cs.cel.tipus !== 'db');
      const bejelolt = st.kivalasztott.has(s.i);
      const szsz = s.allapot === 'utkozik' && st.opciok.utkozes === 'toldalek' && s.toldalek_szabad ? s.toldalekos : s.szamlaszam;
      return `<div class="import-sor ${s.allapot} ${bejelolt ? 'bejelolt' : ''}">
        <label class="kijelolo ${bejelolt ? 'bejelolt' : ''} ${valaszthato ? '' : 'tiltott'}"><input type="checkbox" data-act="import-sor" data-i="${s.i}" ${bejelolt ? 'checked' : ''} ${valaszthato ? '' : 'disabled'}>${I.check}</label>
        <div class="kozep">
          <div class="cim"><span class="badge ${s.irany === 'BEJOVO' ? 'BE' : 'KI'}">${s.tipus}</span> <b>„${esc(szsz)}”</b> <span class="badge ${oszt}">${cimke}</span>${s.figyelmeztetes.length ? ' <span class="badge hamarosan" title="' + esc(s.figyelmeztetes.join('; ')) + '">!</span>' : ''}</div>
          <div class="alsor"><span>${esc(s.ceg_nev)}${cn && cn !== s.ceg_nev ? ` <span class="szurke">→ ${esc(cn)}</span>` : ''}${s.partnerkod ? ` <span class="mono">P${esc(s.partnerkod)}</span>` : ''}${ujCeg ? ' <span class="badge FIZETENDO">ÚJ CÉG</span>' : ''}</span><span>kelt ${fmtDatum(s.kelt)}</span><span>telj. ${fmtDatum(s.teljesites)}</span>${s.hatarido ? `<span>határidő <b>${fmtDatum(s.hatarido)}</b></span>` : ''}<span class="szurke">Excel ${s.sor}. sor</span></div>
          ${s.regi_kotes || s.regi_k || s.kotes_cel ? `<div class="alsor"><span class="mono">${s.regi_kotes ? 'régi kötés ' + esc(s.regi_kotes) : ''}${s.regi_k ? ' · K ' + esc(s.regi_k) : ''}</span>${s.kotes_cel ? `<span>→ ${esc(s.kotes_cel)}</span>` : ''}${s.beszam ? `<span>BESZÁM: <b>${esc(s.beszam)}</b></span>` : ''}${s.kotes_megnevezes ? `<span class="szurke">${esc(s.kotes_megnevezes)}</span>` : ''}</div>` : ''}
          ${s.uzenet ? `<div class="alsor uzenet ${s.allapot}">${esc(s.uzenet)}</div>` : ''}
          ${s.figyelmeztetes.length ? `<div class="alsor uzenet figy">${esc(s.figyelmeztetes.join(' · '))}</div>` : ''}
          ${s.megjegyzes ? `<div class="alsor"><span class="szurke">${esc(s.megjegyzes.length > 120 ? s.megjegyzes.slice(0, 120) + '…' : s.megjegyzes)}</span></div>` : ''}
        </div>
        <div class="osszeg${s.osszeg && Number(s.osszeg) < 0 ? ' negativ' : ''}">${s.osszeg ? fmtOsszeg(Number(s.osszeg), s.penznem) : '–'}</div>
      </div>`;
    }).join('') : `<div class="ures">${URES}Nincs a szűrésnek megfelelő sor.</div>`) + (sorok.length > MAX ? `<div class="alcim-sor">… és még ${sorok.length - MAX} sor (a szűrőkkel szűkítheted a listát; az import a rejtett kijelölt sorokra is vonatkozik).</div>` : '');
    importOsszegzoFrissit();
  }
  function importOsszegzoFrissit() {
    const st = App.importAllapot;
    const n = st.kivalasztott.size;
    let be = 0, ki = 0;
    const ossz = {};
    st.elemzes.sorok.forEach((s) => { if (st.kivalasztott.has(s.i)) { if (s.irany === 'BEJOVO') be++; else ki++; const k = (s.irany === 'BEJOVO' ? 'bejövő ' : 'kimenő ') + s.penznem; ossz[k] = (ossz[k] || 0) + Number(s.osszeg || 0); } });
    const d = $('[data-import-darab]'); if (d) d.textContent = `${n} kijelölve / ${st.elemzes.sorok.length}`;
    const g = $('[data-import-gomb]');
    if (g) { g.disabled = n === 0; g.innerHTML = `${I.check} <span class="btn-szoveg">Importálás – ${n} sor${n ? `<small>${be ? be + ' bejövő' : ''}${be && ki ? ' · ' : ''}${ki ? ki + ' kimenő' : ''} · ${Object.keys(ossz).map((k) => k.split(' ')[1] + ' ' + fmtOsszeg(ossz[k])).join(' · ')}</small>` : ''}</span>`; }
    $$('.import-sor').forEach((row) => { const cb = $('input[data-act=import-sor]', row); if (cb) { const on = st.kivalasztott.has(Number(cb.dataset.i)); row.classList.toggle('bejelolt', on); cb.closest('.kijelolo').classList.toggle('bejelolt', on); cb.checked = on; } });
  }
  function importJelolMind(mod) {
    const st = App.importAllapot;
    st.elemzes.sorok.filter(importSorLathato).forEach((s) => {
      const valaszthato = s.allapot === 'uj' || (s.allapot === 'utkozik' && utkozoValaszthato(s));
      if (mod === 'mind' && valaszthato) st.kivalasztott.add(s.i); else if (mod === 'semmi') st.kivalasztott.delete(s.i);
    });
    importOsszegzoFrissit();
  }
  async function importInditas() {
    const st = App.importAllapot;
    const ids = Array.from(st.kivalasztott);
    if (!ids.length) return toast('Nincs kijelölt sor.', 'hiba');
    const el = st.elemzes;
    if (el.ceg_osszesites.bizonytalan) {
      const ok = await confirmModal('Bizonytalan cégpárosítások', `<p>A cégek egyeztetésénél <b>${el.ceg_osszesites.bizonytalan} bizonytalan</b> párosítás van (hasonló, de nem azonos nevek). Ha nem nézted át őket, a számlák rossz céghez kerülhetnek.</p><p class="kicsi">A „Bizonytalan” szűrővel a Cégek egyeztetése részben látod őket; egy csoport legördülőjének átállítása megerősítésnek számít.</p>`, { ok: 'Mégis importálom', megse: 'Átnézem előbb', okOsztaly: 'btn-piros' });
      if (!ok) { st.cegSzuro = 'bizonytalan'; $$('.import-cegszurok .csip').forEach((c) => c.classList.toggle('aktiv', c.dataset.v === 'bizonytalan')); importCsoportokRajzol(); $('.import-cegek').scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    }
    let be = 0, ki = 0, told = 0;
    const csMap = {}; el.csoportok.forEach((c) => { csMap[c.id] = c; });
    const ujCegek = new Set();
    el.sorok.forEach((s) => { if (st.kivalasztott.has(s.i)) { if (s.irany === 'BEJOVO') be++; else ki++; if (s.allapot === 'utkozik') told++; const cs = s.csoport_id != null ? csMap[s.csoport_id] : null; if (cs) { const c = cs.cel.tipus === 'csoport' ? csMap[cs.cel.id] || cs : cs; if (c.cel.tipus !== 'db') ujCegek.add(c.id); } } });
    const ok = await confirmModal('Import indítása', `<p><b>${ids.length} sor</b> kerül az adatbázisba: ${be ? `${be} bejövő számla (${el.formatum === 'koteskonyv' ? 'régi kötés ID szerinti kötésekbe' : 'ARCHÍV kötésekbe'}, ${st.opciok.bejovo_statusz === 'FIZETVE' ? 'FIZETVE' : 'FIZETENDŐ'} státusszal)` : ''}${be && ki ? ', ' : ''}${ki ? `${ki} kimenő számla (${st.opciok.kimeno_statusz})` : ''}.${ujCegek.size ? ` <b>${ujCegek.size} új cég</b> jön létre.` : ''}${told ? ` ${told} ütköző számlaszám toldalékkal kerül be.` : ''}</p>
      <p class="kicsi">${st.mentesKell ? `${I.db} Előtte automatikusan teljes adatbázis-mentés készül a DBBCKP mappába.` : '<b>Import előtti mentés nélkül</b> (admin kikapcsolta).'} A sorok ${ids.length > 400 ? 'adagokban, adagonként egy-egy tranzakcióban' : 'egyetlen tranzakcióban'} kerülnek be.</p>`, { ok: 'Importálás', okOsztaly: 'btn-zold' });
    if (!ok) return;
    const ADAG = 400;
    const adagok = []; for (let i = 0; i < ids.length; i += ADAG) adagok.push(ids.slice(i, i + ADAG));
    const m = modal({ bezarhato: false, osztaly: 'nagy-uzenet', html: `<div class="toltes"></div><div class="fo">Importálás…</div><div class="kis" data-import-halad>${st.mentesKell ? 'Előbb az adatbázis mentése, majd ' : ''}${ids.length} sor rögzítése. Ne zárd be az oldalt!</div><div class="halad" style="margin-top:14px"><span data-import-halad-sav style="width:0"></span></div>` });
    const ossz = { bejovo: 0, kimeno: 0, uj_ceg: 0, uj_kotes: 0, uj_archiv: 0, kihagyott: 0, toldalekos: 0, fizetve: 0 };
    let ujKotesek = [], ujKotesekDb = 0, kihagyva = [], mentes = null, kesz = 0;
    try {
      for (let a = 0; a < adagok.length; a++) {
        const r = await api('import_vegrehajt', { token: st.token, sorok: adagok[a], opciok: st.opciok, elso: a === 0, utolso: a === adagok.length - 1 });
        Object.keys(ossz).forEach((k) => { ossz[k] += r.stat[k] || 0; });
        ujKotesek = ujKotesek.concat(r.uj_kotesek || []); ujKotesekDb += r.uj_kotesek_db || 0; kihagyva = kihagyva.concat(r.kihagyva || []);
        if (r.mentes) mentes = r.mentes;
        kesz += adagok[a].length;
        const h = $('[data-import-halad]'); if (h) h.textContent = `${kesz} / ${ids.length} sor kész…`;
        const sav = $('[data-import-halad-sav]'); if (sav) sav.style.width = Math.round(100 * kesz / ids.length) + '%';
      }
      m.bezar();
      App.cegek = null; hataridoAdat = null; App.importAllapot = null;
      await uzenetModal(`<div class="fo">Import kész!</div>
        <div class="import-eredmeny">
          <div><span>Bejövő számla</span><b>${ossz.bejovo}</b></div><div><span>Kimenő számla</span><b>${ossz.kimeno}</b></div>
          <div><span>Új cég</span><b>${ossz.uj_ceg}</b></div><div><span>Új kötés</span><b>${ossz.uj_kotes + ossz.uj_archiv}</b></div>
          ${ossz.toldalekos ? `<div><span>Toldalékos számlaszám</span><b>${ossz.toldalekos}</b></div>` : ''}${ossz.kihagyott ? `<div><span>Kihagyva</span><b>${ossz.kihagyott}</b></div>` : ''}
        </div>
        ${ujKotesek.length ? `<p class="kicsi" style="text-align:left">${ujKotesekDb > ujKotesek.length ? `Az első ${ujKotesek.length} új kötés (összesen ${ujKotesekDb}): ` : 'Új kötések: '}${ujKotesek.map((k) => `<a href="#/bejovo/ceg/${k.ceg_id}/kotes/${k.id}" class="mono">${esc(k.kod)}</a>${k.regi_kod ? ` (régi ${esc(k.regi_kod)})` : ''}`).join(', ')}</p>` : ''}
        ${kihagyva.length ? `<p class="kicsi" style="text-align:left">Kihagyott sorok: ${kihagyva.slice(0, 20).map((x) => `${x.sor}. sor „${esc(x.szamlaszam)}” – ${esc(x.ok)}`).join('; ')}${kihagyva.length > 20 ? ' …' : ''}</p>` : ''}
        <p class="kicsi">${mentes ? `Import előtti mentés: <b class="mono">${esc(mentes.fajl)}</b>` : 'Import előtti mentés nem készült (kikapcsolva).'} · A részletek a tevékenységnaplóban (EXCEL_IMPORT).</p>`, { pipa: true, ok: 'Rendben' });
      nav(be ? '#/bejovo' : '#/kimeno');
    } catch (e) {
      m.bezar();
      if (e.kod === 'IMPORT_LEJART') { App.importAllapot = null; nav('#/import'); }
      await uzenetModal(`<div class="fo" style="color:var(--piros)">Az import megszakadt</div><p class="kozepre">${esc(e.message)}</p><p class="kozepre kicsi">${kesz ? `${kesz} sor már bekerült (adagonként külön tranzakció), a többi nem. A fájl újratöltésével a hiányzók pótolhatók – ami már bent van, „már megvan” jelzést kap.` : 'Semmi nem került az adatbázisba (a tranzakció visszagördült).'}</p>`, { ok: 'Értem', okOsztaly: 'btn-piros' });
    }
  }

  // ------------------------------------------------------- ADMIN: DB-mentések
  const fmtMeret = (b) => (b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' kB');
  const MENTES_BADGE = { auto: 'FIZETVE', potlas: 'UTALASHOZ_ADVA', kezi: 'EUR', visszaallitas_elott: 'FIZETENDO', feltoltott: 'HUF', idegen: 'kesobb' };
  function mentesekHtml(m) {
    const a = m.allapot;
    const u = a.utolso;
    const cronSzoveg = a.cron_ok ? 'ÉJSZAKAI MENTÉS RENDBEN' : (a.utolso_cron ? 'CRON NEM FUTOTT' : 'CRON MÉG NINCS BEÁLLÍTVA');
    return `<div class="kartya mentes-allapot">
        <div class="fejsor"><div style="min-width:0">
          <div class="cim">${u ? `Utolsó mentés: ${esc(fmtIdo(u.ido_szoveg))} <span class="szurke">·</span> ${esc(u.mod_nev)} <span class="szurke">·</span> ${fmtMeret(u.meret)}` : 'Még nem készült mentés'}</div>
          <div class="alsor"><span>${a.db} fájl · ${fmtMeret(a.osszmeret)}</span><span>következő éjszakai: <b>${esc(fmtIdo(a.kovetkezo))}</b></span>${a.megorzes_nap ? `<span>az automatikus mentések ${a.megorzes_nap} napig maradnak meg</span>` : '<span>minden mentés megmarad</span>'}</div>
          <div class="alsor"><span class="mono kicsi">${esc(a.mappa)}</span>${a.irhato ? '' : '<span class="negativ"><b>A mappa nem írható!</b></span>'}</div>
        </div><span class="badge ${a.cron_ok ? 'FIZETVE' : 'lejart'}" title="${a.utolso_cron ? 'utolsó cron-mentés: ' + esc(fmtIdo(a.utolso_cron.ido_szoveg)) : 'még nem futott cron-mentés'}">${cronSzoveg}</span></div>
        ${a.cron_ok ? '' : `<div class="info-doboz" style="margin:12px 0 0">${a.utolso_auto ? `Az utolsó automatikus mentés: <b>${esc(fmtIdo(a.utolso_auto.ido_szoveg))}</b> (${esc(a.utolso_auto.mod_nev)}). ` : ''}A tárhelyen a <b>cron jobot</b> kell beállítani, hogy minden nap ${a.ora}:00-kor az oldal megnyitása nélkül is elkészüljön a teljes mentés – a parancs lent, a „Cron beállítása” gomb alatt.${a.potlas ? ' Amíg a cron nincs beállítva, az alkalmazás az első napi használatkor pótolja a mentést (…_potlas.sql).' : ''}</div>`}
        <div class="muveletek">
          <button class="btn btn-sm" type="button" data-act="mentes-most">${I.db} Mentés most</button>
          <label class="btn btn-outline btn-sm" title="Korábban letöltött .sql mentés feltöltése a DBBCKP mappába">${I.in} Feltöltés<input type="file" accept=".sql,application/sql,text/plain" data-act="mentes-feltolt" hidden></label>
          <button class="btn btn-outline btn-sm" type="button" data-act="cron-mutat">${I.gear} Cron beállítása</button>
        </div>
        <div class="cron-doboz rejtett" data-cron>
          <p class="kicsi" style="margin:0 0 8px">A cPanel <b>Cron Jobs</b> menüjében adj hozzá egy feladatot: <b>minden nap ${String(a.ora).padStart(2, '0')}:00</b> (perc: <b>0</b>, óra: <b>${a.ora}</b>, nap / hónap / hét: <b>*</b>). Parancsnak az egyik alábbi sor. A cron a tárhely <b>szerverideje</b> szerint fut – most magyar idő szerint ${esc(a.szerver_ido.slice(0, 5))}, UTC szerint ${esc(a.utc_ido)}; ha a cPanel más időzónát mutat, számold át az órát (pl. UTC-s szervernél télen ${(a.ora + 23) % 24} óra, nyáron ${(a.ora + 22) % 24} óra).</p>
          <div class="cron-sor"><div><b>1. PHP parancssorból</b> (ajánlott)<code>${esc(a.cron_cli)}</code><span class="kicsi szurke">Ha a tárhely nem ismeri a <code>php</code> parancsot, a teljes útvonalat add meg, pl. <code>/usr/local/bin/php</code>.</span></div><button class="btn btn-outline btn-sm" type="button" data-act="masol" data-szoveg="${esc(a.cron_cli)}">Másolás</button></div>
          <div class="cron-sor"><div><b>2. URL-hívással</b> (ha a tárhely csak ezt engedi)<code>${esc(a.cron_wget)}</code><span class="kicsi szurke">vagy: <code>${esc(a.cron_curl)}</code></span></div><button class="btn btn-outline btn-sm" type="button" data-act="masol" data-szoveg="${esc(a.cron_wget)}">Másolás</button></div>
          <div class="cron-sor"><div><b>Cron-kulcs</b> (az URL-es hívás jelszava – ne add ki senkinek)<code>${esc(a.cron_kulcs)}</code></div><button class="btn btn-outline piros btn-sm" type="button" data-act="cron-kulcs-uj">Új kulcs</button></div>
          <p class="kicsi szurke" style="margin:8px 0 0">Ellenőrzés: a beállítás utáni reggel itt zöld „ÉJSZAKAI MENTÉS RENDBEN” jelzésnek kell lennie, és a listában egy ${String(a.ora).padStart(2, '0')}:00-s automatikus mentésnek. A mentés minden tábla teljes szerkezetét és minden sorát tartalmazza; a fájl phpMyAdmin-ban is importálható.</p>
        </div>
      </div>
      <div class="mentes-lista">${m.mentesek.length ? m.mentesek.map((x) => `<div class="mentes-sor" data-fajl="${esc(x.fajl)}">
          <div class="bal"><div class="cim"><b>${esc(fmtIdo(x.ido_szoveg))}</b> <span class="badge ${MENTES_BADGE[x.mod] || 'kesobb'}">${esc(x.mod_nev)}</span></div><div class="alsor"><span class="mono">${esc(x.fajl)}</span><span>${fmtMeret(x.meret)}</span></div></div>
          <div class="gomb-sor jobbra">
            <button class="btn btn-outline piros btn-sm" type="button" data-act="mentes-visszaallit" data-fajl="${esc(x.fajl)}" data-ido="${esc(fmtIdo(x.ido_szoveg))}" data-mod="${esc(x.mod_nev)}">Visszaállítás</button>
            <a class="btn btn-outline btn-sm btn-ikon" href="mentes_letoltes.php?fajl=${encodeURIComponent(x.fajl)}" title="Letöltés" aria-label="Letöltés" download>${I.out}</a>
            <button class="btn btn-outline btn-sm btn-ikon" type="button" data-act="mentes-torol" data-fajl="${esc(x.fajl)}" title="Törlés" aria-label="Törlés">${I.trash}</button>
          </div>
        </div>`).join('') : `<div class="ures">${URES}Még nincs mentés a DBBCKP mappában. Nyomd meg a „Mentés most” gombot, és állítsd be a cron jobot.</div>`}</div>`;
  }
  async function mentesekFrissit() {
    const box = $('[data-mentesek]');
    if (!box) return;
    try { box.innerHTML = mentesekHtml(await api('admin_mentesek')); } catch (e) { hibaToast(e); }
  }
  async function mentesVisszaallitas(el) {
    const fajl = el.dataset.fajl;
    let info = null;
    try { info = await api('admin_mentes_info', { fajl }); } catch (e) { hibaToast(e); return; }
    if (info.sajat && !info.teljes) { await uzenetModal(`<div class="fo" style="color:var(--piros)">Csonka mentésfájl</div><p class="kozepre">A(z) <b class="mono">${esc(fajl)}</b> fájl vége hiányzik – ebből nem szabad visszaállítani.</p>`, { ok: 'Értem' }); return; }
    const ok = await confirmModal('Adatbázis visszaállítása', `
      <p>Az adatbázis <b>teljes tartalma</b> lecserélődik erre a mentett állapotra:</p>
      <div class="info-doboz"><b>${esc(el.dataset.ido)}</b> · ${esc(el.dataset.mod)}<br><span class="mono kicsi">${esc(fajl)}</span> · ${fmtMeret(info.meret)}${info.tablak != null ? ` · ${info.tablak} tábla, ${info.sorok} sor` : ''}${info.sajat ? '' : '<br><b>Ez nem a BaninaPRO által készített fájl</b> – csak akkor folytasd, ha biztos vagy benne, hogy ennek az alkalmazásnak a teljes adatbázisát tartalmazza.'}</div>
      <p><b>Minden változás elveszik</b>, ami ${info.keszult ? `<b>${esc(fmtIdo(info.keszult))}</b>` : 'a mentés'} óta történt (számlák, utalások, cégek, felhasználók, eszközök, beállítások).</p>
      <p class="kicsi">Biztonság: a visszaállítás előtt <b>automatikusan teljes mentés készül</b> a mostani állapotról (…_visszaallitas_elott.sql), így ez a lépés is visszavonható. Ha a visszaállítás közben hiba történik, az előzetes mentés automatikusan visszatöltődik. A művelet alatt a többi felhasználó egy percig nem tud dolgozni.</p>`, { ok: 'Tovább a megerősítéshez', okOsztaly: 'btn-piros' });
    if (!ok) return;
    if (!await visszaszamlaloModal(`Biztos, hogy visszaállítod az adatbázist a(z) ${el.dataset.ido} állapotra?`, 5)) return;
    const m = modal({ bezarhato: false, osztaly: 'nagy-uzenet', html: `<div class="toltes"></div><div class="fo">Visszaállítás folyamatban…</div><div class="kis">Előbb a mostani állapot mentése, majd a kiválasztott mentés betöltése. Ne zárd be az oldalt!</div>` });
    try {
      const r = await api('admin_db_visszaallit', { fajl, megerosites: 'VISSZAÁLLÍTÁS' });
      m.bezar();
      App.cegek = null; hataridoAdat = null; Kosar._mem = null;
      await uzenetModal(`<div class="fo" style="color:var(--zold)">Visszaállítva!</div><p class="kozepre">Az adatbázis a(z) <b>${esc(el.dataset.ido)}</b> állapotra állt vissza (${r.tablak} tábla, ${r.utasitasok} utasítás, ${String(r.masodperc).replace('.', ',')} mp).</p><p class="kozepre kicsi">A visszaállítás előtti állapot mentése: <b class="mono">${esc(r.elotte)}</b> – ha mégis meggondolod magad, ezt állítsd vissza.</p>${r.bejelentkezve_marad ? '' : '<p class="kozepre kicsi negativ">A visszaállított adatbázisban a te felhasználód nem admin vagy nem létezik – újra be kell jelentkezned.</p>'}`, { pipa: true, ok: 'Rendben' });
      if (r.bejelentkezve_marad) render(); else { App.user = null; location.hash = '#/'; render(); }
    } catch (e) {
      m.bezar();
      await uzenetModal(`<div class="fo" style="color:var(--piros)">A visszaállítás nem sikerült</div><p class="kozepre">${esc(e.message)}</p><p class="kozepre kicsi">A részletek a tevékenységnaplóban vannak (DB_VISSZAALLITAS).</p>`, { ok: 'Értem', okOsztaly: 'btn-piros' });
      mentesekFrissit();
    }
  }
  async function mentesFeltoltes(input) {
    const f = input.files && input.files[0];
    if (!f) return;
    input.value = '';
    if (!/\.sql$/i.test(f.name)) return toast('Csak .sql fájl tölthető fel', 'hiba');
    toast(`${I.in} Feltöltés: ${esc(f.name)} (${fmtMeret(f.size)})…`, '', 60000);
    try {
      const fd = new FormData(); fd.append('fajl', f);
      const r = await fetch('mentes_feltoltes.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': App.csrf }, body: fd });
      let j = null; try { j = await r.json(); } catch (e) { throw new Error('Érvénytelen válasz a szervertől (HTTP ' + r.status + ') – lehet, hogy a fájl túl nagy a tárhely feltöltési korlátjához.'); }
      if (j.csrf) App.csrf = j.csrf;
      if (!j.ok) throw new Error(j.hiba || 'A feltöltés sikertelen');
      const i = j.adat.info;
      toast(`${I.check} Feltöltve: <b>${esc(j.adat.fajl)}</b>${i.sajat ? (i.teljes ? ' (teljes BaninaPRO-mentés)' : ' – <b>FIGYELEM: csonka fájl!</b>') : ' (külső SQL fájl)'}`, i.sajat && !i.teljes ? 'hiba' : 'siker', 6000);
      mentesekFrissit();
    } catch (e) { hibaToast(e); }
  }

  // ------------------------------------------------------------------- ADMIN
  /** Admin → Felhasználók alatti tájékoztató a belépés módjáról (1.15: QR-kapcsoló szerint) */
  function felhBelepesSzoveg(qr) {
    return qr
      ? 'Új felhasználónak adj <b>eszköz-regisztrációs kódot</b> („Kód” gomb): a telefonján beolvassa, Face ID-val / ujjlenyomattal regisztrál, és attól kezdve jelszó nélkül, QR-kóddal lép be. Jelszóval csak az léphet be, akinek még nincs regisztrált eszköze.'
      : 'A QR-kódos belépés ki van kapcsolva: <b>mindenki felhasználónévvel és jelszóval lép be</b>. Az új felhasználónak itt adj jelszót; eszközt regisztrálni ettől még lehet, a QR-kódos belépés visszakapcsolása után használható.';
  }
  /** Admin → Belépés kártya: QR-kódos belépés kapcsoló (1.15) */
  function qrKartyaBelso(qr, jelszoNelkul) {
    return `<label class="jelolo kapcsolo"><input type="checkbox" data-act="qr-belepes-kapcsolo" ${qr ? 'checked' : ''}> <span><b>QR-kódos belépés</b> (telefon + Face ID / ujjlenyomat) – bekapcsolva a belépés QR-kóddal és telefonos jóváhagyással történik, jelszóval csak az léphet be, akinek még nincs regisztrált eszköze.<br><small class="szurke">Kikapcsolva <b>mindenki felhasználónévvel és jelszóval</b> lép be, a QR-kódos belépés szünetel. A regisztrált telefonok megmaradnak: visszakapcsolás után ugyanúgy működnek, újra regisztrálni nem kell. A már belépett felhasználókat a váltás nem lépteti ki.</small></span></label>
      ${!qr && jelszoNelkul.length ? `<div class="hiba-doboz" style="margin-top:10px">Ezeknek a felhasználóknak nincs jelszava, ezért most nem tudnak belépni: <b>${jelszoNelkul.map(esc).join(', ')}</b>. Adj nekik jelszót a fenti listában (ceruza → Új jelszó).</div>` : ''}`;
  }
  async function viewAdmin() {
    const main = shell({ alcim: 'Admin', vissza: '#/' });
    if (!App.user.admin) { main.innerHTML = '<div class="hiba-doboz">Ehhez ADMIN jogosultság kell.</div>'; return; }
    try {
      const [f, b, n, m] = await Promise.all([api('admin_felhasznalok'), api('admin_beallitasok'), api('admin_naplo_fajlok'), api('admin_mentesek')]);
      const kezi = b.beallitasok.eur_huf_kezi ? b.beallitasok.eur_huf_kezi.ertek : '';
      main.innerHTML = `<h1>Admin</h1>
        <h2>Felhasználók</h2>
        <div class="kartya"><table class="tabla"><thead><tr><th>Név</th><th>Szerep</th><th>Utolsó belépés</th><th></th></tr></thead><tbody>
          ${f.felhasznalok.map((u) => `<tr><td><b>${esc(u.felhasznalonev)}</b><br><span class="kicsi szurke">${esc(u.nev)}</span><br><span class="kicsi ${u.passkey_db ? 'szurke' : 'negativ'}">${I.key} ${u.passkey_db || 0} eszköz</span></td><td><span class="badge szerep-${esc(u.szerep)}">${esc(u.szerep_nev || SZEREP_NEV[u.szerep] || u.szerep)}</span>${u.aktiv ? '' : ' <span class="badge lejart">letiltva</span>'}</td><td class="kicsi">${fmtIdo(u.utolso_belepes) || '–'}</td><td class="jobbra"><div class="gomb-sor jobbra"><button class="btn btn-outline zold btn-sm" type="button" data-act="admin-regkod" data-id="${u.id}" data-felh="${esc(u.felhasznalonev)}" title="Eszköz-regisztrációs kód">${I.key} Kód</button>${u.passkey_db ? `<button class="btn btn-outline piros btn-sm btn-ikon" type="button" data-act="admin-passkey-torol" data-id="${u.id}" data-felh="${esc(u.felhasznalonev)}" title="Összes eszköz törlése">${I.trash}</button>` : ''}<button class="btn btn-outline btn-sm btn-ikon" type="button" data-act="admin-felh-szerk" data-id="${u.id}" data-nev="${esc(u.nev)}" data-felh="${esc(u.felhasznalonev)}" data-szerep="${u.szerep}" data-aktiv="${u.aktiv ? 1 : 0}">${I.edit}</button></div></td></tr>`).join('')}
        </tbody></table>
        <p class="kicsi szurke" style="margin:10px 0 0" data-felh-belepes>${felhBelepesSzoveg(b.qr_belepes)}</p>
        <div class="szerep-leiras">${Object.keys(SZEREP_NEV).map((k) => `<div><span class="badge szerep-${k}">${SZEREP_NEV[k]}</span><span>${esc(SZEREP_LEIRAS[k])}</span></div>`).join('')}</div>
        <div class="muveletek"><span class="tolt"></span><button class="btn btn-sm" type="button" data-act="admin-felh-uj">${I.plus} Új felhasználó</button></div></div>
        <h2>Belépés</h2>
        <div class="kartya" data-qr-kartya>${qrKartyaBelso(b.qr_belepes, b.jelszo_nelkul || [])}</div>
        <h2>Árfolyam</h2>
        <div class="kartya">
          <div class="alsor" style="margin-bottom:8px">Jelenlegi: ${b.arfolyam ? `<b>1 EUR = ${fmtOsszeg(b.arfolyam.eur_huf)} HUF</b> (${esc(b.arfolyam.forras)}, ${fmtDatum(b.arfolyam.datum || '')}, lekérve ${fmtIdo(b.arfolyam.lekerve)})` : '<b>nem elérhető</b>'}</div>
          <form data-form="arfolyam"><div class="mezo"><label>Kézi EUR/HUF árfolyam <span class="opc">(tartalék, ha az MNB/ECB lekérés nem működik a szerveren)</span></label><input type="text" inputmode="decimal" name="ertek" value="${esc(kezi || '')}" placeholder="pl. 395,5"></div>
          <div class="gomb-sor"><button class="btn btn-sm" type="submit">Mentés</button><button class="btn btn-outline btn-sm" type="button" data-act="arfolyam-frissit">Automatikus frissítés most</button></div></form>
        </div>
        <h2>Részteljesítés</h2>
        <div class="kartya">
          <label class="jelolo kapcsolo"><input type="checkbox" data-act="reszt-bejovo-kapcsolo" ${b.beallitasok.reszt_bejovo && b.beallitasok.reszt_bejovo.ertek === '1' ? 'checked' : ''}> <span><b>Részteljesítés a bejövő számláknál</b> – bekapcsolva a bejövő számlák szerkesztő ablakában is felvezethető részteljesítés (+ gomb), mint a kimenő számláknál.<br><small class="szurke">Alapból kikapcsolva: a bejövő oldalon nincs részteljesítés, csak a kimenő számláknál. A korábban felvezetett bejövő részteljesítések kikapcsolt állapotban is látszanak, de újat nem lehet felvinni. A kimenő oldalt a kapcsoló nem érinti.</small></span></label>
        </div>
        <h2>Excel import</h2>
        <div class="kartya">
          <label class="jelolo kapcsolo"><input type="checkbox" data-act="import-mentes-kapcsolo" ${b.beallitasok.import_mentes && b.beallitasok.import_mentes.ertek === '0' ? '' : 'checked'}> <span><b>Az Excel import előtt automatikusan teljes adatbázis-mentés készül</b> a DBBCKP mappába (…_import_elott.sql).<br><small class="szurke">Csak admin kapcsolhatja ki – a felhasználók importálhatnak, de ezt a védelmet nem tudják kikapcsolni. Kikapcsolva az import gyorsabb, de egy rossz fájl importja csak az éjszakai mentésből vonható vissza.</small></span></label>
          <div class="muveletek"><span class="tolt"></span><a class="btn btn-outline btn-sm" href="#/import">${I.sheet} Excel import megnyitása</a></div>
        </div>
        <h2>Adatbázis-mentések <small>(DBBCKP mappa · minden nap ${m.allapot.ora}:00-kor teljes mentés)</small></h2>
        <div data-mentesek>${mentesekHtml(m)}</div>
        <h2>Tevékenységnapló <small>(LOG mappa, naponta 03:00-kor új fájl)</small></h2>
        <div class="kartya">
          <div class="kereso mezo"><select data-naplo-fajl>${n.fajlok.length ? n.fajlok.map((x) => `<option value="${esc(x.nev)}">${esc(x.nev)} (${Math.round(x.meret / 1024)} kB)</option>`).join('') : '<option value="">– még nincs naplófájl –</option>'}</select><button class="btn btn-sm" type="button" data-act="naplo-olvas">Megnyitás</button></div>
          <div data-naplo class="naplo-doboz rejtett"></div>
        </div>`;
    } catch (e) { main.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }
  async function viewProfil() {
    const main = shell({ alcim: 'Profil · eszközök', vissza: '#/' });
    let d = { passkeyek: [], van_jelszo: true };
    try { d = await api('passkeyek'); } catch (e) { /* lista nélkül is megy */ }
    const tamogat = waTamogatott();
    main.innerHTML = `<h1>Profil <small>· ${esc(App.user.felhasznalonev)}</small></h1>
      <h2>Eszközeim (passkey – Face ID / ujjlenyomat)</h2>
      <div class="kartya">
        <p class="kicsi szurke" style="margin:0 0 10px">Ezekkel az eszközökkel tudod jóváhagyni a QR-kódos belépést. ${d.passkeyek.length ? 'Amíg van regisztrált eszközöd, jelszóval <b>nem</b> lehet belépni a fiókodba.' : '<b>Még nincs regisztrált eszközöd</b> – addig jelszóval léphetsz be.'}</p>
        ${d.passkeyek.length ? `<table class="tabla"><thead><tr><th>Eszköz</th><th>Regisztrálva</th><th>Utoljára</th><th></th></tr></thead><tbody>${d.passkeyek.map((p) => `<tr><td><b>${esc(p.eszkoz_nev)}</b></td><td class="kicsi">${fmtIdo(p.letrehozva)}</td><td class="kicsi">${p.utolso_hasznalat ? fmtIdo(p.utolso_hasznalat) : '–'}</td><td class="jobbra"><button class="btn btn-outline piros btn-sm btn-ikon" type="button" data-act="passkey-torol" data-id="${p.id}" data-nev="${esc(p.eszkoz_nev)}" title="Eszköz törlése">${I.trash}</button></td></tr>`).join('')}</tbody></table>` : ''}
        <div class="muveletek"><span class="tolt"></span>${tamogat ? `<button class="btn btn-sm" type="button" data-act="passkey-uj">${I.plus} Ezt az eszközt regisztrálom</button>` : '<span class="kicsi szurke">Ez a böngésző nem támogatja a passkey-t.</span>'}</div>
      </div>
      <h2>Jelszó módosítása <small>(tartalék belépés)</small></h2>
      <form class="kartya" data-form="jelszo" autocomplete="off">
      <div class="mezo"><label>Jelenlegi jelszó</label>${jelszoMezo('regi_jelszo', { cimke: 'Jelenlegi jelszó' })}</div>
      <div class="mezo"><label>Új jelszó (min. 8 karakter)</label>${jelszoMezo('uj_jelszo', { cimke: 'Új jelszó', minlength: 8 })}</div>
      <div class="mezo"><label>Új jelszó még egyszer</label>${jelszoMezo('uj_jelszo2', { cimke: 'Új jelszó még egyszer', minlength: 8 })}</div>
      <button class="btn btn-blokk" type="submit">Mentés</button></form>`;
  }

  // ================================================================= űrlapok
  /**
   * 1.14 – jelszómező, amit a böngésző NEM ajánl fel elmenteni és nem is tölt ki: nem type="password", hanem
   * maszkolt szövegmező (-webkit-text-security: disc), autocomplete="off"; a szem gombbal megmutatható.
   * Ha a böngésző nem ismeri a maszkolást (régi Firefox), type="password" + autocomplete="new-password" a tartalék.
   */
  const JELSZO_MASZK = (() => { try { return !!(window.CSS && CSS.supports && CSS.supports('-webkit-text-security', 'disc')); } catch (e) { return false; } })();
  function jelszoMezo(name, o) {
    o = o || {};
    const kozos = `name="${name}" data-jelszo ${o.kotelezo === false ? '' : 'required'} ${o.minlength ? `minlength="${o.minlength}"` : ''} ${o.autofocus ? 'autofocus' : ''} autocapitalize="none" autocorrect="off" spellcheck="false" aria-label="${esc(o.cimke || 'Jelszó')}" ${o.attr || ''}`;
    const mezo = JELSZO_MASZK
      ? `<input type="text" class="jelszo-rejtett" autocomplete="off" ${kozos}>`
      : `<input type="password" autocomplete="new-password" ${kozos}>`;
    return `<div class="jelszo-mezo">${mezo}<button type="button" class="szem" data-act="jelszo-szem" aria-label="Jelszó megjelenítése" title="Jelszó megjelenítése / elrejtése">${I.eye}</button></div>`;
  }
  const inp = (name, cimke, o) => {
    o = o || {};
    if (o.tipus === 'password') {
      return `<div class="mezo"><label>${esc(cimke)}${o.opc ? ' <span class="opc">(nem kötelező)</span>' : ''}</label>${jelszoMezo(name, { kotelezo: !!o.kotelezo, cimke, minlength: o.minlength })}${o.segit ? `<div class="segit">${o.segit}</div>` : ''}<div class="ellenorzes" data-ell="${name}"></div></div>`;
    }
    return `<div class="mezo"><label>${esc(cimke)}${o.opc ? ' <span class="opc">(nem kötelező)</span>' : ''}</label><input type="${o.tipus || 'text'}" name="${name}" value="${esc(o.ertek == null ? '' : o.ertek)}" ${o.tipus === 'text' || !o.tipus ? `autocomplete="off"` : ''} ${o.inputmode ? `inputmode="${o.inputmode}"` : ''} ${o.placeholder ? `placeholder="${esc(o.placeholder)}"` : ''} ${o.kotelezo ? 'required' : ''} ${o.attr || ''}>${o.segit ? `<div class="segit">${o.segit}</div>` : ''}<div class="ellenorzes" data-ell="${name}"></div></div>`;
  };
  const penznemValaszto = (ertek) => `<div class="mezo"><label>Pénznem</label><div class="valaszto">${['EUR', 'HUF'].map((p) => `<label><input type="radio" name="penznem" value="${p}" ${(ertek || 'EUR') === p ? 'checked' : ''} required><span>${p}</span></label>`).join('')}</div></div>`;
  const evValaszto = (ertek) => `<div class="mezo"><label>Év <span class="opc">(az azonosítóban)</span></label><select name="ev">${evek().map((y) => `<option value="${y}" ${String(ertek) === String(y) ? 'selected' : ''}>${y}</option>`).join('')}</select></div>`;
  function formAdat(form) {
    const o = {};
    new FormData(form).forEach((v, k) => { o[k] = typeof v === 'string' ? v.trim() : v; });
    return o;
  }
  function formHiba(form, uzenet) {
    let d = $('[data-hiba]', form);
    if (!d) { d = document.createElement('div'); d.className = 'hiba-doboz'; d.setAttribute('data-hiba', ''); form.insertBefore(d, $('.lablec', form) || null); }
    d.textContent = uzenet; d.classList.remove('rejtett');
  }
  function formModal(cim, html, onSubmit, o) {
    o = o || {};
    const m = modal({ cim, html: `<form data-modal-form novalidate autocomplete="off">${html}<div class="lablec"><button class="btn btn-outline" type="button" data-m="megse">Mégse</button><button class="btn ${o.okOsztaly || ''}" type="submit">${esc(o.ok || 'Mentés')}</button></div></form>`, onBezar: o.onBezar });
    const form = $('form', m.el);
    $('[data-m=megse]', form).addEventListener('click', () => m.bezar());
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const gomb = $('button[type=submit]', form);
      gomb.disabled = true;
      try { await onSubmit(formAdat(form), form, m); }
      catch (err) { formHiba(form, err.message); }
      finally { gomb.disabled = false; }
    });
    const elso = $('input:not([type=radio]):not([type=hidden]), select', form);
    if (elso && window.innerWidth >= 640) setTimeout(() => elso.focus(), 60);
    return { m, form };
  }
  /**
   * Élő számlaszám-ellenőrzés gépelés közben.
   *  - bejövő (kivéve.kotes_id): ugyanabban a kötésben TILTÁS (a mentés gomb inaktív), máshol csak FIGYELMEZTETÉS
   *  - kimenő (kivéve.kimeno): a kimenők között tiltás, bejövővel egyezés figyelmeztetés
   */
  function szamlaszamEllenorzes(form, kivéve) {
    const mezo = $('[name=szamlaszam]', form);
    const ell = $('[data-ell=szamlaszam]', form);
    const gomb = $('button[type=submit]', form);
    let ido = null;
    let utolso = '';
    const allit = (foglalt) => { form.dataset.foglalt = foglalt ? '1' : ''; if (gomb) { gomb.disabled = !!foglalt; gomb.classList.toggle('tiltott', !!foglalt); gomb.title = foglalt ? 'Ebben a kötésben már szerepel ez a számlaszám' : ''; } };
    const fut = async () => {
      const v = mezo.value.trim();
      if (v === utolso) return;
      utolso = v;
      if (!v) { ell.textContent = ''; ell.className = 'ellenorzes'; mezo.className = ''; allit(false); return; }
      try {
        const r = await api('szamlaszam_ellenoriz', Object.assign({ szamlaszam: v }, kivéve || {}));
        if (mezo.value.trim() !== v) return;
        if (r.ervenytelen) { ell.textContent = 'Érvénytelen: legalább egy betű vagy szám kell.'; ell.className = 'ellenorzes foglalt'; mezo.className = 'hibas'; allit(true); }
        else if (r.foglalt) { ell.textContent = '⛔ ' + r.uzenet; ell.className = 'ellenorzes foglalt'; mezo.className = 'hibas'; allit(true); }
        else if (r.figyelmeztetes) { ell.textContent = '⚠ ' + r.figyelmeztetes_uzenet; ell.className = 'ellenorzes figyelmeztetes'; mezo.className = 'figyelem'; allit(false); }
        else { ell.textContent = '✓ Ez a számlaszám még szabad.'; ell.className = 'ellenorzes szabad'; mezo.className = 'jo'; allit(false); }
      } catch (e) { ell.textContent = ''; allit(false); }
    };
    mezo.addEventListener('input', () => { clearTimeout(ido); ido = setTimeout(fut, 350); });
    mezo.addEventListener('blur', fut);
  }

  function cegForm(ceg) {
    const szerk = !!ceg;
    const csakMegj = szerk && !jog('ir');   // Üzletkötő: csak a megjegyzéshez fűzhet hozzá
    formModal(csakMegj ? `Megjegyzés – ${esc(ceg.nev)}` : szerk ? 'Cég szerkesztése' : 'Új cég', `
      ${csakMegj ? `<div class="info-doboz">Üzletkötőként a cég adatait nem módosíthatod, a megjegyzéshez hozzáfűzhetsz.</div><div class="alcim-sor"><b>${esc(ceg.nev)}</b>${ceg.adoszam ? ` · adószám ${esc(ceg.adoszam)}` : ''}${ceg.partnerkod ? ` · P${esc(ceg.partnerkod)}` : ''}</div>` : `${inp('nev', 'Cég neve', { ertek: ceg && ceg.nev, kotelezo: true })}
      <div class="mezo-sor">${inp('adoszam', 'Adószám', { ertek: ceg && ceg.adoszam, opc: true })}${inp('partnerkod', 'Partnerkód (régi rendszer)', { ertek: ceg && ceg.partnerkod, opc: true, inputmode: 'numeric', segit: 'Az Excel import ez alapján ismeri fel a céget.' })}</div>`}
      ${megjegyzesMezo(ceg)}
      ${szerk && !csakMegj ? `<label class="jelolo"><input type="checkbox" name="aktiv" value="1" ${ceg.aktiv ? 'checked' : ''}> Aktív cég (inaktív cég nem jelenik meg a listákban)</label>` : ''}`,
      async (a, form, m) => {
        const megj = megjegyzesErtek(a);
        if (csakMegj) { await api('ceg_modosit', { id: ceg.id, megjegyzes: megj }); toast('Megjegyzés mentve', 'siker'); }
        else if (szerk) { if (!a.nev) throw new Error('A cégnév kötelező.'); await api('ceg_modosit', { id: ceg.id, nev: a.nev, adoszam: a.adoszam, partnerkod: a.partnerkod, megjegyzes: megj, aktiv: !!a.aktiv }); toast('Cég mentve', 'siker'); }
        else { if (!a.nev) throw new Error('A cégnév kötelező.'); await api('ceg_letrehoz', { nev: a.nev, adoszam: a.adoszam, partnerkod: a.partnerkod, megjegyzes: megj }); toast('Új cég létrehozva: ' + esc(a.nev), 'siker'); }
        App.cegek = null; m.bezar(); render();
      });
  }
  function kotesForm(cegId, kotes) {
    const szerk = !!kotes;
    const csakMegj = szerk && !jog('ir');   // Üzletkötő: csak a megjegyzéshez fűzhet hozzá
    const { form } = formModal(csakMegj ? `Megjegyzés – ${esc(kotes.kod)}` : szerk ? `Kötés szerkesztése – ${esc(kotes.kod)}` : 'Új kötés', `
      ${csakMegj ? `<div class="info-doboz">Üzletkötőként a kötés adatait nem módosíthatod, a megjegyzéshez hozzáfűzhetsz.</div><div class="alcim-sor">${badge(kotes.penznem)} ${kotes.megnevezes ? esc(kotes.megnevezes) : ''}${kotes.regi_kod ? ` · régi ID: <b>${esc(kotes.regi_kod)}</b>` : ''}</div>` : `${szerk ? '' : penznemValaszto('EUR') + evValaszto(App.ma.slice(0, 4))}
      ${inp('megnevezes', 'Megnevezés / ügylet', { ertek: kotes && kotes.megnevezes, opc: true, placeholder: 'pl. szeptemberi banánszállítás' })}
      ${inp('regi_kod', 'Régi rendszerbeli kötés ID', { ertek: kotes && kotes.regi_kod, opc: true, placeholder: 'pl. K-2025/117', segit: 'Szabad szöveg. Ha már használtad egy másik kötésnél, figyelmeztetünk.' })}`}
      ${megjegyzesMezo(kotes)}
      ${szerk ? '' : '<div class="info-doboz">Az azonosító automatikusan képződik: <b>év-pénznem-6 jegyű sorszám</b> (pl. 2026-EUR-000001). A pénznem később nem módosítható.</div>'}`,
      async (a, form, m) => {
        const megj = megjegyzesErtek(a);
        const kuld = async (megerositve) => {
          if (csakMegj) { await api('kotes_modosit', { id: kotes.id, megjegyzes: megj }); toast('Megjegyzés mentve', 'siker'); m.bezar(); render(); return; }
          if (szerk) { await api('kotes_modosit', { id: kotes.id, megnevezes: a.megnevezes, megjegyzes: megj, regi_kod: a.regi_kod, megerositve }); toast('Kötés mentve', 'siker'); m.bezar(); render(); return; }
          if (!a.penznem) throw new Error('Válassz pénznemet!');
          const r = await api('kotes_letrehoz', { ceg_id: cegId, penznem: a.penznem, ev: a.ev, megnevezes: a.megnevezes, megjegyzes: megj, regi_kod: a.regi_kod, megerositve });
          m.bezar(); toast('Új kötés: ' + esc(r.kod), 'siker'); nav(`#/bejovo/ceg/${cegId}/kotes/${r.id}`);
        };
        try { await kuld(false); } catch (e) {
          if (e.kod !== 'REGI_KOD_FOGLALT') throw e;
          if (await regiKodMegerosites(a.regi_kod, e.extra.kotesek)) await kuld(true);
        }
      }, { ok: szerk ? 'Mentés' : 'Kötés létrehozása' });
    if (szerk) form.dataset.kotes = String(kotes.id);
    if (!csakMegj) regiKodElore(form);
  }
  /** Élő jelzés a régi kötés ID mezőnél: már használt-e */
  function regiKodElore(form) {
    const mezo = form && $('[name=regi_kod]', form);
    if (!mezo) return;
    const ell = $('[data-ell=regi_kod]', form);
    let ido = null;
    const kiveve = form.dataset.kotes ? Number(form.dataset.kotes) : undefined;
    mezo.addEventListener('input', () => {
      clearTimeout(ido);
      const v = mezo.value.trim();
      if (!v) { ell.textContent = ''; ell.className = 'ellenorzes'; return; }
      ido = setTimeout(async () => {
        try {
          const r = await api('regi_kod_ellenoriz', { regi_kod: v, kiveve });
          if (mezo.value.trim() !== v) return;
          ell.className = 'ellenorzes ' + (r.foglalt ? 'figyelem' : 'ok');
          ell.innerHTML = r.foglalt ? `Ezt a régi ID-t már használtad: ${r.kotesek.map((k) => `<b class="mono">${esc(k.kod)}</b> (${k.szamlak.length} számla)`).join(', ')} – mentéskor megerősítést kérünk.` : 'Még nem használt régi ID.';
        } catch (e) { /* csendes */ }
      }, 350);
    });
  }
  /** Popup: a régi kötés ID-t már használták – Mégse mindig aktív, IGEN csak 5 mp után */
  function regiKodMegerosites(regiKod, kotesek, mp) {
    mp = mp || 5;
    return new Promise((resolve) => {
      const m = modal({
        bezarhato: false, osztaly: 'nagy-uzenet regi-kod',
        html: `<div class="fo" style="color:#c62828">Ezt a régi kötés ID-t már használtad!</div>
          <p class="kozepre">A(z) <b>${esc(regiKod)}</b> régi kötés ID már ezekhez a számlákhoz tartozik:</p>
          <div class="reszletes regi-lista">${(kotesek || []).map((k) => `<div class="kotes-sor"><span class="kod">${esc(k.kod)}<small>${esc(k.ceg_nev)}${k.megnevezes ? ' · ' + esc(k.megnevezes) : ''}</small></span><span>${k.szamlak.length} számla</span></div>
            ${k.szamlak.length ? k.szamlak.map((s) => `<div class="szamla-sor"><div class="bal"><span class="k">${esc(s.kod)}</span><span class="szsz">„${esc(s.szamlaszam)}”</span>${badge(s.statusz)}</div><span class="o${negOszt(s.osszeg)}">${fmtOsszeg(s.osszeg, k.penznem)}</span></div>`).join('') : '<div class="szamla-sor"><div class="bal"><span class="szsz">(még nincs számla ebben a kötésben)</span></div></div>'}`).join('')}</div>
          <p class="kozepre"><b>Biztosan használni akarod ugyanezt a régi ID-t?</b></p>
          <div class="szamlalo" data-m="szam">${mp}</div>
          <div class="kis">Az IGEN gomb csak a visszaszámlálás lejárta után aktiválódik.</div>
          <div class="lablec"><button class="btn btn-outline" type="button" data-m="nem">Mégse</button><button class="btn btn-piros" type="button" data-m="igen" disabled>Igen</button></div>`,
      });
      let n = mp;
      const szam = $('[data-m=szam]', m.el);
      const igen = $('[data-m=igen]', m.el);
      const t = setInterval(() => { n -= 1; szam.textContent = n > 0 ? n : '✓'; if (n <= 0) { clearInterval(t); igen.disabled = false; igen.textContent = 'Igen, használom'; } }, 1000);
      $('[data-m=nem]', m.el).addEventListener('click', () => { clearInterval(t); m.bezar(); resolve(false); });
      igen.addEventListener('click', () => { if (igen.disabled) return; m.bezar(); resolve(true); });
    });
  }
  /** Archív kötés kijelölt számláinak áthelyezése: új vagy meglévő kötésbe */
  async function archivAthelyezForm(cegId, penznem, kotesId) {
    const ids = Array.from(App.archivKijeloles);
    if (!ids.length) return toast('Előbb jelöld ki a számlákat a sorok végén lévő kerek gombbal.', 'hiba');
    let celok = [];
    try { celok = (await api('kotesek', { ceg_id: cegId })).kotesek.filter((k) => !k.archiv && k.penznem === penznem); } catch (e) { /* üres lista */ }
    const { form } = formModal(`${ids.length} számla áthelyezése kötésbe`, `
      <div class="mezo"><label>Hová?</label><div class="valaszto"><label><input type="radio" name="cel" value="uj" checked><span>Új kötés</span></label><label><input type="radio" name="cel" value="meglevo" ${celok.length ? '' : 'disabled'}><span>Meglévő kötés</span></label></div></div>
      <div data-cel-uj>
        ${inp('megnevezes', 'Megnevezés / ügylet', { opc: true, placeholder: 'pl. januári banánszállítás' })}
        ${inp('regi_kod', 'Régi rendszerbeli kötés ID', { opc: true, placeholder: 'pl. K-2025/117', segit: 'Szabad szöveg. Ha már használtad egy másik kötésnél, figyelmeztetünk.' })}
        ${megjegyzesMezo(null)}
        <div class="info-doboz">Az új kötés a mostani rendszer szerinti azonosítót kapja (<b>${esc(App.ma.slice(0, 4))}-${esc(penznem)}-sorszám</b>), a számlák pedig ehhez tartozó új K sorszámot (pl. …-K0001). A régi kötés ID csak tájékoztató adat.</div>
      </div>
      <div data-cel-meglevo class="rejtett">
        <div class="mezo"><label>Kötés</label><select name="kotes_id">${celok.map((k) => `<option value="${k.id}">${esc(k.kod)}${k.megnevezes ? ' · ' + esc(k.megnevezes) : ''}${k.regi_kod ? ' · régi: ' + esc(k.regi_kod) : ''} (${k.db} számla)</option>`).join('')}</select></div>
      </div>`,
      async (a, f, m) => {
        const cel = a.cel === 'meglevo' ? 'meglevo' : 'uj';
        const kuld = async (megerositve) => {
          const r = await api('archiv_szamlak_athelyez', { szamla_ids: ids, cel, kotes_id: cel === 'meglevo' ? Number(a.kotes_id) : undefined, megnevezes: a.megnevezes, regi_kod: a.regi_kod, megjegyzes: a.megjegyzes, megerositve });
          m.bezar(); App.archivKijeloles = new Set();
          toast(`${I.check} ${r.db} számla áthelyezve: <b class="mono">${esc(r.kod)}</b>${r.uj ? ' (új kötés)' : ''}`, 'siker', 6000);
          nav(`#/bejovo/ceg/${cegId}/kotes/${r.kotes_id}`);
        };
        try { await kuld(false); } catch (e) {
          if (e.kod !== 'REGI_KOD_FOGLALT') throw e;
          if (await regiKodMegerosites(a.regi_kod, e.extra.kotesek)) await kuld(true);
        }
      }, { ok: 'Áthelyezés' });
    $$('input[name=cel]', form).forEach((r) => r.addEventListener('change', () => { const uj = form.cel.value === 'uj'; $('[data-cel-uj]', form).classList.toggle('rejtett', !uj); $('[data-cel-meglevo]', form).classList.toggle('rejtett', uj); }));
    regiKodElore(form);
  }
  function bejovoSzamlaForm(kotes, szamla) {
    const szerk = !!szamla;
    const lezart = szerk && (szamla.statusz === 'FIZETVE' || szamla.statusz === 'BESZAMITVA');
    const uzletkoto = szerk && !jog('ir');                 // Üzletkötő: csak BESZÁM + megjegyzés
    const korlatozott = lezart || uzletkoto;
    const beszamVan = !!(szamla && String(szamla.beszam || '').trim());
    const { form } = formModal(uzletkoto ? `BESZÁM / megjegyzés – ${esc(szamla.kod)}` : szerk ? `Számla szerkesztése – ${esc(szamla.kod)}` : `Új számla – ${esc(kotes.kod)}`, `
      <div class="alcim-sor">${badge(kotes.penznem)} ${esc(kotes.ceg_nev || '')}${kotes.megnevezes ? ' · ' + esc(kotes.megnevezes) : ''}${szerk ? ' · ' + badge(szamla.statusz) : ''}</div>
      ${uzletkoto ? `<div class="info-doboz">Üzletkötőként a számla adatait nem módosíthatod: a <b>BESZÁM</b>-ot beírhatod vagy módosíthatod (törölni nem), a megjegyzéshez hozzáfűzhetsz.</div><div class="alsor" style="margin-bottom:10px"><span>„${esc(szamla.szamlaszam)}”</span><span>Kelt: ${fmtDatum(szamla.kelt)}</span><span>Határidő: <b>${fmtDatum(szamla.fizetesi_hatarido)}</b></span><span><b>${fmtOsszeg(szamla.osszeg, kotes.penznem)}</b></span></div>` : lezart ? '<div class="info-doboz">A számla már FIZETVE/BESZÁMÍTVA státuszú: csak a BESZÁM és a megjegyzés módosítható.</div>' : ''}
      ${korlatozott ? '' : inp('szamlaszam', 'Számlaszám (a számlán szereplő, bármilyen formátum)', { ertek: szamla && szamla.szamlaszam, kotelezo: true, placeholder: 'pl. SZ-2026/00017', attr: 'autocapitalize="characters"' })}
      ${korlatozott ? '' : `<div class="mezo-sor">${inp('kelt', 'Számla kelte', { tipus: 'date', ertek: (szamla && szamla.kelt) || App.ma, kotelezo: true })}${inp('teljesites_datum', 'Teljesítési dátum', { tipus: 'date', ertek: (szamla && szamla.teljesites_datum) || App.ma, kotelezo: true })}</div>`}
      ${korlatozott ? '' : `<div class="mezo-sor">${inp('fizetesi_hatarido', 'Fizetési határidő', { tipus: 'date', ertek: szamla && szamla.fizetesi_hatarido, kotelezo: true })}${inp('osszeg', `Számla összege (${kotes.penznem})`, { ertek: szamla ? String(szamla.osszeg).replace('.', ',') : '', kotelezo: true, inputmode: 'text', placeholder: 'pl. 12 500 vagy -2 000', segit: szerk && Number(szamla.reszt) > 0 ? `Ez a számla <b>eredeti</b> összege. A részteljesítések (${fmtOsszeg(szamla.reszt, kotes.penznem)}) után a hátralék: <b>${fmtOsszeg(szamla.hatralek, kotes.penznem)}</b>.` : 'Lehet mínusz szám is (jóváíró / beszámítandó tétel).' })}</div>`}
      ${inp('beszam', 'BESZÁM', { ertek: szamla && szamla.beszam, opc: !(uzletkoto && beszamVan), kotelezo: uzletkoto && beszamVan, placeholder: 'pl. raktári ellenőrző irat száma', segit: uzletkoto && beszamVan ? 'A kitöltött BESZÁM csak módosítható, üresre nem állítható.' : '' })}
      ${megjegyzesMezo(szamla)}
      ${szerk ? resztSzakasz('BEJOVO', szamla) : ''}`,
      async (a, f, m) => {
        if (!korlatozott && f.dataset.foglalt === '1') throw new Error('Ebben a kötésben már szerepel ez a számlaszám – egy kötésen belül ugyanaz a számlaszám csak egyszer rögzíthető (másik kötésben igen).');
        if (uzletkoto && beszamVan && !String(a.beszam || '').trim()) throw new Error('A BESZÁM nem törölhető – ha egyszer ki lett töltve, csak módosítani lehet.');
        a.megjegyzes = megjegyzesErtek(a); delete a.megjegyzes_uj; delete a.megjegyzes_regi;
        if (szerk) { await api('bejovo_szamla_modosit', Object.assign({ id: szamla.id }, a)); toast(uzletkoto ? 'BESZÁM / megjegyzés mentve' : 'Számla mentve', 'siker'); }
        else { const r = await api('bejovo_szamla_letrehoz', Object.assign({ kotes_id: kotes.id }, a)); toast('Új számla: ' + esc(r.kod), 'siker'); }
        allapot.frissit = false; m.bezar(); render();
      }, { ok: szerk ? 'Mentés' : 'Számla rögzítése', onBezar: () => { if (allapot.frissit) render(); } });
    const allapot = { frissit: false };
    if (!korlatozott) szamlaszamEllenorzes(form, Object.assign({ kotes_id: kotes.id }, szerk ? { kiveve_bejovo_id: szamla.id } : {}));
    if (szerk) resztInit(form, 'BEJOVO', szamla, allapot);
  }
  /**
   * RÉSZTELJESÍTÉSEK szakasz a számla szerkesztő ablakában.
   * Alapból csak egy + gomb; rá kattintva összeg + utalás dátuma (kötelező) + banki azonosító (nem kötelező) mezők.
   * A mentés azonnal megtörténik (külön API), a számla NYITOTT marad, a főérték = hátralék.
   */
  function resztSzakasz(irany, szamla) {
    // bejövő számlánál csak akkor, ha az admin bekapcsolta (alapból ki) – a már felvezetett részteljesítések ettől még látszanak
    if (irany === 'BEJOVO' && !resztBejovo() && !(Number(szamla.reszt) > 0)) return '';
    const nyitott = (irany === 'BEJOVO' ? szamla.statusz === 'FIZETENDO' : szamla.statusz === 'NYITOTT') && jog('ir') && (irany !== 'BEJOVO' || resztBejovo());
    return `<div class="reszt-szakasz" data-reszt data-irany="${irany}" data-szamla="${szamla.id}">
      <div class="reszt-fej"><div><b>Részteljesítések</b> <span class="szurke kicsi" data-reszt-info></span></div>
        ${nyitott && Number(szamla.osszeg) > 0 ? `<button type="button" class="reszt-plusz" data-reszt-plusz title="Részteljesítés felvezetése" aria-label="Részteljesítés felvezetése">${I.plus}</button>` : ''}</div>
      <div class="reszt-lista" data-reszt-lista></div>
      ${nyitott && Number(szamla.osszeg) > 0 ? `<div class="reszt-urlap rejtett" data-reszt-urlap>
        <div class="mezo-sor">
          <div class="mezo"><label>Részteljesítés összege (${esc(szamla.penznem)})</label><input type="text" inputmode="text" data-r="osszeg" autocomplete="off" placeholder="pl. 100 vagy 4 003,20"></div>
          <div class="mezo"><label>Utalás dátuma</label><input type="date" data-r="datum" value="${esc(App.ma)}"></div>
        </div>
        <div class="mezo"><label>Banki azonosító <span class="opc">(nem kötelező)</span></label><input type="text" data-r="bank" autocomplete="off" autocapitalize="characters" placeholder="bankkivonat / tranzakció azonosítója"></div>
        <div class="hiba-doboz rejtett" data-reszt-hiba></div>
        <div class="gomb-sor"><button type="button" class="btn btn-outline btn-sm" data-reszt-megse>${I.x} Mégse</button><button type="button" class="btn btn-zold btn-sm" data-reszt-ment>${I.check} Részteljesítés felvezetése</button></div>
      </div>` : `<div class="segit">${nyitott ? 'Negatív (jóváíró) számlára nem vezethető fel részteljesítés.' : irany === 'BEJOVO' && !resztBejovo() ? 'A bejövő számláknál a részteljesítés ki van kapcsolva (Admin oldalon kapcsolható be) – a korábbiak csak megtekinthetők.' : !jog('ir') ? 'Részteljesítés felvezetéséhez Rögzítő vagy magasabb jogosultság kell.' : 'A számla már rendezett – a részteljesítések csak megtekinthetők.'}</div>`}
    </div>`;
  }
  function resztInit(form, irany, szamla, allapot) {
    const doboz = $('[data-reszt]', form);
    if (!doboz) return;
    const nyitott = irany === 'BEJOVO' ? szamla.statusz === 'FIZETENDO' : szamla.statusz === 'NYITOTT';
    const torolhet = nyitott && jog('torol');
    const pn = szamla.penznem;
    let lista = szamla.reszteljesitesek || [];
    let reszt = Number(szamla.reszt) || 0;
    const rajzol = () => {
      const hatralek = Math.round((Number(szamla.osszeg) - reszt) * 100) / 100;
      $('[data-reszt-info]', doboz).innerHTML = lista.length ? `${lista.length} db · összesen <b>${fmtOsszeg(reszt, pn)}</b> · <b class="narancs-szoveg">hátralék: ${fmtOsszeg(hatralek, pn)}</b>` : (nyitott ? 'még nincs – a + gombbal vezethetsz fel' : 'nincs');
      $('[data-reszt-lista]', doboz).innerHTML = lista.map((r) => `<div class="reszt-tetel"><span class="datum">${fmtDatum(r.datum)}</span><span class="o">${fmtOsszeg(r.osszeg, pn)}</span><span class="bank">${r.banki_azonosito ? esc(r.banki_azonosito) : '<span class="szurke">– nincs banki azonosító –</span>'}</span>${torolhet ? `<button type="button" class="btn btn-outline piros btn-sm btn-ikon" data-reszt-torol="${r.id}" title="Részteljesítés törlése" aria-label="Részteljesítés törlése">${I.trash}</button>` : ''}</div>`).join('');
    };
    rajzol();
    const urlap = $('[data-reszt-urlap]', doboz);
    const plusz = $('[data-reszt-plusz]', doboz);
    if (plusz && urlap) {
      const nyit = (on) => { urlap.classList.toggle('rejtett', !on); plusz.classList.toggle('nyitva', on); if (on) setTimeout(() => $('[data-r=osszeg]', urlap).focus(), 30); };
      plusz.addEventListener('click', () => nyit(urlap.classList.contains('rejtett')));
      $('[data-reszt-megse]', urlap).addEventListener('click', () => nyit(false));
      const hibaBox = $('[data-reszt-hiba]', urlap);
      const ment = async () => {
        const osszeg = $('[data-r=osszeg]', urlap).value.trim(), datum = $('[data-r=datum]', urlap).value, bank = $('[data-r=bank]', urlap).value.trim();
        hibaBox.classList.add('rejtett');
        try {
          if (!osszeg) throw new Error('Add meg a részteljesítés összegét!');
          if (!datum) throw new Error('Add meg az utalás dátumát!');
          const gomb = $('[data-reszt-ment]', urlap); gomb.disabled = true;
          try {
            const r = await api('reszteljesites_hozzaad', { irany, szamla_id: szamla.id, osszeg, datum, banki_azonosito: bank });
            lista = r.reszteljesitesek; reszt = Number(r.szamla.reszt); allapot.frissit = true;
            rajzol(); nyit(false);
            $('[data-r=osszeg]', urlap).value = ''; $('[data-r=bank]', urlap).value = '';
            toast(`${I.check} Részteljesítés felvezetve – a számla hátraléka: <b>${fmtOsszeg(r.szamla.hatralek, pn)}</b> (a státusz ${irany === 'BEJOVO' ? 'FIZETENDŐ' : 'NYITOTT'} marad)`, 'siker', 4500);
          } finally { gomb.disabled = false; }
        } catch (e) { hibaBox.textContent = e.message; hibaBox.classList.remove('rejtett'); }
      };
      $('[data-reszt-ment]', urlap).addEventListener('click', ment);
      // Enter a részteljesítés mezőiben: nem a számla mentése, hanem a részteljesítés felvezetése
      urlap.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); ment(); } });
    }
    doboz.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-reszt-torol]');
      if (!b) return;
      const r = lista.find((x) => x.id === Number(b.dataset.resztTorol));
      if (!r) return;
      const ok = await confirmModal('Részteljesítés törlése', `<p>Biztos törlöd ezt a részteljesítést?</p><p><b>${fmtOsszeg(r.osszeg, pn)}</b> · ${fmtDatum(r.datum)}${r.banki_azonosito ? ` · ${esc(r.banki_azonosito)}` : ''}</p><p class="kicsi szurke">A számla hátraléka ennyivel újra nő.</p>`, { ok: 'Törlés', okOsztaly: 'btn-piros' });
      if (!ok) return;
      try {
        const d = await api('reszteljesites_torol', { id: r.id });
        lista = d.reszteljesitesek; reszt = Number(d.szamla.reszt); allapot.frissit = true;
        rajzol(); toast('Részteljesítés törölve', 'siker');
      } catch (err) { hibaToast(err); }
    });
  }
  function kimenoSzamlaForm(cegId, szamla) {
    const szerk = !!szamla;
    const lezart = szerk && szamla.statusz === 'FIZETVE';
    const uzletkoto = szerk && !jog('ir');                 // Üzletkötő: csak megjegyzés
    const korlatozott = lezart || uzletkoto;
    const { form } = formModal(uzletkoto ? `Megjegyzés – ${esc(szamla.kod)}` : szerk ? `Kimenő számla szerkesztése – ${esc(szamla.kod)}` : 'Új kimenő számla', `
      ${uzletkoto ? `<div class="info-doboz">Üzletkötőként a számla adatait nem módosíthatod, a megjegyzéshez hozzáfűzhetsz.</div><div class="alsor" style="margin-bottom:10px"><span>„${esc(szamla.szamlaszam)}”</span><span>Kelt: ${fmtDatum(szamla.kelt)}</span><span>Határidő: <b>${fmtDatum(szamla.fizetesi_hatarido)}</b></span><span><b>${fmtOsszeg(szamla.osszeg, szamla.penznem)}</b></span></div>` : lezart ? `<div class="info-doboz">A számla már FIZETVE státuszú (bank: ${esc(szamla.banki_azonosito || '')}): csak a megjegyzés módosítható.</div>` : ''}
      ${szerk ? '' : penznemValaszto('EUR') + evValaszto(App.ma.slice(0, 4))}
      ${korlatozott ? '' : inp('szamlaszam', 'Számlaszám (bármilyen formátum)', { ertek: szamla && szamla.szamlaszam, kotelezo: true, attr: 'autocapitalize="characters"' })}
      ${korlatozott ? '' : `<div class="mezo-sor">${inp('kelt', 'Számla kelte', { tipus: 'date', ertek: (szamla && szamla.kelt) || App.ma, kotelezo: true })}${inp('teljesites_datum', 'Teljesítési dátum', { tipus: 'date', ertek: (szamla && szamla.teljesites_datum) || App.ma, kotelezo: true })}</div>`}
      ${korlatozott ? '' : `<div class="mezo-sor">${inp('fizetesi_hatarido', 'Fizetési határidő (mikor kell megkapnom)', { tipus: 'date', ertek: szamla && szamla.fizetesi_hatarido, kotelezo: true })}${inp('osszeg', `Összeg${szerk ? ' (' + szamla.penznem + ')' : ''}`, { ertek: szamla ? String(szamla.osszeg).replace('.', ',') : '', kotelezo: true, inputmode: 'text', placeholder: 'pl. 250 000', segit: szerk && Number(szamla.reszt) > 0 ? `Ez a számla <b>eredeti</b> összege. A részteljesítések (${fmtOsszeg(szamla.reszt, szamla.penznem)}) után a hátralék: <b>${fmtOsszeg(szamla.hatralek, szamla.penznem)}</b>.` : '' })}</div>`}
      ${megjegyzesMezo(szamla)}
      ${szerk ? resztSzakasz('KIMENO', szamla) : '<div class="info-doboz">Az azonosító automatikusan képződik: <b>év-pénznem-6 jegyű sorszám</b> (pl. 2026-EUR-000001).</div>'}`,
      async (a, f, m) => {
        if (!korlatozott && f.dataset.foglalt === '1') throw new Error('Ez a kimenő számlaszám már szerepel a rendszerben – a kimenő számlák számlaszáma egyedi.');
        a.megjegyzes = megjegyzesErtek(a); delete a.megjegyzes_uj; delete a.megjegyzes_regi;
        if (szerk) { await api('kimeno_modosit', Object.assign({ id: szamla.id }, a)); toast(uzletkoto ? 'Megjegyzés mentve' : 'Számla mentve', 'siker'); }
        else { if (!a.penznem) throw new Error('Válassz pénznemet!'); const r = await api('kimeno_letrehoz', Object.assign({ ceg_id: cegId }, a)); toast('Új kimenő számla: ' + esc(r.kod), 'siker'); }
        allapot.frissit = false; m.bezar(); render();
      }, { ok: szerk ? 'Mentés' : 'Számla rögzítése', onBezar: () => { if (allapot.frissit) render(); } });
    const allapot = { frissit: false };
    if (!korlatozott) szamlaszamEllenorzes(form, Object.assign({ kimeno: true }, szerk ? { kiveve_kimeno_id: szamla.id } : {}));
    if (szerk) resztInit(form, 'KIMENO', szamla, allapot);
  }

  // --------------------------------------------------------- változásnapló
  const NAPLO_MUVELET = {
    LETREHOZ: ['Létrehozás', 'zold'], MODOSIT: ['Módosítás', 'kek'], TOROL: ['Törlés', 'piros'], STATUSZ: ['Státuszváltás', 'narancs'],
    RESZT: ['Részteljesítés', 'zold'], ATHELYEZ: ['Áthelyezés', 'sarga'], IMPORT: ['Excel import', 'szurke'],
  };
  /** A kötés / számla teljes története: ki, mikor, mit csinált vele */
  async function naploModal(tipus, id, cim) {
    const m = modal({ cim: `${I.book} Változásnapló <small class="mono">${esc(cim)}</small>`, osztaly: 'naplo-modal', html: '<div class="toltes"></div>' });
    try {
      const d = await api('naplo_rekord', { tipus, id });
      const r = d.rekord;
      const fej = `<div class="naplo-fej"><div><b>${esc(r.kod)}</b>${r.szamlaszam ? ` · „${esc(r.szamlaszam)}”` : ''}${r.megnevezes ? ` · ${esc(r.megnevezes)}` : ''}<br><span class="kicsi szurke">${esc(r.ceg_nev || '')}${r.statusz ? ' · ' + esc(STATUSZ[r.statusz] || r.statusz) : ''}</span></div>
        <div class="kicsi szurke jobbra">Létrehozta <b>${esc(r.letrehozta_nev || '?')}</b><br>${fmtIdo(r.letrehozva)}${r.modositva ? `<br>Utoljára módosította <b>${esc(r.modositotta_nev || '?')}</b><br>${fmtIdo(r.modositva)}` : ''}</div></div>`;
      const sorok = d.bejegyzesek.map((b) => {
        const [nev, szin] = NAPLO_MUVELET[b.muvelet] || [b.muvelet, 'szurke'];
        const masik = tipus === 'KOTES' && b.tipus === 'BEJOVO';
        return `<div class="naplo-tetel ${szin}${masik ? ' szamla' : ''}">
          <div class="mikor"><b>${fmtDatum(b.idopont)}</b><span>${esc(String(b.idopont).slice(11, 16))}</span></div>
          <div class="mit">
            <div class="fej"><span class="muvelet">${esc(nev)}</span><span class="ki">${I.user} ${esc(b.felhasznalonev || 'rendszer')}</span></div>
            <div class="leiras">${esc(b.leiras)}</div>
            ${b.valtozasok && b.valtozasok.length ? `<table class="valtozasok">${b.valtozasok.map((v) => `<tr><td class="mezo">${esc(v.m)}</td><td class="regi">${esc(v.r)}</td><td class="nyil">→</td><td class="uj">${esc(v.u)}</td></tr>`).join('')}</table>` : ''}
          </div></div>`;
      }).join('');
      m.tartalom.innerHTML = `${fej}<div class="naplo-lista">${sorok || `<div class="ures kicsi">${URES}Ehhez a rekordhoz még nincs naplóbejegyzés.</div>`}</div>
        <div class="kicsi szurke" style="margin-top:10px">${d.bejegyzesek.length} bejegyzés, időrendben.${tipus === 'KOTES' ? ' A kötés saját változásai mellett a hozzá tartozó számlák eseményei is látszanak (behúzva).' : ''} Az utalás- és fizetési státuszváltások, részteljesítések, áthelyezések is bekerülnek.</div>`;
    } catch (e) { m.tartalom.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }

  // ------------------------------------------------------ utalás kiválasztás
  async function utalasValasztas(cegId, penznem) {
    const d = await api('utalas_nyitottak', { ceg_id: cegId, penznem });
    const nyitottak = d.utalasok.filter((u) => !u.lezarva);      // lelakatolt utalásba nem gyűjtünk
    const lakatolt = d.utalasok.length - nyitottak.length;
    const kulcs = `aktivUtalas_${cegId}_${penznem}`;
    // 1) a bankkártyás gyűjtő utalása, ha ehhez a céghez és pénznemhez tartozik
    const akt = UtalasKosar.get();
    if (akt && nyitottak.some((u) => u.id === akt.id)) { store.set(kulcs, String(akt.id)); return akt.id; }
    // 2) a munkamenetben ehhez a céghez/pénznemhez korábban választott utalás
    const mentett = Number(store.get(kulcs));
    if (mentett && nyitottak.some((u) => u.id === mentett)) return mentett;
    if (nyitottak.length === 1) { store.set(kulcs, String(nyitottak[0].id)); return nyitottak[0].id; }
    if (nyitottak.length === 0) {
      const ok = await confirmModal(lakatolt ? `Minden nyitott ${penznem} utalás le van lakatolva` : `Nincs nyitott ${penznem} utalás`, `<p>${lakatolt ? `Ennél a cégnél a nyitott <b>${penznem}</b> utalás${lakatolt > 1 ? 'ok' : ''} le ${lakatolt > 1 ? 'vannak' : 'van'} lakatolva (a gyűjtés befejezve).` : `Ehhez a céghez jelenleg nincs nyitott <b>${penznem}</b> utalás.`} Létrehozzak egy új <b>manuális</b> utalást, és ahhoz adjam hozzá a tételt?</p>${lakatolt ? '<p class="kicsi szurke">Ha a lelakatolt utalásba kell, előbb nyisd ki a lakatot az utalás oldalán.</p>' : ''}`, { ok: 'Igen, új utalás' });
      if (!ok) return null;
      const r = await api('utalas_letrehoz', { ceg_id: cegId, penznem });
      store.set(kulcs, String(r.id));
      toast('Új utalás létrehozva: ' + esc(r.uid), 'siker');
      return r.id;
    }
    return new Promise((resolve) => {
      let valasz = null;
      const m = modal({ cim: `Melyik ${penznem} utaláshoz adjam?`, html: `<div class="sorkoz">${nyitottak.map((u) => `<button class="btn btn-outline btn-blokk" type="button" data-id="${u.id}" style="justify-content:space-between"><span class="mono">${esc(u.uid)}</span><span>${fmtOsszeg(u.osszeg, u.penznem)} · ${u.db} db</span></button>`).join('')}</div><div class="segit" style="margin-top:8px">A választott utalás lesz az aktuális gyűjtő (bankkártyás gomb) – a munkamenet végéig ehhez a céghez és pénznemhez ezt használom.${lakatolt ? ` ${lakatolt} lelakatolt utalás nem választható.` : ''}</div>`, onBezar: () => resolve(valasz) });
      $$('[data-id]', m.el).forEach((b) => b.addEventListener('click', () => { valasz = Number(b.dataset.id); store.set(kulcs, String(valasz)); m.bezar(); }));
    });
  }
  async function utalashozAdvaPopup(r, db) {
    await uzenetModal(`<div class="fo">UTALÁSHOZ ADVA!</div>${db ? `<div class="kis">${db} számla hozzáadva</div>` : ''}<div class="kis" style="margin-top:8px">UTALÁS ÖSSZEGE EDDIG:</div><div class="osszeg-nagy${negOszt(r.osszeg)}" data-countup="${r.osszeg}" data-penznem="${esc(r.penznem)}">${fmtOsszeg(r.osszeg, r.penznem)}</div><div class="kis"><a href="#/utalas/${r.utalas_id || ''}" data-act="nav" data-href="#/utalas/${r.utalas_id || ''}">${esc(r.uid)}</a></div>`, { pipa: true });
  }
  function hatarertekModal(cegId) {
    const m = modal({ cim: 'Határértékes utalás', html: `<form data-hf novalidate>
      <div class="lepesek" data-lepesek><span data-n="1" class="aktiv">Határérték</span><i></i><span data-n="2">Előnézet</span><i></i><span data-n="3">Létrehozás</span></div>
      <div class="info-doboz">Először <b>te adod meg</b> a pénznemet és a <b>legfeljebb</b> utalható összeget. Csak ezután gyűjti össze a rendszer a <b>legrégebb óta várakozó</b> FIZETENDŐ számlákat (fizetési határidő szerint) úgy, hogy az utalás végösszege <b>soha ne haladja meg</b> a határértéket – ami nem fér bele, azt kihagyja és a következővel folytatja. Az előnézet <b>még semmit nem hoz létre</b>: az utalás csak a 3. lépésben, a gomb megnyomására jön létre.</div>
      ${penznemValaszto('EUR')}
      ${inp('hatarertek', 'Határérték (legfeljebb ennyit utalok)', { kotelezo: true, inputmode: 'decimal', placeholder: 'pl. 100 000' })}
      <div class="hiba-doboz rejtett" data-hiba></div>
      <div class="lablec"><button class="btn btn-outline" type="button" data-m="megse">Mégse</button><button class="btn btn-sarga" type="submit">Számlák összegyűjtése (előnézet)</button></div>
      <div data-elonezet></div></form>` });
    const form = $('form', m.el);
    const lepes = (k) => $$('[data-lepesek] span', form).forEach((sp, i) => { sp.classList.toggle('aktiv', i === k); sp.classList.toggle('kesz', i < k); });
    $('[data-m=megse]', form).addEventListener('click', () => m.bezar());
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const a = formAdat(form);
      const box = $('[data-elonezet]', form);
      const hibaBox = $('[data-hiba]', form);
      hibaBox.classList.add('rejtett');
      if (!a.penznem) { hibaBox.textContent = 'Válaszd ki a pénznemet!'; hibaBox.classList.remove('rejtett'); return; }
      const limitSzam = Number(String(a.hatarertek || '').replace(/\s/g, '').replace(',', '.'));
      if (!a.hatarertek || !(limitSzam > 0)) { hibaBox.textContent = 'Add meg a határértéket (pozitív szám) – enélkül a rendszer nem gyűjt össze semmit.'; hibaBox.classList.remove('rejtett'); $('[name=hatarertek]', form).focus(); return; }
      box.innerHTML = '<div class="toltes"></div>';
      lepes(1);
      try {
        const d = await api('utalas_hatarertek_elonezet', { ceg_id: cegId, penznem: a.penznem, hatarertek: a.hatarertek });
        const pn = a.penznem;
        box.innerHTML = `<h3 style="margin-top:14px">Kiválasztott számlák <small class="szurke">(${d.valasztott.length} db)</small></h3>
          ${d.valasztott.length ? d.valasztott.map((s) => `<label class="jelolo"><input type="checkbox" data-v="${s.id}" data-o="${s.hatralek}" checked><span style="flex:1"><span class="mono">${esc(s.kod)}</span> „${esc(s.szamlaszam)}”<br><span class="kicsi szurke">határidő ${fmtDatum(s.fizetesi_hatarido)}${Number(s.reszt) > 0 ? ` · eredeti ${fmtOsszeg(s.osszeg, pn)}, részteljesítés után` : ''}</span></span><b class="${negOszt(s.hatralek)}">${fmtOsszeg(s.hatralek, pn)}</b></label>`).join('') : '<div class="ures">Nincs olyan FIZETENDŐ számla, ami beleférne a határértékbe.</div>'}
          ${d.kihagyott.length ? `<h3 style="margin-top:14px">Kihagyott <small class="szurke">(nem fér bele: ${d.kihagyott.length} db)</small></h3>${d.kihagyott.map((s) => `<div class="jelolo szurke"><span style="flex:1"><span class="mono">${esc(s.kod)}</span> „${esc(s.szamlaszam)}”<br><span class="kicsi">határidő ${fmtDatum(s.fizetesi_hatarido)}</span></span><b>${fmtOsszeg(s.hatralek, pn)}</b></div>`).join('')}` : ''}
          <div class="osszesen-sor" style="border-top:3px double #000;display:flex;justify-content:space-between;padding:10px 0;font-weight:900"><span>Utalás összege</span><span data-ossz>${fmtOsszeg(d.osszeg, pn)}</span></div>
          <div class="kicsi szurke">Határérték: ${fmtOsszeg(d.hatarertek, pn)}</div>
          <div class="hiba-doboz rejtett" data-tullep>A kiválasztott számlák összege meghaladja a határértéket!</div>
          <div class="kicsi szurke" style="margin-top:8px">Ez csak előnézet – még semmi nem történt. Az utalás a lenti gombbal jön létre, a bepipált számlákkal.</div>
          <button class="btn btn-zold btn-blokk" type="button" data-letrehoz ${d.valasztott.length ? '' : 'disabled'} style="margin-top:12px">${I.transfer} 3. lépés: Utalás létrehozása</button>`;
        const ujra = () => {
          let o = 0; $$('input[data-v]:checked', box).forEach((c) => { o += Number(c.dataset.o); });
          o = Math.round(o * 100) / 100;
          $('[data-ossz]', box).textContent = fmtOsszeg(o, pn);
          const tul = o > d.hatarertek + 0.000001;
          $('[data-tullep]', box).classList.toggle('rejtett', !tul);
          $('[data-letrehoz]', box).disabled = tul || !$$('input[data-v]:checked', box).length;
        };
        $$('input[data-v]', box).forEach((c) => c.addEventListener('change', ujra));
        $('[data-letrehoz]', box).addEventListener('click', async () => {
          const ids = $$('input[data-v]:checked', box).map((c) => Number(c.dataset.v));
          try {
            lepes(2);
            const r = await api('utalas_hatarertek_letrehoz', { ceg_id: cegId, penznem: pn, hatarertek: a.hatarertek, szamla_ids: ids });
            m.bezar(); UtalasKosar.set({ id: r.id, uid: r.uid, penznem: pn, osszeg: r.osszeg, db: r.db });
            await uzenetModal(`<div class="fo">HATÁRÉRTÉKES UTALÁS LÉTREHOZVA</div><div class="kis" style="margin-top:8px">${r.db} számla · UTALÁS ÖSSZEGE:</div><div class="osszeg-nagy" data-countup="${r.osszeg}" data-penznem="${pn}">${fmtOsszeg(r.osszeg, pn)}</div><div class="kis mono">${esc(r.uid)}</div>`, { pipa: true });
            nav(`#/utalas/${r.id}`);
          } catch (err) { box.insertAdjacentHTML('afterbegin', `<div class="hiba-doboz">${esc(err.message)}</div>`); }
        });
      } catch (err) { lepes(0); box.innerHTML = `<div class="hiba-doboz">${esc(err.message)}</div>`; }
    });
  }
  function utalasUjMenu(cegId) {
    const m = modal({ cim: 'Új utalás', html: `<div class="sorkoz">
      <button class="btn btn-outline narancs btn-blokk" type="button" data-t="manual" style="min-height:58px">${I.plus} Manuális utalás<br><span class="kicsi">üres utalás – a számlákat te válogatod bele</span></button>
      <button class="btn btn-sarga btn-blokk" type="button" data-t="hatar" style="min-height:58px">${I.filter} Határértékes utalás<br><span class="kicsi">legrégebbi FIZETENDŐ számlák egy felső összeghatárig</span></button></div>` });
    $('[data-t=manual]', m.el).addEventListener('click', () => { m.bezar(); manualUtalasForm(cegId); });
    $('[data-t=hatar]', m.el).addEventListener('click', () => { m.bezar(); hatarertekModal(cegId); });
  }
  function manualUtalasForm(cegId) {
    formModal('Új manuális utalás', `${penznemValaszto('EUR')}${megjegyzesMezo(null)}<div class="info-doboz">Létrejön egy üres utalás <b>U-pénznem-év-000000</b> azonosítóval. Ezután a kötések / számlák melletti <b>UTALÁSHOZ</b> gombbal adhatod hozzá a tételeket. Egy utalásba <b>csak azonos pénznemű</b> számla kerülhet!</div>`,
      async (a, f, m) => {
        if (!a.penznem) throw new Error('Válassz pénznemet!');
        const r = await api('utalas_letrehoz', { ceg_id: cegId, penznem: a.penznem, megjegyzes: a.megjegyzes });
        store.set(`aktivUtalas_${cegId}_${a.penznem}`, String(r.id));
        m.bezar(); toast('Új utalás: ' + esc(r.uid), 'siker'); nav(`#/utalas/${r.id}`);
      }, { ok: 'Utalás létrehozása' });
  }
  async function utalasSzamlaValaszto(utalasId, cegId, penznem) {
    const m = modal({ cim: `FIZETENDŐ ${penznem} számlák`, html: '<div class="toltes"></div>' });
    try {
      const k = await api('kotesek', { ceg_id: cegId });
      const kot = k.kotesek.filter((x) => x.penznem === penznem && x.fizetendo_db > 0);
      if (!kot.length) { m.tartalom.innerHTML = '<div class="ures">Nincs FIZETENDŐ státuszú ' + penznem + ' számla ennél a cégnél.</div>'; return; }
      const reszek = await Promise.all(kot.map((x) => api('bejovo_szamlak', { kotes_id: x.id })));
      m.tartalom.innerHTML = reszek.map((d) => `<div class="kotes-sor" style="display:flex;justify-content:space-between;font-weight:800;border-top:2px solid #000;padding:8px 0 4px"><span class="mono">${esc(d.kotes.kod)}</span><button class="btn btn-sm" type="button" data-act="utalashoz-kotes-modal" data-kotes="${d.kotes.id}" data-utalas="${utalasId}">Mind (${d.kotes.fizetendo_db})</button></div>
        ${d.szamlak.filter((s) => s.statusz === 'FIZETENDO').map((s) => `<div class="szamla-sor" style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:6px 0;border-top:1px dashed #e2e2e2"><span><span class="mono">${esc(s.k)}</span> „${esc(s.szamlaszam)}”<br><span class="kicsi szurke">határidő ${fmtDatum(s.fizetesi_hatarido)}${Number(s.reszt) > 0 ? ` · részteljesítés után (eredeti ${fmtOsszeg(s.osszeg, penznem)})` : ''}</span></span><b class="${negOszt(foErtek(s))}">${fmtOsszeg(foErtek(s), penznem)}</b><button class="btn btn-outline btn-sm btn-ikon" type="button" data-act="utalashoz-szamla-modal" data-szamla="${s.id}" data-utalas="${utalasId}" aria-label="Hozzáadás">${I.plus}</button></div>`).join('')}`).join('');
      m.el.addEventListener('click', async (e) => {
        const b = e.target.closest('[data-act=utalashoz-szamla-modal],[data-act=utalashoz-kotes-modal]');
        if (!b) return;
        b.disabled = true;
        try {
          const r = b.dataset.act === 'utalashoz-szamla-modal'
            ? await api('utalas_szamla_hozzaad', { utalas_id: utalasId, szamla_id: Number(b.dataset.szamla) })
            : await api('utalas_kotes_hozzaad', { utalas_id: utalasId, kotes_id: Number(b.dataset.kotes) });
          m.bezar(); r.utalas_id = utalasId; UtalasKosar.set({ id: utalasId, uid: r.uid, penznem: r.penznem, osszeg: r.osszeg, db: r.utalas_db });
          await utalashozAdvaPopup(r, r.db); render();
        } catch (err) { hibaToast(err); b.disabled = false; }
      });
    } catch (e) { m.tartalom.innerHTML = `<div class="hiba-doboz">${esc(e.message)}</div>`; }
  }
  function utalvaModal(utalasId, uid, osszeg, penznem) {
    formModal(`Utalás teljesítve – ${esc(uid)}`, `
      <div class="info-doboz">Az utalás összege: <b>${fmtOsszeg(osszeg, penznem)}</b>. Teljesítés után a benne lévő számlák <b>FIZETVE</b> (negatív összeg esetén <b>BESZÁMÍTVA</b>) státuszba kerülnek, a sorukban látszik majd az utalás azonosítója (${esc(uid)}).</div>
      ${inp('datum', 'Utalás dátuma', { tipus: 'date', ertek: App.ma, kotelezo: true })}
      ${inp('banki_hivatkozas', 'Banki hivatkozás / tranzakció-azonosító', { opc: true })}`,
      async (a, f, m) => {
        await api('utalas_utalva', { utalas_id: utalasId, datum: a.datum, banki_hivatkozas: a.banki_hivatkozas });
        UtalasKosar.torol(utalasId); m.bezar(); toast(`${esc(uid)} teljesítve – a számlák FIZETVE státuszba kerültek`, 'siker'); render();
      }, { ok: 'Igen, elutalva', okOsztaly: 'btn-zold' });
  }

  // ---------------------------------------------------- bank hozzárendelés
  function bankHozzarendelModal(ids) {
    ids = (ids || BankKosar.ids()).map(Number).filter((x) => x > 0);
    if (!ids.length) return;
    const { m, form } = formModal(`${ids.length} számla bankkivonati azonosítóhoz adása`, `
      ${inp('datum', 'Dátum (jóváírás napja)', { tipus: 'date', ertek: App.ma, kotelezo: true })}
      ${inp('azonosito', 'Banki azonosító (bankkivonat / tranzakció azonosítója)', { kotelezo: true, placeholder: 'bármilyen formátum lehet', attr: 'autocapitalize="characters"' })}
      <div data-bank-ell></div>`,
      async (a, f, mm) => {
        if (!a.azonosito) throw new Error('Add meg a banki azonosítót!');
        if (!a.datum) throw new Error('Add meg a dátumot!');
        await bankHozzarendelFut(ids, a.datum, a.azonosito, false, mm);
      }, { ok: 'Hozzárendelés', okOsztaly: 'btn-zold' });
    // élő ellenőrzés
    const mezo = $('[name=azonosito]', form);
    const ell = $('[data-bank-ell]', form);
    let ido = null;
    mezo.addEventListener('input', () => {
      clearTimeout(ido);
      ido = setTimeout(async () => {
        const v = mezo.value.trim();
        if (!v) { ell.innerHTML = ''; return; }
        try {
          const r = await api('bank_azonosito_ellenoriz', { azonosito: v });
          if (mezo.value.trim() !== v) return;
          ell.innerHTML = r.hasznalt ? duplaFigyelmeztetes(r) : '<div class="ellenorzes szabad">✓ Ezt a banki azonosítót még nem használtad.</div>';
        } catch (e) { ell.innerHTML = ''; }
      }, 350);
    });
  }
  function duplaFigyelmeztetes(r) {
    return `<div class="figyelem-doboz"><b>Itt valami nem stimmel!</b> Ezt a banki azonosítót (<b>${esc(r.kivonat.azonosito)}</b>, ${fmtDatum(r.kivonat.datum)}) már használtad ezekhez a számlákhoz:<ul style="margin:6px 0 6px 18px;padding:0">${r.szamlak.map((s) => `<li><span class="mono">${esc(s.kod)}</span> „${esc(s.szamlaszam)}” – ${esc(s.ceg_nev)} – ${fmtOsszeg(s.hatralek != null ? s.hatralek : s.osszeg, s.penznem)}</li>`).join('')}</ul>Biztos, hogy a mostani számlákat is ez az azonosító fedezi? (A „Hozzárendelés” gomb megnyomása után még egyszer rákérdezek.)</div>`;
  }
  async function bankHozzarendelFut(ids, datum, azonosito, megerositve, m) {
    try {
      const r = await api('bank_hozzarendel', { szamla_ids: ids, datum, azonosito, megerositve });
      m.bezar();
      BankKosar.urit();
      await uzenetModal(`<div class="fo">FIZETVE</div><div class="kis" style="margin-top:8px">${r.db} számla a(z) <b>${esc(azonosito)}</b> banki azonosítóhoz rendelve${r.uj ? '' : ' (már korábban használt azonosító – megerősítve)'}.</div>`, { pipa: true });
      render();
    } catch (e) {
      if (e.kod === 'DUPLA') {
        const ok = await confirmModal('Itt valami nem stimmel!', `${duplaFigyelmeztetes(e.extra)}`, { ok: 'Igen, ez fedezi', megse: 'Nem', okOsztaly: 'btn-piros' });
        if (!ok) return;
        const biztos = await visszaszamlaloModal('Biztos?', 5);
        if (!biztos) return;
        return bankHozzarendelFut(ids, datum, azonosito, true, m);
      }
      throw e;
    }
  }

  // ------------------------------------------------------------ admin űrlap
  function adminFelhasznaloForm(u) {
    const szerk = !!u;
    formModal(szerk ? `Felhasználó – ${esc(u.felh)}` : 'Új felhasználó', `
      ${szerk ? '' : inp('felhasznalonev', 'Felhasználónév', { kotelezo: true, placeholder: 'kisbetű, szám, pont, kötőjel', attr: 'autocapitalize="none"' })}
      ${inp('nev', 'Teljes név', { ertek: u && u.nev, opc: true })}
      ${inp(szerk ? 'uj_jelszo' : 'jelszo', szerk ? 'Új jelszó (csak ha módosítod)' : 'Jelszó (min. 8 karakter)', { tipus: 'password', kotelezo: !szerk, minlength: 8 })}
      <div class="mezo"><label>Szerepkör</label><select name="szerep">${['rogzito', 'uzletkoto', 'irodavezeto', 'admin'].map((k) => `<option value="${k}" ${(u ? u.szerep : 'rogzito') === k ? 'selected' : ''}>${SZEREP_NEV[k]}</option>`).join('')}</select><div class="segit" data-szerep-leiras>${esc(SZEREP_LEIRAS[u ? u.szerep : 'rogzito'] || '')}</div></div>
      ${szerk ? `<label class="jelolo"><input type="checkbox" name="aktiv" value="1" ${u.aktiv ? 'checked' : ''}> Aktív (letiltva = nem tud belépni)</label>` : ''}`,
      async (a, f, m) => {
        if (szerk) await api('admin_felhasznalo_modosit', { id: u.id, nev: a.nev, szerep: a.szerep, aktiv: !!a.aktiv, uj_jelszo: a.uj_jelszo || '' });
        else await api('admin_felhasznalo_letrehoz', { felhasznalonev: a.felhasznalonev, nev: a.nev, jelszo: a.jelszo, szerep: a.szerep });
        m.bezar(); toast('Felhasználó mentve', 'siker'); render();
      });
    const sel = $('.modal select[name=szerep]');
    if (sel) sel.addEventListener('change', () => { const d = $('.modal [data-szerep-leiras]'); if (d) d.textContent = SZEREP_LEIRAS[sel.value] || ''; });
  }

  /** Fül-indikátor csúsztatása, majd a tényleges váltás */
  function fulCsusztat(el, utana) {
    const f = el.closest('.fulek');
    if (f) { const i = Array.from(f.children).indexOf(el); f.style.setProperty('--i', String(i)); $$('button', f).forEach((b) => b.classList.toggle('aktiv', b === el)); }
    setTimeout(utana, 200);
  }
  // =============================================================== műveletek
  const ACT = {
    menu: () => nyitMenu(),
    'kereso-torol': () => {
      App.kereso = { q: '', adat: null, sorszam: App.kereso.sorszam + 1 };
      const inp = $('[data-kereso-input]'); if (inp) { inp.value = ''; inp.focus(); }
      const box = $('[data-kereso-talalatok]'); if (box) { box.classList.add('rejtett'); box.innerHTML = ''; }
      const segit = $('[data-kereso-segit]'); if (segit) segit.innerHTML = KERESO_SEGIT;
      const t = $('[data-act=kereso-torol]'); if (t) t.classList.add('rejtett');
    },
    'naplo-mutat': (el) => naploModal(el.dataset.tipus, Number(el.dataset.id), el.dataset.cim || ''),
    'utalas-kosar': () => utalasKosarModal(),
    'utalas-kosar-valaszt': async (el) => { await utalasKosarValaszt(Number(el.dataset.utalas)); render(); },
    'utalas-lakat': async (el) => {
      const lezar = el.dataset.lezar === '1';
      const r = await api('utalas_lezar', { utalas_id: Number(el.dataset.utalas), lezar });
      const akt = UtalasKosar.get();
      const elengedve = lezar && akt && akt.id === r.utalas.id;
      if (elengedve) UtalasKosar.torol(r.utalas.id);       // a gyűjtés kész: a bankkártyás gomb elengedi az utalást
      toast(lezar ? `${I.lock} <b class="mono">${esc(r.utalas.uid)}</b> lelakatolva – a gyűjtés befejezve, a státusz NYITOTT marad.<br><small>Számla nem adható hozzá és nem vehető ki, amíg ki nem nyitod a lakatot.${elengedve ? ' A bankkártyás gomb elengedte az utalást.' : ''}</small>` : `${I.unlock} <b class="mono">${esc(r.utalas.uid)}</b> lakat kinyitva – újra lehet számlát hozzáadni / kivenni.<br><small>Ha gyűjteni akarsz bele, nyomd meg az „Ehhez gyűjtök” gombot.</small>`, 'siker', 5000);
      render();
    },
    'kereso-ugras': (el) => {
      // csak kattintásra ugrunk – a naplózás a háttérben megy, nem várjuk meg
      api('kereses_ugras', { q: App.kereso.q, tipus: el.dataset.tipus, id: Number(el.dataset.id), cim: el.dataset.cim || '' }).catch(() => {});
      nav(el.getAttribute('href'));
    },
    'nyomtat-valt': (el) => {
      const t = el.dataset.t, id = Number(el.dataset.id), cimke = el.dataset.cimke || '';
      const be = Kosar.valt(t, id, cimke);
      frissitNyomtatGombok();
      const n = Kosar.db();
      toast(`${I.print} ${be ? 'Nyomtatáshoz adva' : 'Kivéve a nyomtatásból'}: <b>${esc(cimke)}</b><br><small>${n ? `${n} sor a kosárban – a jobb alsó nyomtató gombbal készíthetsz PDF-et` : 'a kosár üres'}</small>`, be ? 'siker' : '', 2600);
    },
    'nyomtat-pdf': () => {
      const l = Kosar.lista();
      if (!l.length) { toast(`${I.print} A nyomtatási kosár üres.<br><small>A listákban a sorok végén lévő nyomtató gombbal gyűjts össze sorokat, aztán nyomd meg ezt a gombot.</small>`, '', 5000); return; }
      pdfKuldes(l);
      if (kosarLap) kosarLap.bezar();
      toast(`${I.print} PDF készül ${l.length} sorból – új lapon nyílik meg.<br><small><a href="#" data-act="nyomtat-urit">Kosár ürítése</a> · <a href="#" data-act="nyomtat-kosar">Kosár megtekintése</a></small>`, 'siker', 8000);
    },
    'nyomtat-urit': () => { Kosar.urit(); frissitNyomtatGombok(); kosarLapFrissit(); toast('A nyomtatási kosár kiürítve', '', 2200); },
    'nyomtat-kosar': () => { $('#toast-root').innerHTML = ''; nyomtatKosarLap(); },
    'kosar-ki': (el) => { Kosar.torol(el.dataset.t, Number(el.dataset.id)); frissitNyomtatGombok(); kosarLapFrissit(); },
    nav: (el) => nav(el.dataset.href),
    kilepes: async () => { try { await api('kilepes'); } catch (e) { /* mindegy */ } App.user = null; App.cegek = null; App.kotesIdoszak = null; App.allapotSzuro = null; hataridoAdat = null; location.hash = '#/'; render(); },
    'ceg-uj': () => cegForm(),
    'ceg-szerk': async (el) => { const c = (await cegekBetolt()).find((x) => x.id === Number(el.dataset.id)); if (c) cegForm(c); },
    'kotes-uj': (el) => kotesForm(Number(el.dataset.ceg)),
    'kotes-szerk': async (el) => { const d = await api('kotes', { id: Number(el.dataset.kotes) }); kotesForm(d.kotes.ceg_id, d.kotes); },
    'kotes-torol': async (el) => { if (await confirmModal('Kötés törlése', `<p>Törlöd a(z) <b class="mono">${esc(el.dataset.kod)}</b> kötést? (Csak üres kötés törölhető.)</p>`, { ok: 'Törlés', okOsztaly: 'btn-piros' })) { await api('kotes_torol', { id: Number(el.dataset.kotes) }); toast('Kötés törölve'); nav(`#/bejovo/ceg/${el.dataset.ceg}`); } },
    'bejovo-uj': async (el) => { const d = await api('kotes', { id: Number(el.dataset.kotes) }); bejovoSzamlaForm(d.kotes); },
    'bejovo-szerk': async (el) => { const d = await api('bejovo_szamla', { id: Number(el.dataset.szamla) }); bejovoSzamlaForm({ id: d.szamla.kotes_id, kod: d.szamla.kotes_kod, penznem: d.szamla.penznem, ceg_nev: d.szamla.ceg_nev, megnevezes: d.szamla.kotes_megnevezes }, d.szamla); },
    'bejovo-torol': async (el) => { if (await confirmModal('Számla törlése', `<p>Biztosan törlöd a(z) <b class="mono">${esc(el.dataset.kod)}</b> számlát?</p>`, { ok: 'Törlés', okOsztaly: 'btn-piros' })) { await api('bejovo_szamla_torol', { id: Number(el.dataset.szamla) }); toast('Számla törölve'); render(); } },
    'utalashoz-szamla': async (el) => {
      const uid = await utalasValasztas(Number(el.dataset.ceg), el.dataset.penznem);
      if (!uid) return;
      const r = await api('utalas_szamla_hozzaad', { utalas_id: uid, szamla_id: Number(el.dataset.szamla) });
      r.utalas_id = uid; UtalasKosar.set({ id: uid, uid: r.uid, penznem: r.penznem, osszeg: r.osszeg, db: r.utalas_db }); await utalashozAdvaPopup(r); render();
    },
    'utalashoz-kotes': async (el) => {
      const uid = await utalasValasztas(Number(el.dataset.ceg), el.dataset.penznem);
      if (!uid) return;
      const r = await api('utalas_kotes_hozzaad', { utalas_id: uid, kotes_id: Number(el.dataset.kotes) });
      r.utalas_id = uid; UtalasKosar.set({ id: uid, uid: r.uid, penznem: r.penznem, osszeg: r.osszeg, db: r.utalas_db }); await utalashozAdvaPopup(r, r.db); render();
    },
    'utalasbol-ki': async (el) => { const r = await api('utalas_szamla_eltavolit', { szamla_id: Number(el.dataset.szamla) }); toast(`${esc(r.kod)} kivéve az utalásból (${esc(r.uid)}) – utalás összege: ${fmtOsszeg(r.osszeg)}`); render(); },
    'uid-mutat': (el) => toast(`Utaláshoz adva: <a href="#/utalas/${esc(el.dataset.utalas)}">${esc(el.dataset.uid)}</a>`, '', 5000),
    'utalas-uj-menu': (el) => utalasUjMenu(Number(el.dataset.ceg)),
    'utalas-manual': (el) => manualUtalasForm(Number(el.dataset.ceg)),
    'utalas-hatarertek': (el) => hatarertekModal(Number(el.dataset.ceg)),
    'utalas-ful': (el) => { App.utalasFul = el.dataset.ful; fulCsusztat(el, render); },
    'ful-nav': (el) => fulCsusztat(el, () => nav(el.dataset.href)),
    'utalas-szamla-valaszt': (el) => utalasSzamlaValaszto(Number(el.dataset.utalas), Number(el.dataset.ceg), el.dataset.penznem),
    'utalas-utalva': (el) => utalvaModal(Number(el.dataset.utalas), el.dataset.uid, Number(el.dataset.osszeg), el.dataset.penznem),
    'utalas-torol': async (el) => { if (await confirmModal('Utalás törlése', `<p>Törlöd a(z) <b class="mono">${esc(el.dataset.uid)}</b> utalást? A benne lévő számlák visszakerülnek FIZETENDŐ státuszba.</p>`, { ok: 'Törlés', okOsztaly: 'btn-piros' })) { await api('utalas_torol', { utalas_id: Number(el.dataset.utalas) }); UtalasKosar.torol(Number(el.dataset.utalas)); toast('Utalás törölve'); history.back(); } },
    'utalas-visszanyit': async (el) => { if (await confirmModal('Utalás visszanyitása', `<p>Visszanyitod a(z) <b class="mono">${esc(el.dataset.uid)}</b> utalást? A számlái visszakerülnek UTALÁSHOZ ADVA státuszba.</p>`, { ok: 'Visszanyitás' })) { await api('utalas_visszanyit', { utalas_id: Number(el.dataset.utalas) }); toast('Utalás visszanyitva'); render(); } },
    'utalas-szerk': async (el) => { const d = await api('utalas', { id: Number(el.dataset.utalas) }); formModal(jog('ir') ? 'Utalás adatai' : `Megjegyzés – ${esc(d.utalas.uid)}`, `${jog('ir') ? inp('banki_hivatkozas', 'Banki hivatkozás', { ertek: d.utalas.banki_hivatkozas, opc: true }) : ''}${megjegyzesMezo(d.utalas)}`, async (a, f, m) => { await api('utalas_modosit', { utalas_id: d.utalas.id, megjegyzes: megjegyzesErtek(a), banki_hivatkozas: a.banki_hivatkozas }); m.bezar(); toast('Mentve', 'siker'); render(); }); },
    'mu-szuro': (el) => { App.mindenUtalasSzuro[el.dataset.k] = el.dataset.v; if (el.closest('.fulek')) fulCsusztat(el, render); else render(); },
    'kimeno-uj': (el) => kimenoSzamlaForm(Number(el.dataset.ceg)),
    'kimeno-szerk': async (el) => { const d = await api('kimeno_szamla', { id: Number(el.dataset.szamla) }); kimenoSzamlaForm(d.szamla.ceg_id, d.szamla); },
    'kimeno-torol': async (el) => { if (await confirmModal('Kimenő számla törlése', `<p>Biztosan törlöd a(z) <b class="mono">${esc(el.dataset.kod)}</b> számlát?</p>`, { ok: 'Törlés', okOsztaly: 'btn-piros' })) { await api('kimeno_torol', { id: Number(el.dataset.szamla) }); BankKosar.torol(Number(el.dataset.szamla)); toast('Számla törölve'); render(); } },
    'kimeno-ful': (el) => { App.kimenoFul = el.dataset.ful; fulCsusztat(el, render); },
    'kotes-ful': (el) => {
      App.kotesFul = el.dataset.ful;
      // aktív időszak-szűrésnél a mezőkbe közben beírt dátumokkal szűr tovább – ami a mezőkben látszik, az érvényes
      const sav = $('.idoszak-sav.aktiv');
      if (sav && App.kotesIdoszak) { try { Object.assign(App.kotesIdoszak, idoszakErtek(sav)); } catch (err) { hibaToast(err); } }
      fulCsusztat(el, kotesekFrissit);
    },
    'bejovo-ful': (el) => { App.bejovoFul = el.dataset.ful; fulCsusztat(el, render); },
    'idoszak-szures': async (el) => {
      const sav = el.closest('.idoszak-sav');
      const e = idoszakErtek(sav);
      const kulcs = sav.dataset.idoszak;          // 'kotes' | 'kimeno'
      if (e.mezo === 'allapot') {
        // állapot vizsgálat (1.19): a szerver számol, a fül MIND-re vált – a vizsgált napon bármilyen számla lehet
        const p = route().path;
        App.allapotSzuro = { kulcs, id: Number(kulcs === 'kotes' ? p[4] : p[2]), tol: e.tol, ig: e.ig, nap: e.nap };
        if (kulcs === 'kotes') App.bejovoFul = 'MIND'; else App.kimenoFul = 'MIND';
        $('[data-idoszak-eredmeny]', sav).innerHTML = '<div class="toltes" style="padding:14px"></div>';
        await render();
        return;
      }
      if (App.allapotSzuro) {
        // állapot vizsgálatból sima időszak-szűrésre: a normál lista újrarajzolása, majd rajta a szűrés
        App.allapotSzuro = null;
        App.idoszakUrlap = Object.assign({ kulcs }, e);
        await render();
        const uj = $(`.idoszak-sav[data-idoszak="${kulcs}"]`);
        if (uj && App.idoszakLista) idoszakSzuresLista(uj, App.idoszakLista.szamlak, App.idoszakLista.tipus);
        return;
      }
      const l = App.idoszakLista || { tipus: el.dataset.lista, szamlak: [] };
      idoszakSzuresLista(sav, l.szamlak, l.tipus);
    },
    'allapot-torol': async (el) => {
      const sav = el.closest('.idoszak-sav');
      App.allapotSzuro = null;
      App.idoszakUrlap = sav ? Object.assign({ kulcs: sav.dataset.idoszak }, idoszakUrlap(sav)) : null;   // a dátumok a mezőkben maradnak
      await render();
    },
    'idoszak-ceg': async (el) => {
      const sav = el.closest('.idoszak-sav');
      const e = idoszakErtek(sav);
      App.kotesIdoszak = Object.assign({ cegId: Number(el.dataset.ceg) }, e);
      if (e.mezo === 'allapot') App.kotesFul = 'MIND';   // állapot vizsgálat: a vizsgált napon bármilyen számla lehet
      $('[data-idoszak-eredmeny]', sav).innerHTML = '<div class="toltes" style="padding:14px"></div>';
      await kotesekFrissit();
    },
    'idoszak-ceg-torol': async () => { App.kotesIdoszak = null; await kotesekFrissit(); },
    'ov-kosarba-pdf': (el) => {
      const tetel = osszevetesTetel({ ceg: Number(el.dataset.ceg), pn: el.dataset.pn, tol: el.dataset.tol, ig: el.dataset.ig }, el.dataset.cegNev || '');
      const r = Kosar.hozzaad([tetel]);
      pdfKuldes([tetel]);   // a PDF csak ezt az összevetést tartalmazza; a kosárban marad, más tételekkel együtt is nyomtatható
      kosarLapFrissit();
      toast(`${I.print} Az összevetés ${r.mar ? 'már benne volt a' : 'bekerült a'} nyomtatási kosárba – a PDF új lapon nyílik.<br><small>${esc(tetel.cimke)}</small><br><small>${Kosar.db()} sor a kosárban – a jobb alsó nyomtató gombbal más tételekkel együtt is nyomtathatod.</small>`, 'siker', 6000);
    },
    'idoszak-kosarba': (el) => { const sav = el.closest('.idoszak-sav'); idoszakKosarba(JSON.parse(sav.dataset.talalat || '[]'), sav.dataset.cimke || ''); },
    'idoszak-torol': (el) => { const sav = el.closest('.idoszak-sav'); $$('.szamla-lista [data-szamla-id]').forEach((k) => k.classList.remove('rejtett')); $('[data-idoszak-eredmeny]', sav).innerHTML = ''; delete sav.dataset.talalat; },
    'archiv-athelyez': async () => { const r = route(); const cegId = Number(r.path[2]); const d = await api('kotes', { id: Number(r.path[4]) }); archivAthelyezForm(cegId, d.kotes.penznem, d.kotes.id); },
    'import-fajl-gomb': () => { const i = $('[data-act=import-fajl]'); if (i) i.click(); },
    'import-uj': () => { App.importAllapot = null; nav('#/import'); },
    'import-szuro': (el) => { App.importAllapot.szuro = el.dataset.v; importSorokRajzol(); },
    'import-jelol-mind': (el) => { importJelolMind(el.dataset.mod); },
    'import-inditas': () => importInditas(),
    'import-cegszuro': (el) => { App.importAllapot.cegSzuro = el.dataset.v; $$('.import-cegszurok .csip').forEach((c) => c.classList.toggle('aktiv', c === el)); importCsoportokRajzol(); },
    'import-csoport-levalaszt': (el) => importCsoportLevalaszt(el),
    'kimeno-jelol-gomb': (el) => { const s = App.kimenoSorok[Number(el.dataset.szamla)]; if (!s) return; const be = BankKosar.valt(s); toast(`${I.bank} ${be ? 'A banki gyűjtőbe téve' : 'Kivéve a banki gyűjtőből'}: <b>${esc(s.kod)}</b><br><small>${BankKosar.db() ? `${BankKosar.db()} számla a gyűjtőben – a jobb alsó bank-gombbal adhatod bankazonosítóhoz` : 'a gyűjtő üres'}</small>`, be ? 'siker' : '', 2600); },
    'bank-kosar': () => bankKosarModal(),
    'kimeno-visszavon': async (el) => { if (await confirmModal('Fizetés visszavonása', `<p>A(z) <b class="mono">${esc(el.dataset.kod)}</b> számla visszakerül NYITOTT státuszba, a banki azonosító-hozzárendelés törlődik.</p>`, { ok: 'Visszavonás', okOsztaly: 'btn-piros' })) { await api('kimeno_fizetes_visszavon', { id: Number(el.dataset.szamla) }); toast('Fizetés visszavonva'); render(); } },
    'bank-hozzarendel': () => bankHozzarendelModal(BankKosar.ids()),
    'bank-keres': () => { const q = $('[data-bank-q]').value.trim(); nav('#/osszevetes' + qs({ tab: 'bank', q })); },
    'bank-reszlet': async (el) => {
      const box = $('[data-bank-szamlak]', el);
      if (!box.classList.contains('rejtett')) { box.classList.add('rejtett'); return; }
      box.classList.remove('rejtett'); box.innerHTML = '<div class="toltes"></div>';
      const d = await api('bank_kivonat', { id: Number(el.dataset.id) });
      box.innerHTML = `<div class="reszletes" style="margin-top:6px">${d.szamlak.map((s) => `<div class="szamla-sor"><div class="bal"><a class="k" href="#/kimeno/ceg/${s.ceg_id}?szamla=${s.id}">${esc(s.kod)}</a><span class="szsz">„${esc(s.szamlaszam)}”</span><span class="kicsi">${esc(s.ceg_nev)}</span></div><span class="o">${fmtOsszeg(s.osszeg, s.penznem)}</span>${nyomtatGomb('kimeno', s.id, `${s.kod} · „${s.szamlaszam}”`)}</div>`).join('')}</div>`;
    },
    'hat-irany': (el) => { App.hataridoSzuro.irany = el.dataset.v; rajzolHataridok(); hataridoSzuroToast(); },
    'hat-utalasalatt': () => { App.hataridoSzuro.utalasAlatt = !App.hataridoSzuro.utalasAlatt; rajzolHataridok(); toast(App.hataridoSzuro.utalasAlatt ? 'Az utaláshoz adott számlák is látszanak' : 'Csak a FIZETENDŐ / NYITOTT számlák látszanak'); },
    'hat-penznem': (el) => { App.hataridoSzuro.penznem = el.dataset.v || null; rajzolHataridok(); hataridoSzuroToast(); },
    'hat-ceg': (el) => { App.hataridoSzuro.ceg = el.dataset.id ? Number(el.dataset.id) : null; App.hataridoSzuro.cegNev = el.dataset.nev || ''; rajzolHataridok(); hataridoSzuroToast(); },
    'hat-reset': () => { App.hataridoSzuro = { ceg: null, cegNev: '', penznem: null, irany: 'MIND', utalasAlatt: App.hataridoSzuro.utalasAlatt }; rajzolHataridok(); hataridoSzuroToast(); },
    'ov-lekerdez': () => {
      const r = route();
      const ceg = $('[data-act=ov-ceg]').value;
      const pn = ($('input[name=ov-penznem]:checked') || {}).value || 'EUR';
      const tol = $('[data-ov=tol]').value, ig = $('[data-ov=ig]').value;
      if (!ceg) return toast('Előbb válassz céget!', 'hiba');
      if (!tol || !ig) return toast('A tól–ig dátum megadása kötelező!', 'hiba');
      if (tol > ig) return toast('A kezdő dátum nem lehet későbbi a záró dátumnál!', 'hiba');
      const uj = '#/osszevetes' + qs({ ceg, penznem: pn, tol, ig });
      if (('#' + r.path.join('/')) && location.hash === uj) rajzolReszletek($('[data-reszletek]'), { ceg, penznem: pn, tol, ig }); else nav(uj);
    },
    'jelszo-szem': (el) => {
      const be = el.parentElement.querySelector('[data-jelszo]'); if (!be) return;
      const mutat = !be.classList.contains('jelszo-latszik');
      if (!be.classList.contains('jelszo-rejtett')) be.type = mutat ? 'text' : 'password';   // tartalék mód (type=password)
      be.classList.toggle('jelszo-latszik', mutat);
      el.innerHTML = mutat ? I.eyeOff : I.eye; el.setAttribute('aria-label', mutat ? 'Jelszó elrejtése' : 'Jelszó megjelenítése');
      be.focus();
    },
    'belepes-vissza': () => viewLogin(),
    'belepes-jelszo': (el) => { const m = $('input[name=felhasznalonev]'); viewLogin('jelszo', el.dataset.nev || (m ? m.value : '')); },
    'belepes-qr-ujra': (el) => qrBelepesInditas(el.dataset.nev),
    'belepes-helyi': async (el) => { el.disabled = true; try { await helyiPasskeyBelepes(el.dataset.nev); } catch (e) { toast(esc(waHiba(e)), 'hiba', 7000); el.disabled = false; } },
    'qr-jovahagy': async (el) => {
      const hibaBox = $('[data-hiba]'); hibaBox.classList.add('rejtett'); el.disabled = true;
      try {
        const asr = await passkeyAlair(QR.opciok);
        const r = await api('qr_jovahagy', { token: el.dataset.token, assertion: asr });
        qrLeallit();
        $('[data-belepes-belso]').innerHTML = `${PIPA}<div class="jov-cim" style="color:var(--zold)">Jóváhagyva!</div><p class="kozepre">A(z) <b>${esc(r.keres_eszkoz)}</b> gépen beléptél mint <b>${esc(r.felhasznalonev)}</b>. Ezt az oldalt bezárhatod.</p><a class="btn btn-outline btn-blokk" href="#/" data-act="nav" data-href="#/">Az alkalmazás megnyitása itt</a>`;
      } catch (e) { hibaBox.textContent = waHiba(e); hibaBox.classList.remove('rejtett'); el.disabled = false; }
    },
    'qr-elutasit': async (el) => { try { await api('qr_elutasit', { token: el.dataset.token }); } catch (e) { /* mindegy */ } qrLeallit(); $('[data-belepes-belso]').innerHTML = `<div class="jov-cim">Elutasítva</div><p class="kozepre">A belépési kérést elutasítottad. Ha nem te kérted, szólj az adminnak.</p>`; },
    'regisztral': async (el) => {
      const hibaBox = $('[data-hiba]'); hibaBox.classList.add('rejtett'); el.disabled = true;
      try {
        const att = await passkeyLetrehoz(QR.opciok);
        const r = await api('regisztracio_vegrehajt', { token: el.dataset.token, attestation: att, eszkoz_nev: ($('[name=eszkoz_nev]') || {}).value || '' });
        qrLeallit(); belepve(r);
        $('[data-belepes-belso]').innerHTML = `${PIPA}<div class="jov-cim" style="color:var(--zold)">Kész!</div><p class="kozepre">Ez az eszköz mostantól jóvá tudja hagyni a belépéseidet Face ID-val / ujjlenyomattal, és be is léptél.</p><a class="btn btn-blokk" href="#/" data-act="nav" data-href="#/">Tovább az alkalmazásba</a>`;
      } catch (e) { hibaBox.textContent = waHiba(e); hibaBox.classList.remove('rejtett'); el.disabled = false; }
    },
    'passkey-uj': async (el) => {
      el.disabled = true;
      try {
        const o = await api('passkey_regisztracio_opciok');
        const att = await passkeyLetrehoz(o.opciok);
        const nev = await new Promise((res) => { const { m } = formModal('Az eszköz neve', inp('eszkoz_nev', 'Név', { ertek: eszkozNevJavaslat(), kotelezo: true }), async (a, f, mm) => { mm.bezar(); res(a.eszkoz_nev || eszkozNevJavaslat()); }, { ok: 'Mentés' }); m.el.addEventListener('click', (e) => { if (e.target === m.el) res(eszkozNevJavaslat()); }); });
        await api('passkey_regisztracio', { attestation: att, eszkoz_nev: nev });
        toast('Eszköz regisztrálva – mostantól jelszó nélkül, QR-kóddal léphetsz be', 'siker', 6000); render();
      } catch (e) { toast(esc(waHiba(e)), 'hiba', 7000); el.disabled = false; }
    },
    'passkey-torol': async (el) => { if (await confirmModal('Eszköz törlése', `<p>Törlöd a(z) <b>${esc(el.dataset.nev)}</b> eszközt? Ezzel az eszközzel ezután nem lehet belépést jóváhagyni.</p>`, { ok: 'Törlés', okOsztaly: 'btn-piros' })) { await api('passkey_torol', { id: Number(el.dataset.id) }); toast('Eszköz törölve'); render(); } },
    'admin-regkod': async (el) => {
      const r = await api('admin_regisztracios_kod', { id: Number(el.dataset.id) });
      modal({ cim: `Eszköz-regisztrációs kód – ${esc(r.felhasznalonev)}`, html: `<p class="kicsi szurke" style="margin:0 0 10px">A felhasználó a <b>telefonjával</b> olvassa be (vagy nyissa meg a linket), és Face ID-val / ujjlenyomattal regisztrálja az eszközét. Egyszer használható, ${r.lejarat_perc} percig érvényes.</p><div class="qr-kep">${window.BaninaQR ? window.BaninaQR.svg(r.url) : ''}</div><div class="mezo" style="margin-top:12px"><label>Link (ha nem tudja beolvasni)</label><input type="text" readonly value="${esc(r.url)}"></div><button class="btn btn-outline btn-blokk" type="button" data-act="masol" data-szoveg="${esc(r.url)}">Link másolása</button>` });
    },
    'admin-passkey-torol': async (el) => { if (await confirmModal('Összes eszköz törlése', `<p><b>${esc(el.dataset.felh)}</b> minden regisztrált eszközét törlöd. Utána jelszóval léphet be (ha van), vagy új regisztrációs kódot kell adnod.</p>`, { ok: 'Törlés', okOsztaly: 'btn-piros' })) { await api('admin_passkey_torol', { id: Number(el.dataset.id) }); toast('Eszközök törölve'); render(); } },
    'masol': async (el) => { try { await navigator.clipboard.writeText(el.dataset.szoveg); toast('Kimásolva a vágólapra', 'siker'); } catch (e) { toast('Nem sikerült másolni – jelöld ki és másold kézzel', 'hiba'); } },
    'mentes-most': async (el) => { el.disabled = true; try { const r = await api('admin_mentes_most'); toast(`${I.check} Mentés kész: <b>${esc(r.fajl)}</b> (${r.tablak} tábla, ${r.sorok} sor, ${fmtMeret(r.meret)})`, 'siker', 6000); await mentesekFrissit(); } finally { el.disabled = false; } },
    'mentes-visszaallit': (el) => mentesVisszaallitas(el),
    'mentes-torol': async (el) => { if (await confirmModal('Mentés törlése', `<p>Törlöd a(z) <b class="mono">${esc(el.dataset.fajl)}</b> mentésfájlt? Ez nem vonható vissza.</p>`, { ok: 'Törlés', okOsztaly: 'btn-piros' })) { await api('admin_mentes_torol', { fajl: el.dataset.fajl }); toast('Mentésfájl törölve'); mentesekFrissit(); } },
    'cron-mutat': () => { const d = $('[data-cron]'); if (d) d.classList.toggle('rejtett'); },
    'cron-kulcs-uj': async () => { if (await confirmModal('Új cron-kulcs', '<p>Új kulcsot generálsz – a régi URL-lel beállított cron job ezután <b>nem fog működni</b>, a cron parancsot frissíteni kell.</p>', { ok: 'Új kulcs', okOsztaly: 'btn-piros' })) { await api('admin_cron_kulcs_uj'); toast('Új cron-kulcs generálva – frissítsd a cron parancsot', 'siker', 5000); await mentesekFrissit(); const d = $('[data-cron]'); if (d) d.classList.remove('rejtett'); } },
    'admin-felh-uj': () => adminFelhasznaloForm(),
    'admin-felh-szerk': (el) => adminFelhasznaloForm({ id: Number(el.dataset.id), nev: el.dataset.nev, felh: el.dataset.felh, szerep: el.dataset.szerep, aktiv: el.dataset.aktiv === '1' }),
    'arfolyam-frissit': async () => { const r = await api('arfolyam', { frissit: true }); toast(r.arfolyam ? `1 EUR = ${fmtOsszeg(r.arfolyam.eur_huf)} HUF (${esc(r.arfolyam.forras)})` : 'Az árfolyam nem elérhető', r.arfolyam ? 'siker' : 'hiba'); render(); },
    'naplo-olvas': async () => { const f = $('[data-naplo-fajl]').value; if (!f) return; const box = $('[data-naplo]'); box.classList.remove('rejtett'); box.textContent = 'Betöltés…'; const d = await api('admin_naplo_olvas', { fajl: f, sorok: 500 }); box.textContent = `# ${d.fajl} – összesen ${d.osszes_sor} sor, az utolsó ${d.sorok.length}:\n` + d.sorok.join('\n'); box.scrollTop = box.scrollHeight; },
  };

  document.addEventListener('click', async (e) => {
    const el = e.target.closest('[data-act]');
    if (!el || el.disabled) return;
    if (el.tagName === 'INPUT') return; // jelölőnégyzeteket a change kezeli
    const fn = ACT[el.dataset.act];
    if (!fn) return;
    if (el.tagName === 'A') e.preventDefault();
    try { await fn(el, e); } catch (err) { hibaToast(err); }
  });
  document.addEventListener('change', (e) => {
    const el = e.target;
    if (el.matches('[data-act=kimeno-jelol]')) {
      const s = App.kimenoSorok[Number(el.dataset.szamla)];
      if (!s) return;
      try { if (BankKosar.van(s.id) !== el.checked) BankKosar.valt(s); } catch (err) { el.checked = false; hibaToast(err); }
      BankKosar.frissit();
    } else if (el.matches('[data-act=ov-ceg]')) {
      const r = route();
      nav('#/osszevetes' + qs({ ceg: el.value, penznem: r.query.penznem || 'EUR', tol: r.query.tol || '', ig: r.query.ig || '' }));
    } else if (el.matches('[data-act=idoszak-mezo]')) {
      idoszakMezoValt(el);
    } else if (el.matches('[data-act=mu-ceg]')) {
      App.mindenUtalasSzuro.ceg = el.value; render();
    } else if (el.matches('[data-act=mentes-feltolt]')) {
      mentesFeltoltes(el);
    } else if (el.matches('[data-act=archiv-jelol]')) {
      const id = Number(el.dataset.szamla);
      if (el.checked) App.archivKijeloles.add(id); else App.archivKijeloles.delete(id);
      el.closest('.kijelolo').classList.toggle('bejelolt', el.checked);
      const k = el.closest('.kartya'); if (k) k.classList.toggle('bejelolt', el.checked);
      const s = $('.also-sav'); if (s) s.outerHTML = archivAlsoSav();
    } else if (el.matches('[data-act=import-fajl]')) {
      importFeltoltes(el);
    } else if (el.matches('[data-act=import-sor]')) {
      const i = Number(el.dataset.i);
      if (el.checked) App.importAllapot.kivalasztott.add(i); else App.importAllapot.kivalasztott.delete(i);
      importOsszegzoFrissit();
    } else if (el.matches('[data-act=import-opcio]')) {
      App.importAllapot.opciok[el.name] = el.value;
      if (el.name === 'utkozes') importUtkozesAlap();
      if (el.name !== 'hatarido_nap') importSorokRajzol();
    } else if (el.matches('[data-act=import-csoport-cel]')) {
      importCsoportCel(el);
    } else if (el.matches('[data-act=import-csoport-nev]')) {
      importCsoportNev(el);
    } else if (el.matches('[data-act=reszt-bejovo-kapcsolo]')) {
      api('admin_beallitas_ment', { kulcs: 'reszt_bejovo', ertek: el.checked }).then((r) => { if (r.beallitasok) App.beallitasok = r.beallitasok; toast(el.checked ? 'Részteljesítés a bejövő számláknál: bekapcsolva' : 'Részteljesítés a bejövő számláknál: kikapcsolva (csak kimenő oldalon)', 'siker', 4000); }).catch((e) => { hibaToast(e); el.checked = !el.checked; });
    } else if (el.matches('[data-act=qr-belepes-kapcsolo]')) {
      api('admin_beallitas_ment', { kulcs: 'qr_belepes', ertek: el.checked }).then((r) => {
        App.qrBelepes = r.qr_belepes;
        if (App.beallitasok) App.beallitasok.qr_belepes = r.qr_belepes;
        const k = $('[data-qr-kartya]'); if (k) k.innerHTML = qrKartyaBelso(r.qr_belepes, r.jelszo_nelkul || []);
        const sz = $('[data-felh-belepes]'); if (sz) sz.innerHTML = felhBelepesSzoveg(r.qr_belepes);
        toast(r.qr_belepes ? 'QR-kódos belépés: bekapcsolva' : 'QR-kódos belépés: kikapcsolva – mindenki jelszóval lép be', 'siker', 4500);
      }).catch((e) => { hibaToast(e); el.checked = !el.checked; });
    } else if (el.matches('[data-act=import-mentes-kapcsolo]')) {
      api('admin_beallitas_ment', { kulcs: 'import_mentes', ertek: el.checked }).then(() => toast(el.checked ? 'Import előtti DB-mentés: bekapcsolva' : 'Import előtti DB-mentés: kikapcsolva – az importok mentés nélkül futnak!', el.checked ? 'siker' : 'hiba', 4000)).catch((e) => { hibaToast(e); el.checked = !el.checked; });
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.matches('[data-bank-q]')) { e.preventDefault(); ACT['bank-keres'](); }
    if (e.target.matches('[data-kereso-input]')) keresoBillentyu(e);
  });
  document.addEventListener('input', (e) => {
    if (e.target.matches('[data-kereso-input]')) keresoInput(e.target);
  });
  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-form]');
    if (!form) return;
    e.preventDefault();
    const a = formAdat(form);
    const gomb = $('button[type=submit]', form);
    try {
      gomb.disabled = true;
      if (form.dataset.form === 'belepes-nev') {
        if (!a.felhasznalonev) throw new Error('Add meg a felhasználónevet!');
        await qrBelepesInditas(a.felhasznalonev.toLowerCase());
      } else if (form.dataset.form === 'belepes') {
        const r = await api('belepes', { felhasznalonev: a.felhasznalonev, jelszo: a.jelszo });
        belepve(r); hataridoAdat = null; App.cegek = null; render();
      } else if (form.dataset.form === 'jelszo') {
        if (a.uj_jelszo !== a.uj_jelszo2) throw new Error('A két új jelszó nem egyezik.');
        await api('jelszo_modositas', { regi_jelszo: a.regi_jelszo, uj_jelszo: a.uj_jelszo });
        toast('Jelszó módosítva', 'siker'); nav('#/');
      } else if (form.dataset.form === 'arfolyam') {
        await api('admin_beallitas_ment', { kulcs: 'eur_huf_kezi', ertek: a.ertek }); toast('Mentve', 'siker'); render();
      }
    } catch (err) {
      const d = $('[data-hiba]', form);
      if (d) { d.textContent = err.message; d.classList.remove('rejtett'); } else hibaToast(err);
    } finally { gomb.disabled = false; }
  });

  // ================================================================= render
  let renderSzamlalo = 0;
  async function render() {
    const my = ++renderSzamlalo;
    $('#modal-root').innerHTML = '';
    qrLeallit();
    const r = route();
    const p = r.path;
    if (p[0] === 'qr' && p[1]) return await viewQrJovahagyas(p[1]);
    if (p[0] === 'regisztral' && p[1]) return await viewRegisztral(p[1]);
    if (!App.user) { viewLogin(); return; }
    try {
      if (p.length === 0) return viewHome();
      if (p[0] === 'bejovo') {
        if (p.length === 1) return await viewBejovoCegek();
        if (p[1] === 'ceg' && p.length === 3) return await viewKotesek(Number(p[2]));
        if (p[1] === 'ceg' && p[3] === 'kotes') return await viewSzamlak(Number(p[2]), Number(p[4]), r.query);
        if (p[1] === 'ceg' && p[3] === 'utalasok') return await viewCegUtalasok(Number(p[2]));
      }
      if (p[0] === 'utalas' && p[1]) return await viewUtalas(Number(p[1]));
      if (p[0] === 'utalasok') return await viewMindenUtalas();
      if (p[0] === 'kimeno') {
        if (p.length === 1) return await viewKimenoCegek();
        if (p[1] === 'ceg' && p[2]) return await viewKimenoSzamlak(Number(p[2]), r.query);
      }
      if (p[0] === 'osszevetes') return await viewOsszevetes(r.query);
      if (p[0] === 'hataridok') { hataridoAdat = null; return await viewHataridok(); }
      if (p[0] === 'import') return await viewImport(r.query);
      if (p[0] === 'admin') return await viewAdmin();
      if (p[0] === 'profil') return await viewProfil();
      nav('#/');
    } catch (e) {
      if (my === renderSzamlalo) hibaToast(e);
    }
  }
  window.addEventListener('hashchange', () => { window.scrollTo(0, 0); render(); });
  Jelenlet.indit();
  render();
})();
