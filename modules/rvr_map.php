<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;

// ── Module guard ─────────────────────────
if (($GLOBALS['cms_settings']['mod_rvr_map'] ?? '1') === '0' && ($GLOBALS['userPriv'] ?? 0) < 4) {
    echo '<div class="info-msg">This section is currently not available.</div>';
    return;
}

// Site name from settings
$siteName = $db->query("SELECT value FROM settings WHERE setting_key = 'site_name' LIMIT 1")->fetchColumn();
$siteName = $siteName ? htmlspecialchars($siteName) : 'DAoC CMS';
$pageTitle = $siteName . ' Warmap';

// ── Load initial keep data from DB ───────────────────────────
$_rw_keep_map = [
    'dun_crauchon'    => 100, 'dun_crimthain'   => 101, 'dun_bolg'        => 102,
    'dun_nged'        => 103, 'dun_da_behnn'    => 104, 'dun_scathaig'    => 105,
    'dun_ailinne'     => 106, 'bledmeer'        => 75,  'nottmoor'        => 76,
    'hlidskialf'      => 77,  'blendrake'       => 78,  'glenlock'        => 79,
    'fensalir'        => 80,  'arvakr'          => 81,  'caer_benowyc'    => 50,
    'caer_berkstead'  => 51,  'caer_erasleigh'  => 52,  'caer_boldiam'    => 53,
    'caer_sursbrooke' => 54,  'caer_hurbury'    => 55,  'caer_renaris'    => 56,
];
$_rw_realm_map = [0 => 'neutral', 1 => 'alb', 2 => 'mid', 3 => 'hib'];
$_rw_initial   = [];
try {
    $_rw_indexed = daoc_game_realm_war_keeps($db, array_values($_rw_keep_map));
    foreach ($_rw_keep_map as $slug => $kid) {
        $rid = isset($_rw_indexed[$kid]) ? (int)$_rw_indexed[$kid]['Realm'] : 0;
        $gname = isset($_rw_indexed[$kid]) ? (string)$_rw_indexed[$kid]['GuildName'] : '';
        $_rw_initial[$slug] = [
            'owner' => $_rw_realm_map[$rid] ?? 'neutral',
            'guild' => $gname
        ];
    }
} catch (Throwable $e) {
    error_log('RvR map keep query failed: ' . $e->getMessage());
    foreach ($_rw_keep_map as $slug => $_) { $_rw_initial[$slug] = ['owner' => 'neutral', 'guild' => '']; }
}

// Query relics safely (empty table tolerated)
$_rw_relics = [];
try {
    $_rw_relics = daoc_game_realm_war_relics($db);
} catch (Throwable $e) {
    error_log('RvR map relic query failed: ' . $e->getMessage());
    $_rw_relics = [];
}
?>

<link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
<script src="assets/vendor/leaflet/leaflet.js"></script>

<div id="warmap-wrap">

  <!-- HEADER -->
  <div id="wm-header">
    <h2><?= $pageTitle ?> <span>·</span> FRONTIERS</h2>

    <div class="wm-realm-scores">
      <div class="wm-realm-score alb">
        <div class="dot"></div>ALBION
        <div class="count" id="wm-alb-count">0</div>
      </div>
      <div class="wm-realm-score mid">
        <div class="dot"></div>MIDGARD
        <div class="count" id="wm-mid-count">0</div>
      </div>
      <div class="wm-realm-score hib">
        <div class="dot"></div>HIBERNIA
        <div class="count" id="wm-hib-count">0</div>
      </div>
    </div>

    <div class="wm-live-badge">
      <div class="wm-live-dot"></div>LIVE
    </div>
  </div>

  <!-- BODY -->
  <div id="wm-body">
    <div id="wm-sidebar"></div>
    <div id="wm-map-container">
      <div id="wm-map"></div>
      <div class="wm-compass" aria-hidden="true">
        <svg viewBox="0 0 100 100">
          <circle cx="50" cy="50" r="46" class="wm-compass-ring"/>
          <circle cx="50" cy="50" r="38" class="wm-compass-ring-inner"/>
          <polygon points="50,8 45,50 50,46 55,50" class="wm-compass-n"/>
          <polygon points="50,92 45,50 50,54 55,50" class="wm-compass-s"/>
          <text x="50" y="20" class="wm-compass-label">N</text>
        </svg>
      </div>
    </div>
  </div>

  <!-- FOOTER -->
  <div id="wm-footer">
    <span>EMAIN MACHA</span><span class="wm-sep">·</span>
    <span>ODIN'S GATE</span><span class="wm-sep">·</span>
    <span>HADRIAN'S WALL</span><span class="wm-sep">·</span>
    <span>BREIFINE</span><span class="wm-sep">·</span>
    <span>PENNINE MOUNTAINS</span><span class="wm-sep">·</span>
    <span>JAMTLAND MOUNTAINS</span>
    <div class="wm-df-status">DARKNESS FALLS: <span id="wm-df-owner">NEUTRAL</span></div>
  </div>

</div>

<script>
const WM_ZONES = {
  emain:    { name: "Emain Macha"         },
  odin:     { name: "Odin's Gate"         },
  hadrian:  { name: "Hadrian's Wall"      },
  breifine: { name: "Breifine"            },
  pennine:  { name: "Pennine Mountains"   },
  jamtland: { name: "Jamtland Mountains"  },
};

const WM_KEEPS = [
  // ── Emain Macha (Hib Frontier) ── Portal Keep = Dun Crauchon (DB ID 100)
  { id:'dun_crauchon',   name:'Dun Crauchon',    zone:'emain',   type:'portal', x:110, y:500, owner:'hib' },
  { id:'dun_crimthain',  name:'Dun Crimthain',   zone:'emain',   type:'keep',   x:70,  y:430, owner:'hib' },
  { id:'dun_bolg',       name:'Dun Bolg',        zone:'emain',   type:'keep',   x:135, y:560, owner:'hib' },
  { id:'dun_nged',       name:'Dun nGed',        zone:'emain',   type:'keep',   x:60,  y:640, owner:'hib' },
  { id:'dun_da_behnn',   name:'Dun Da Behnn',    zone:'emain',   type:'keep',   x:145, y:660, owner:'hib' },
  { id:'dun_scathaig',   name:'Dun Scathaig',    zone:'emain',   type:'keep',   x:75,  y:790, owner:'hib' },
  { id:'dun_ailinne',    name:'Dun Ailinne',     zone:'emain',   type:'keep',   x:55,  y:720, owner:'hib' },
  // ── Odin's Gate (Mid Frontier) ── Portal Keep = Bledmeer Faste (DB ID 75)
  { id:'bledmeer',       name:'Bledmeer Faste',  zone:'odin',    type:'portal', x:390, y:460, owner:'mid' },
  { id:'nottmoor',       name:'Nottmoor Faste',  zone:'odin',    type:'keep',   x:400, y:385, owner:'mid' },
  { id:'hlidskialf',     name:'Hlidskialf Faste',zone:'odin',    type:'keep',   x:595, y:465, owner:'mid' },
  { id:'blendrake',      name:'Blendrake Faste', zone:'odin',    type:'keep',   x:510, y:460, owner:'mid' },
  { id:'glenlock',       name:'Glenlock Faste',  zone:'odin',    type:'keep',   x:530, y:390, owner:'mid' },
  { id:'fensalir',       name:'Fensalir Faste',  zone:'odin',    type:'keep',   x:595, y:345, owner:'mid' },
  { id:'arvakr',         name:'Arvakr Faste',    zone:'odin',    type:'keep',   x:665, y:430, owner:'mid' },
  // ── Hadrian's Wall (Alb Frontier) ── Portal Keep = Caer Benowyc (DB ID 50)
  { id:'caer_benowyc',   name:'Caer Benowyc',    zone:'hadrian', type:'portal', x:790, y:520, owner:'alb' },
  { id:'caer_berkstead', name:'Caer Berkstead',  zone:'hadrian', type:'keep',   x:870, y:510, owner:'alb' },
  { id:'caer_erasleigh', name:'Caer Erasleigh',  zone:'hadrian', type:'keep',   x:775, y:570, owner:'alb' },
  { id:'caer_boldiam',   name:'Caer Boldiam',    zone:'hadrian', type:'keep',   x:865, y:590, owner:'alb' },
  { id:'caer_sursbrooke',name:'Caer Sursbrooke', zone:'hadrian', type:'keep',   x:800, y:635, owner:'alb' },
  { id:'caer_hurbury',   name:'Caer Hurbury',    zone:'hadrian', type:'keep',   x:855, y:700, owner:'alb' },
  { id:'caer_renaris',   name:'Caer Renaris',    zone:'hadrian', type:'keep',   x:940, y:645, owner:'alb' },
];

// Portal keep per zone (for supply lines)
const WM_PORTALS = { emain:'dun_crauchon', odin:'bledmeer', hadrian:'caer_benowyc' };

const WM_REALM_COLORS = { alb:'#c0392b', mid:'#2980b9', hib:'#27ae60', neutral:'#333333' };
const WM_REALM_NAMES  = { alb:'Albion',  mid:'Midgard',  hib:'Hibernia', neutral:'Neutral' };

// ── Leaflet init ───────────────────────────────────────────────
const wmMap = L.map('wm-map', {
  crs: L.CRS.Simple,
  minZoom: -2, maxZoom: 3,
  zoomControl: true,
  attributionControl: false,
  center: [500, 500], zoom: 0,
});

// ── Terrain background (SVG image overlay, no external file) ───
(function drawWmTerrain() {
  const svg = `
    <svg xmlns='http://www.w3.org/2000/svg' width='1000' height='1000' viewBox='0 0 1000 1000'>
      <defs>
        <radialGradient id='sea' cx='50%' cy='50%' r='75%'>
          <stop offset='0%' stop-color='#0a1420'/>
          <stop offset='100%' stop-color='#050a10'/>
        </radialGradient>
        <filter id='rough'><feTurbulence type='fractalNoise' baseFrequency='0.012' numOctaves='3' seed='7'/>
          <feColorMatrix type='matrix' values='0 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 0.5 0'/>
          <feComposite operator='in' in2='SourceGraphic'/></filter>
      </defs>
      <rect width='1000' height='1000' fill='url(#sea)'/>
      <!-- Hibernia landmass (west) -->
      <path d='M0,120 C120,90 210,140 250,260 C280,360 240,470 260,560 C285,680 230,780 250,900 C180,940 90,930 0,940 Z'
            fill='#12241a' stroke='#1e3a29' stroke-width='2'/>
      <!-- Midgard landmass (center) -->
      <path d='M330,80 C430,110 560,90 690,130 C720,250 690,360 700,470 C690,600 720,720 690,860 C560,900 430,880 340,900 C360,760 330,640 350,520 C330,380 360,220 330,80 Z'
            fill='#10202e' stroke='#1c3446' stroke-width='2'/>
      <!-- Albion landmass (east) -->
      <path d='M740,100 C860,80 950,130 1000,110 L1000,910 C920,930 830,910 750,900 C775,760 745,640 760,520 C740,380 770,240 740,100 Z'
            fill='#241414' stroke='#3a1e1e' stroke-width='2'/>
      <!-- Terrain grain -->
      <rect width='1000' height='1000' filter='url(#rough)' opacity='0.35'/>
      <!-- Mountain hatching -->
      <g stroke='#000' stroke-width='1' opacity='0.25'>
        <path d='M60,700 l15,-22 l15,22 M85,700 l15,-22 l15,22' fill='none'/>
        <path d='M560,240 l18,-26 l18,26 M590,250 l18,-26 l18,26' fill='none'/>
        <path d='M840,680 l16,-24 l16,24 M868,690 l16,-24 l16,24' fill='none'/>
      </g>
    </svg>`;
  const url = 'data:image/svg+xml;base64,' + btoa(svg);
  L.imageOverlay(url, [[0,0],[1000,1000]], { opacity: 1, interactive: false, zIndex: 1 }).addTo(wmMap);
})();

// ── Zone tint + labels + supply lines ─────────────────────────
(function drawWmZones() {
  const zones = [
    [0,   0,  330, 1000, 'rgba(39,174,96,0.05)',  'EMAIN MACHA',    165, 60],
    [335, 0,  665, 1000, 'rgba(41,128,185,0.05)', "ODIN'S GATE",    500, 60],
    [670, 0, 1000, 1000, 'rgba(192,57,43,0.05)',  "HADRIAN'S WALL", 835, 60],
  ];

  zones.forEach(([x1,y1,x2,y2,fill,label,lx,ly]) => {
    L.rectangle([[y1,x1],[y2,x2]], {
      color: fill.replace(/0\.05/,'0.15'),
      fillColor: fill, fillOpacity: 1, weight: 1, interactive: false, zIndex: 2,
    }).addTo(wmMap);

    L.marker([ly,lx], {
      icon: L.divIcon({
        className: '',
        html: `<div class="wm-zone-label">${label}</div>`,
        iconSize: [200,20], iconAnchor: [100,10],
      }),
      interactive: false, zIndexOffset: -1000,
    }).addTo(wmMap);
  });

  [[330,0,330,1000],[665,0,665,1000]].forEach(([x1,y1,x2,y2]) => {
    L.polyline([[y1,x1],[y2,x2]], {
      color:'#000', weight:1, interactive:false, dashArray:'2,10', opacity:0.5,
    }).addTo(wmMap);
  });
})();

// ── Supply lines (portal keep -> its keeps) ───────────────────
const wmSupplyLines = [];
function wmDrawSupplyLines() {
  wmSupplyLines.forEach(l => wmMap.removeLayer(l));
  wmSupplyLines.length = 0;
  Object.entries(WM_PORTALS).forEach(([zone, portalId]) => {
    const portal = WM_KEEPS.find(k => k.id === portalId);
    if (!portal) return;
    WM_KEEPS.filter(k => k.zone === zone && k.id !== portalId).forEach(k => {
      const col = WM_REALM_COLORS[portal.owner] ?? '#333';
      const line = L.polyline([[portal.y, portal.x],[k.y, k.x]], {
        color: col, weight: 1, opacity: 0.28, interactive: false, dashArray: '3,6',
      }).addTo(wmMap);
      wmSupplyLines.push(line);
    });
  });
}

// ── Relic temples on map ──────────────────────────────────────
(function drawWmRelicTemples() {
  const temples = [
    { zone:'Strength', realm:'alb', x:900, y:250 },
    { zone:'Power',    realm:'alb', x:920, y:800 },
    { zone:'Strength', realm:'mid', x:500, y:180 },
    { zone:'Power',    realm:'mid', x:500, y:830 },
    { zone:'Strength', realm:'hib', x:120, y:230 },
    { zone:'Power',    realm:'hib', x:130, y:820 },
  ];
  temples.forEach(t => {
    L.marker([t.y, t.x], {
      icon: L.divIcon({
        className: '',
        html: `<div class="wm-relic-temple ${t.realm}" title="${t.zone} Relic"><i class="fas fa-gopuram"></i></div>`,
        iconSize: [26,26], iconAnchor: [13,13],
      }),
      zIndexOffset: 500,
    }).addTo(wmMap);
  });
})();

// ── Markers ────────────────────────────────────────────────────
const wmMarkers = {};

function wmMarkerHtml(keep) {
  const isPortal = keep.type === 'portal';
  const cls = `wm-keep-marker ${keep.owner}${isPortal ? ' wm-portal-keep' : ''}`;
  const icon = isPortal ? 'fa-chess-rook' : 'fa-tower-observation';
  return `<div class="${cls}">
            <div class="wm-keep-shape"><i class="fas ${icon}"></i></div>
            ${isPortal ? '<div class="wm-keep-crown"><i class="fas fa-crown"></i></div>' : ''}
          </div>`;
}

function wmCreateMarker(keep) {
  const isPortal = keep.type === 'portal';
  const size = isPortal ? 34 : 26;

  const icon = L.divIcon({
    className: '',
    html: wmMarkerHtml(keep),
    iconSize: [size, size], iconAnchor: [size/2, size/2],
  });

  const marker = L.marker([keep.y, keep.x], { icon })
    .bindPopup(wmPopupHtml(keep), { className:'wm-popup-wrap', maxWidth:240, offset:[0,-10] })
    .addTo(wmMap);

  wmMarkers[keep.id] = marker;
}

function wmPopupHtml(keep) {
  return `<div class="wm-popup">
    <div class="wm-popup-name">${keep.name.toUpperCase()}</div>
    <div class="wm-popup-zone">${WM_ZONES[keep.zone]?.name ?? keep.zone}</div>
    <div class="wm-popup-owner">
      <div class="wm-owner-dot ${keep.owner}"></div>
      <span class="wm-owner-${keep.owner}">${WM_REALM_NAMES[keep.owner]}</span>
      ${keep.type === 'portal' ? '<span class="wm-popup-portal">· Portal Keep</span>' : ''}
    </div>
    ${keep.guild ? `<div class="wm-popup-guild"><i class="fas fa-shield-halved"></i> &lt; ${keep.guild} &gt;</div>` : ''}
  </div>`;
}

function wmUpdateMarker(keep) {
  const m = wmMarkers[keep.id];
  if (!m) return;
  const isPortal = keep.type === 'portal';
  const size = isPortal ? 34 : 26;
  m.setIcon(L.divIcon({
    className: '',
    html: wmMarkerHtml(keep),
    iconSize: [size,size], iconAnchor: [size/2,size/2],
  }));
  m.setPopupContent(wmPopupHtml(keep));
}

// ── Sidebar ────────────────────────────────────────────────────
function wmBuildSidebar() {
  const sb = document.getElementById('wm-sidebar');
  sb.innerHTML = '';

  const groups = [
    { key:'emain',    label:'EMAIN MACHA',   realm:'hib' },
    { key:'odin',      label:"ODIN'S GATE",   realm:'mid' },
    { key:'hadrian',  label:"HADRIAN'S WALL",realm:'alb' },
    { key:'breifine', label:'BREIFINE',       realm:'hib' },
    { key:'pennine',  label:'PENNINE MTS.',   realm:'alb' },
    { key:'jamtland', label:'JAMTLAND MTS.',  realm:'mid' },
  ];

  groups.forEach(zone => {
    const keeps = WM_KEEPS.filter(k => k.zone === zone.key);
    if (!keeps.length) return;
    const col = WM_REALM_COLORS[zone.realm];

    const div = document.createElement('div');
    div.className = 'wm-zone';
    div.innerHTML = `
      <div class="wm-zone-header" style="--zone-col:${col};">
        <span>${zone.label}</span>
        <span class="wm-zone-count">${keeps.length}</span>
      </div>
      <div class="wm-keep-list">
        ${keeps.map(k => `
          <div class="wm-keep-item ${k.owner !== 'neutral' ? 'owned-'+k.owner : ''}" data-id="${k.id}">
            <div class="wm-keep-icon"></div>${k.name}
            ${k.guild ? `<span class="wm-keep-guild">&lt;${k.guild}&gt;</span>` : ''}
          </div>`).join('')}
      </div>`;

    div.querySelectorAll('.wm-keep-item').forEach(el => {
      el.addEventListener('click', () => {
        const k = WM_KEEPS.find(k => k.id === el.dataset.id);
        if (k) wmMap.flyTo([k.y, k.x], 1, { duration: 0.5 });
      });
    });

    sb.appendChild(div);
  });

  // ── Captured relics section ──
  const relDiv = document.createElement('div');
  relDiv.className = 'wm-zone';

  let relicsHtml = '';
  if (typeof WM_RELICS !== 'undefined' && WM_RELICS.length > 0) {
      WM_RELICS.forEach(r => {
          if (parseInt(r.Realm) !== parseInt(r.OriginalRealm)) {
              const currentRealm = WM_REALM_NAMES[r.Realm === 1 ? 'alb' : r.Realm === 2 ? 'mid' : r.Realm === 3 ? 'hib' : 'neutral'];
              const origRealm = WM_REALM_NAMES[r.OriginalRealm === 1 ? 'alb' : r.OriginalRealm === 2 ? 'mid' : r.OriginalRealm === 3 ? 'hib' : 'neutral'];
              const rName = r.RelicName || `Relic #${r.RelicID}`;
              relicsHtml += `
                <div class="wm-relic-row">
                  <i class="fas fa-trophy"></i><strong>${rName}</strong><br>
                  <span class="wm-relic-sub">Captured by ${currentRealm} (Orig: ${origRealm})</span>
                </div>`;
          }
      });
  }

  if (!relicsHtml) {
      relicsHtml = '<div class="wm-relic-secure">All relics secure.</div>';
  }

  relDiv.innerHTML = `
    <div class="wm-zone-header wm-zone-header--relic" style="--zone-col:var(--gold,#c5a059);">
      <span>CAPTURED RELICS</span>
    </div>
    <div class="wm-keep-list">
      ${relicsHtml}
    </div>`;
  sb.appendChild(relDiv);
}

// ── Scores ─────────────────────────────────────────────────────
function wmUpdateScores(counts, dfOwner) {
  document.getElementById('wm-alb-count').textContent = counts.alb ?? 0;
  document.getElementById('wm-mid-count').textContent = counts.mid ?? 0;
  document.getElementById('wm-hib-count').textContent = counts.hib ?? 0;

  const dfEl = document.getElementById('wm-df-owner');
  dfEl.textContent = WM_REALM_NAMES[dfOwner]?.toUpperCase() ?? 'NEUTRAL';
  dfEl.style.color  = WM_REALM_COLORS[dfOwner] ?? '#333';
}

function wmScoreFromLocal() {
  const c = { alb:0, mid:0, hib:0 };
  WM_KEEPS.forEach(k => { if (c[k.owner] !== undefined) c[k.owner]++; });
  const winner = Object.entries(c).sort((a,b)=>b[1]-a[1])[0][0];
  wmUpdateScores(c, winner);
}

// ── API polling via CMS router ─────────────────────────────────
async function wmFetch() {
  try {
    const res  = await fetch('index.php?p=realmwar_json').catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    if (!data.success) throw new Error('API error');

    WM_KEEPS.forEach(keep => {
      if (data.keeps[keep.id] !== undefined) {
        if (typeof data.keeps[keep.id] === 'object') {
            keep.owner = data.keeps[keep.id].owner;
            keep.guild = data.keeps[keep.id].guild;
        } else {
            keep.owner = data.keeps[keep.id];
        }
        wmUpdateMarker(keep);
      }
    });

    wmUpdateScores(data.counts, data.df_owner);
    wmDrawSupplyLines();
    wmBuildSidebar();
  } catch (e) {
    console.warn('Warmap API unreachable:', e);
    wmScoreFromLocal();
  }
}

// ── INIT ───────────────────────────────────────────────────────
// Initial keep states embedded from PHP → no flicker
const WM_INITIAL = <?php echo json_encode($_rw_initial); ?>;
const WM_RELICS = <?php echo json_encode($_rw_relics); ?>;
WM_KEEPS.forEach(keep => {
  if (WM_INITIAL[keep.id] !== undefined) {
    keep.owner = WM_INITIAL[keep.id].owner;
    keep.guild = WM_INITIAL[keep.id].guild;
  }
});

WM_KEEPS.forEach(wmCreateMarker);
wmDrawSupplyLines();
wmBuildSidebar();
wmScoreFromLocal();
wmMap.fitBounds([[0,0],[1000,1000]], { padding: [20,20] });
wmFetch();
setInterval(wmFetch, 30000);
</script>