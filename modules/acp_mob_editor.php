<?php
// SPDX-License-Identifier: GPL-3.0-only


if (!defined('IN_CMS')) { die('Direct access denied.'); }
if (!isset($userPriv) || $userPriv < 3) { echo "Access denied."; return; }

// ── TERRAIN SERVICE CONFIG ────────────────────────────────────
// Base URL of the .NET TerrainService (see the /terrainservice project).
// Adjustable per installation, e.g. overridable via a central config.php.
if (!defined('TERRAIN_SERVICE_URL'))         define('TERRAIN_SERVICE_URL', 'http://127.0.0.1:5200');
if (!defined('TERRAIN_SERVICE_TIMEOUT_MS'))  define('TERRAIN_SERVICE_TIMEOUT_MS', 800);

function getTerrainServiceZ(int $region, int $globalX, int $globalY): ?int {
    static $unavailableForRequest = false;
    if ($unavailableForRequest) return null;

    $url = rtrim(TERRAIN_SERVICE_URL, '/') . "/groundz?region={$region}&x={$globalX}&y={$globalY}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS     => TERRAIN_SERVICE_TIMEOUT_MS,
        CURLOPT_CONNECTTIMEOUT_MS => TERRAIN_SERVICE_TIMEOUT_MS,
    ]);
    $raw = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($err || $raw === false) {
        $unavailableForRequest = true;
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['success']) || !isset($data['z'])) {
        $unavailableForRequest = true;
        return null;
    }
    return (int)round($data['z']);
}

/**
 * Central fallback chain for ground-height resolution, used by
 * add_mob and get_nearest_z. Priority:
 * 1. Nearby manual calibration point (deliberate admin correction, e.g. dungeon/keep)
 * 2. TerrainService (exact client-data height)
 * 3. Median of the nearest mobs in the DB
 * 4. Zones-Default
 *
 * @return array{z:int, source:string}
 */
function resolveGroundZ(PDO $db, int $region, int $zoneId, int $globalX, int $globalY, int $leafX, int $leafY): array {
    // 1) Calibration – always wins when a point is close enough,
    //    since an admin deliberately placed this point to correct a known problem spot.
    $calFile = __DIR__ . "/../assets/data/zone_calibration.json";
    $calData = file_exists($calFile) ? json_decode(file_get_contents($calFile), true) : [];
    $zoneKey = "zone_{$zoneId}";
    if (!empty($calData[$zoneKey])) {
        $bestZ = null; $bestDist = PHP_INT_MAX;
        foreach ($calData[$zoneKey] as $cal) {
            $dist = pow($cal['lx'] - $leafX, 2) + pow($cal['ly'] - $leafY, 2);
            if ($dist < $bestDist) { $bestDist = $dist; $bestZ = (int)$cal['z']; }
        }
        if ($bestDist < 9000000 && $bestZ !== null) {
            return ['z' => $bestZ, 'source' => 'calibration'];
        }
    }

    // 2) TerrainService – exact height from client data
    $terrainZ = getTerrainServiceZ($region, $globalX, $globalY);
    if ($terrainZ !== null) {
        return ['z' => $terrainZ, 'source' => 'terrain'];
    }

    // 3) Median of the nearest mobs
    $nearStmt = $db->prepare(
        "SELECT Z FROM mob WHERE Z > 100 AND Region = ?
         ORDER BY (POW(X - ?, 2) + POW(Y - ?, 2)) ASC LIMIT 10"
    );
    $nearStmt->execute([$region, $globalX, $globalY]);
    $nearZ = $nearStmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($nearZ) >= 2) {
        sort($nearZ);
        return ['z' => (int)$nearZ[(int)floor(count($nearZ) / 2)], 'source' => 'nearest_mob'];
    }

    // 4) Zone default
    static $zDef = [0=>2500,1=>2000,2=>2000,3=>2000,4=>2000,6=>2000,7=>2000,9=>2000,12=>3000,
                    100=>2000,101=>2000,102=>2000,103=>2000,104=>3000,105=>2000,106=>2000,107=>2000,108=>2000,116=>2000,
                    200=>2000,201=>2000,202=>2000,203=>2000,204=>2000,205=>2000,206=>2000,207=>2000,216=>2000];
    return ['z' => $zDef[$zoneId] ?? 2500, 'source' => 'zone_default'];
}

// ── 3D VIEW INTERCEPT ─────────────────────────────────────────
function mobEditorUuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

if (isset($_GET['view']) && $_GET['view'] === '3d') {
    $zoneId3d = (int)($_GET['zone_id'] ?? 0);

    $z3d = null;
    try {
        $zs = $db->prepare("SELECT ZoneID,RegionID,Name AS ZoneName,OffsetX,OffsetY FROM zones WHERE ZoneID=? LIMIT 1");
        $zs->execute([$zoneId3d]); $z3d = $zs->fetch(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
    $r3d   = (int)($z3d['RegionID']??0);
    $mgx3d = (int)($z3d['OffsetX']??0)*8192;
    $mgy3d = (int)($z3d['OffsetY']??0)*8192;
    $mobs3d= [];
    try {
        $ms = $db->prepare("SELECT Mob_ID,Name,X,Y,Z,Level,Model,Race,PackageID,AggroLevel,AggroRange FROM mob WHERE Realm=0 AND Region=? ORDER BY Name");
        $ms->execute([$r3d]);
        foreach($ms->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $m['lx']=(int)$m['X']-$mgx3d; $m['ly']=(int)$m['Y']-$mgy3d; $mobs3d[]=$m;
        }
    } catch(Exception $e) {}
    $cal3d=[]; $cf=__DIR__."/../assets/data/zone_calibration.json";
    if(file_exists($cf)){$cd=json_decode(file_get_contents($cf),true);$cal3d=$cd["zone_{$zoneId3d}"]??[];}
    $fav3d=[]; $ff=__DIR__."/../assets/data/mob_favourites.json";
    if(file_exists($ff)){$fav3d=json_decode(file_get_contents($ff),true)??[];}
    $zimg3d=[0=>'assets/img/zones/albion/Camelot_Hills_map.webp',1=>'assets/img/zones/albion/Salisbury_Plains_map.webp',2=>'assets/img/zones/albion/Black_Mtns_South_map.webp',3=>'assets/img/zones/albion/Black_Mtns_North_map.webp',4=>'assets/img/zones/albion/Dartmoor_map.webp',6=>'assets/img/zones/albion/Cornwall_map.webp',7=>'assets/img/zones/albion/Llyn_Barfog_map.webp',8=>'assets/img/zones/albion/Campacorentin_Forest_map.webp',9=>'assets/img/zones/albion/Avalon_Marsh_map.webp',12=>'assets/img/zones/albion/Snowdonia_map.webp',100=>'assets/img/zones/midgard/Vale_of_Mularn_map.webp',101=>'assets/img/zones/midgard/East_Svealand_map.webp',102=>'assets/img/zones/midgard/West_Svealand_map.webp',103=>'assets/img/zones/midgard/Gotar_map.webp',104=>'assets/img/zones/midgard/Muspelheim_map.webp',105=>'assets/img/zones/midgard/Myrkwood_Forest_map.webp',106=>'assets/img/zones/midgard/Skona_Ravine_map.webp',107=>'assets/img/zones/midgard/Vanern_Swamp_map.webp',108=>'assets/img/zones/midgard/Raumarik_map.webp',116=>'assets/img/zones/midgard/Malmohus_map.webp',200=>'assets/img/zones/hibernia/Lough_Derg_map.webp',201=>'assets/img/zones/hibernia/Silvermine_Mountains_map.webp',202=>'assets/img/zones/hibernia/Shannon_Estuary_map.webp',203=>'assets/img/zones/hibernia/Cliffs_of_Moher_map.webp',204=>'assets/img/zones/hibernia/Lough_Gur_map.webp',205=>'assets/img/zones/hibernia/Bog_of_Cullen_map.webp',206=>'assets/img/zones/hibernia/Valley_of_Bri_Leith_map.webp',207=>'assets/img/zones/hibernia/Connacht_map.webp',216=>'assets/img/zones/hibernia/Sheeroe_Hills_map.webp'];
    $zi3d=$zimg3d[$zoneId3d]??$zimg3d[0];
    $zn3d=htmlspecialchars($z3d['ZoneName']??"Zone $zoneId3d");
    $csrfToken3d = generateToken();
    ?>
    <div id="v3d-wrap">
        <div id="v3d-bar">
            <span id="v3d-bar-title">🌐 3D · <?=$zn3d?></span>
            <span id="v3d-bar-hint">🖱 L-Drag = Move | <kbd>SHIFT</kbd> + L-Drag = Z-Height | R-Drag = Turn Map | Scroll = Zoom</span>
            <button id="v3d-bar-close" onclick="window.close();history.back();"><?= t("mobeditor.popup.close") ?></button>
        </div>
        <div id="v3d-wrap-inner">
            <canvas id="v3d-canvas"></canvas>
            <div id="v3d-load"><div id="v3d-load-txt">⚗ Loading…</div><div id="v3d-load-bar"><div id="v3d-load-fill"></div></div></div>
            <div id="v3d-tt"></div>
            <div id="v3d-leg">
                <div class="v3l"><div class="v3l-d acp-s-c7773693"></div>Mob</div>
                <div class="v3l"><div class="v3l-d acp-s-21a41d20"></div><?= t("mobeditor.3d.legend.lab") ?></div>
                <div class="v3l"><div class="v3l-d acp-s-8bfe3670"></div><?= t("mobeditor.3d.legend.fav") ?></div>
            </div>
           <div id="v3d-co-wrap">
                <span id="v3d-co-text">X: — Y: — Z: —</span>
                <span id="v3d-save-status"><?= t("mobeditor.3d.save_status") ?></span>
                <div class="acp-s-e126b91b">
                    <button class="v3d-mode-btn active" id="btn-terrain-lock" onclick="toggleTerrainLock()">🔒 Ground-Lock: ON</button>
                    <button class="v3d-mode-btn active" id="btn-coord-2d" onclick="setCoordMode('2d')">2D Local</button>
                    <button class="v3d-mode-btn" id="btn-coord-3d" onclick="setCoordMode('3d')">3D Space</button>
                    <button class="v3d-mode-btn" id="btn-coord-loc" onclick="setCoordMode('loc')">Global /loc</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    const _M=<?=json_encode($mobs3d,JSON_NUMERIC_CHECK)?>,_C=<?=json_encode($cal3d)?>,_I=<?=json_encode($zi3d)?>,_Z=65536,_F=<?=json_encode(array_map('strval',$fav3d))?>;
    const _OFFX=<?=$mgx3d?>, _OFFY=<?=$mgy3d?>;
    const _CSRF='<?=$csrfToken3d?>', _ZID=<?=$zoneId3d?>;
    
    function esc(str) { return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    let coordDisplayMode = '2d';
    let terrainLock = true;

    function toggleTerrainLock() {
        terrainLock = !terrainLock;
        var btn = document.getElementById('btn-terrain-lock');
        btn.classList.toggle('active', terrainLock);
        btn.textContent = terrainLock ? '🔒 Ground-Lock: ON' : '🔓 Ground-Lock: OFF';
    }

    function setCoordMode(mode) {
        coordDisplayMode = mode;
        document.getElementById('btn-coord-2d').classList.toggle('active', mode==='2d');
        document.getElementById('btn-coord-3d').classList.toggle('active', mode==='3d');
        document.getElementById('btn-coord-loc').classList.toggle('active', mode==='loc');
    }

    function formatCoords(lx, ly, z) {
        if (coordDisplayMode === '3d') {
            return `3D Space -> X: ${Math.round(lx)} | Y (Height): ${Math.round(z)} | Z: ${Math.round(ly)}`;
        } else if (coordDisplayMode === 'loc') {
            return `Global /loc -> X: ${Math.round(_OFFX + lx)} | Y: ${Math.round(_OFFY + ly)} | Z: ${Math.round(z)}`;
        } else {
            return `2D Local -> lx: ${Math.round(lx)} | ly: ${Math.round(ly)} | Z: ${Math.round(z)}`;
        }
    }

    function _p(v,t){document.getElementById('v3d-load-fill').style.width=v+'%';if(t)document.getElementById('v3d-load-txt').textContent=t;}
_p(5,'⚗ Loading Three.js…');
    var sc=document.createElement('script');sc.src='https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
    sc.onload=function(){
        _p(15,'⚗ Loading Gizmo Controls…');
        var sc2=document.createElement('script');
        sc2.src='https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/TransformControls.js';
        sc2.onload=function(){_p(20,'⚗ Building scene…');setTimeout(_build,150);};
        sc2.onerror=function(){_p(20,'⚗ Building scene…');setTimeout(_build,150);};
        document.head.appendChild(sc2);
    };
    sc.onerror=function(){_p(0,'❌ Three.js error');};
    document.head.appendChild(sc);

function createTicksGeometry(height) {
    var points = [];
    points.push(new THREE.Vector3(0, 4, 0));
    points.push(new THREE.Vector3(0, height, 0));
    for (var y = 500; y < height; y += 500) {
        points.push(new THREE.Vector3(-20, y, 0));
        points.push(new THREE.Vector3(20, y, 0));
    }
    return new THREE.BufferGeometry().setFromPoints(points);
}

function _build(){
        var wrap=document.getElementById('v3d-wrap-inner');
        var canvas=document.getElementById('v3d-canvas');
        var W=wrap.offsetWidth, H=wrap.offsetHeight;
        canvas.width=W; canvas.height=H;
        
        var r=new THREE.WebGLRenderer({canvas:canvas,antialias:true});
        r.setPixelRatio(window.devicePixelRatio||1); r.setSize(W,H); r.setClearColor(0x050507);
        r.shadowMap.enabled = true;
        r.shadowMap.type = THREE.PCFSoftShadowMap;
        
        var scene=new THREE.Scene(); scene.background=new THREE.Color(0x050507);
        var cam=new THREE.PerspectiveCamera(55,W/H,10,500000);
        cam.position.set(_Z/2, 25000, _Z/2 * 1.8); cam.lookAt(_Z/2,0,_Z/2);
        
        scene.add(new THREE.AmbientLight(0xffffff,0.9));
        var sun=new THREE.DirectionalLight(0xffffff,0.7); 
        sun.position.set(_Z*.3,40000,_Z*.2); 
        sun.castShadow = true;
        sun.shadow.mapSize.width = 2048;
        sun.shadow.mapSize.height = 2048;
        sun.shadow.camera.near = 1000;
        sun.shadow.camera.far = 120000;
        var shadowDist = 35000;
        sun.shadow.camera.left = -shadowDist;
        sun.shadow.camera.right = shadowDist;
        sun.shadow.camera.top = shadowDist;
        sun.shadow.camera.bottom = -shadowDist;
        scene.add(sun);

        // Height slicer radar ring (hidden until dragging)
        var radarMat = new THREE.MeshBasicMaterial({color: 0x50c878, side: THREE.DoubleSide, transparent: true, opacity: 0.15});
        var radarRing = new THREE.Mesh(new THREE.RingGeometry(15, 2500, 32), radarMat);
        radarRing.rotation.x = -Math.PI / 2;
        radarRing.visible = false;
        scene.add(radarRing);

        _p(35,'⚗ Loading map…');
        new THREE.TextureLoader().load(_I,function(tex){
            _p(55,'⚗ Terrain…');
            tex.anisotropy=r.capabilities.getMaxAnisotropy();
            var plane=new THREE.Mesh(new THREE.PlaneGeometry(_Z,_Z),new THREE.MeshStandardMaterial({map:tex,side:THREE.DoubleSide,roughness:0.8,metalness:0.1}));
            plane.rotation.x=-Math.PI/2; plane.position.set(_Z/2,0,_Z/2); 
            plane.receiveShadow = true;
            scene.add(plane);
            
            var grid=new THREE.GridHelper(_Z,64,0x111122,0x0a0a18); grid.position.set(_Z/2,2,_Z/2); scene.add(grid);

            _p(70,'⚗ Mobs…');
            var mm=[], gs=new THREE.SphereGeometry(65,8,6), gc=new THREE.ConeGeometry(55,140,6);
            var mn=new THREE.MeshPhongMaterial({color:0x4a8fd4,emissive:0x112244}), ml=new THREE.MeshPhongMaterial({color:0xff8800,emissive:0x331500}), mf=new THREE.MeshPhongMaterial({color:0xffcc44,emissive:0x332200});
            var lineMat=new THREE.LineBasicMaterial({color:0x555577, transparent:true, opacity:0.5});
            var pinMat=new THREE.MeshBasicMaterial({color:0x555577, transparent:true, opacity:0.6});

            var visibleMobs = _M.filter(m => m.lx > -1000 && m.lx < _Z+1000 && m.ly > -1000 && m.ly < _Z+1000);
            document.getElementById('v3d-bar-title').innerHTML += ` <span class="acp-s-8c635ec6">(${visibleMobs.length} visible)</span>`;
            
           _p(70,'⚗ Mobs…');
            var mm=[], gs=new THREE.SphereGeometry(65,8,6), gc=new THREE.ConeGeometry(55,140,6);
            var mn=new THREE.MeshStandardMaterial({color:0x4a8fd4,roughness:0.4}), ml=new THREE.MeshStandardMaterial({color:0xff8800,roughness:0.4}), mf=new THREE.MeshStandardMaterial({color:0xffcc44,roughness:0.3});
            var lineMat=new THREE.LineBasicMaterial({color:0x555577, transparent:true, opacity:0.6});
            var pinMat=new THREE.MeshBasicMaterial({color:0x111115, transparent:true, opacity:0.6});

            var visibleMobs = _M.filter(m => m.lx > -1000 && m.lx < _Z+1000 && m.ly > -1000 && m.ly < _Z+1000);
            document.getElementById('v3d-bar-title').innerHTML += ` <span class="acp-s-8c635ec6">(${visibleMobs.length} visible)</span>`;
            
            _p(75,'⚗ Placing '+visibleMobs.length+' Mobs…');
            visibleMobs.forEach(function(mob){
                var il=mob.PackageID==='mob_lab', iv=_F.indexOf(String(mob.Mob_ID))>=0;
                var mesh=new THREE.Mesh(il||iv?gc:gs,(iv?mf:il?ml:mn).clone());
                
                var mobHeight = mob.Z || 80;
                mesh.position.set(mob.lx, mobHeight, mob.ly);
                mesh.castShadow = true;
                mesh.receiveShadow = true;
                mesh.userData={mob:mob}; scene.add(mesh); mm.push(mesh);
                
                var pin = new THREE.Mesh(new THREE.CircleGeometry(40, 16), pinMat.clone());
                pin.rotation.x = -Math.PI/2;
                pin.position.set(mob.lx, 4, mob.ly);
                
                var hScale = 1 + (mobHeight / 2500);
                var hOpacity = Math.max(0.15, 0.7 - (mobHeight / 5000));
                pin.scale.set(hScale, hScale, hScale);
                pin.material.opacity = hOpacity;
                
                scene.add(pin);
                mesh.userData.baseDot = pin;

                var geoLine = createTicksGeometry(mobHeight);
                var line = new THREE.LineSegments(geoLine, lineMat.clone());
                line.position.set(mob.lx, 0, mob.ly);
                scene.add(line);
                mesh.userData.dropLine = line;

                if (mob.AggroRange && mob.AggroRange > 0) {
                    var aggroGeo = new THREE.CylinderGeometry(mob.AggroRange, mob.AggroRange, 200, 16, 1, true);
                    var aggroMat = new THREE.MeshBasicMaterial({color: 0xe07070, transparent: true, opacity: 0.12, side: THREE.DoubleSide, depthWrite: false});
                    var aggroCyl = new THREE.Mesh(aggroGeo, aggroMat);
                    aggroCyl.position.set(mob.lx, mobHeight, mob.ly);
                    scene.add(aggroCyl);
                    mesh.userData.aggroCyl = aggroCyl;
                }
            });

           _C.forEach(function(p){var m=new THREE.Mesh(new THREE.CylinderGeometry(25,25,280,8),new THREE.MeshStandardMaterial({color:0xc5a059,roughness:0.5}));m.position.set(p.lx,140,p.ly);m.castShadow=true;scene.add(m);});

            fetch('acp.php?s=mob_editor&action=get_patrol_paths&zone_id=' + _ZID)
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.success && d.paths) {
                        var pathMat = new THREE.LineDashedMaterial({color: 0x50c878, dashSize: 100, gapSize: 50, linewidth: 2});
                        var nodeGeo = new THREE.SphereGeometry(20, 8, 8);
                        var nodeMat = new THREE.MeshBasicMaterial({color: 0x50c878});
                        d.paths.forEach(function(p){
                            if (!p.points || p.points.length < 2) return;
                            var pts = [];
                            p.points.forEach(function(pt){
                                var px = pt.lx || pt.x || 0;
                                var py = pt.ly || pt.y || 0;
                                var pz = pt.z || 100;
                                var vec = new THREE.Vector3(px, pz, py);
                                pts.push(vec);
                                var node = new THREE.Mesh(nodeGeo, nodeMat);
                                node.position.copy(vec);
                                scene.add(node);
                            });
                            var pathGeo = new THREE.BufferGeometry().setFromPoints(pts);
                            var line = new THREE.Line(pathGeo, pathMat);
                            line.computeLineDistances();
                            scene.add(line);
                        });
                    }
                }).catch(function(e){ console.error("3D Patrol path error:", e); });

            _p(100,'✓ Done!');setTimeout(function(){document.getElementById('v3d-load').classList.add('h');},400);

            var transformControl = null;
            var isGizmoDragging = false;
            if (typeof THREE.TransformControls !== 'undefined') {
                transformControl = new THREE.TransformControls(cam, r.domElement);
                transformControl.setMode('translate');
                scene.add(transformControl);

                transformControl.addEventListener('change', function() {
                    if (transformControl.object) {
                        var m = transformControl.object;
                        var curH = Math.max(10, m.position.y);
                        var hScale = 1 + (curH / 2500);
                        var hOpacity = Math.max(0.15, 0.7 - (curH / 5000));
                        var bd = m.userData.baseDot;
                        if (bd) {
                            bd.position.x = m.position.x;
                            bd.position.z = m.position.z;
                            bd.scale.set(hScale, hScale, hScale);
                            bd.material.opacity = hOpacity;
                        }
                        var dl = m.userData.dropLine;
                        if (dl) {
                            dl.geometry.dispose();
                            dl.geometry = createTicksGeometry(curH);
                            dl.position.set(m.position.x, 0, m.position.z);
                        }
                        var ac = m.userData.aggroCyl;
                        if (ac) {
                            ac.position.set(m.position.x, curH, m.position.z);
                        }
                        coText.textContent = formatCoords(m.position.x, m.position.z, m.position.y);
                    }
                });

                transformControl.addEventListener('dragging-changed', function(event) {
                    isGizmoDragging = event.value;
                    if (!event.value && transformControl.object) {
                        var m = transformControl.object;
                        var mob = m.userData.mob;
                        var finalZ = m.position.y;
                        
                        var saveGizmoPos = function(fz) {
                            mob.lx = m.position.x;
                            mob.ly = m.position.z;
                            mob.Z = fz;
                            m.position.y = fz;

                            var fd = new URLSearchParams();
                            fd.append('mob_id', mob.Mob_ID);
                            fd.append('gx', Math.round(_OFFX + mob.lx));
                            fd.append('gy', Math.round(_OFFY + mob.ly));
                            fd.append('z', Math.round(mob.Z));
                            fd.append('csrf_token', _CSRF);

                            fetch('acp.php?s=mob_editor&action=update_mob_pos&zone_id=' + _ZID, { method:'POST', body:fd })
                                .then(r=>r.json()).then(d=>{
                                    if(d.success) {
                                        var st = document.getElementById('v3d-save-status');
                                        st.classList.add('show');
                                        setTimeout(()=>st.classList.remove('show'), 2500);
                                    }
                                }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
                        };

                        if (terrainLock) {
                            fetch('acp.php?s=mob_editor&action=get_nearest_z&zone_id=' + _ZID + '&lx=' + Math.round(m.position.x) + '&ly=' + Math.round(m.position.z))
                                .then(r=>r.json())
                                .then(d=>{
                                    var resolvedZ = (d.success && d.z) ? d.z : finalZ;
                                    saveGizmoPos(resolvedZ);
                                }).catch(function(){ saveGizmoPos(finalZ); });
                        } else {
                            saveGizmoPos(finalZ);
                        }
                    }
                });
            }

            var slicerGrid = new THREE.GridHelper(_Z, 64, 0x50c878, 0x1a3a2a);
            slicerGrid.position.set(_Z/2, 0, _Z/2);
            slicerGrid.visible = false;
            scene.add(slicerGrid);

            var dragCam=false, rd=false, lm={x:0,y:0}, sph={th:0.3,ph:0.75,r:_Z*0.7}, tgt=new THREE.Vector3(_Z/2,0,_Z/2);
            var tgtTarget = tgt.clone();
            function uc(){cam.position.set(tgt.x+sph.r*Math.sin(sph.ph)*Math.sin(sph.th),tgt.y+sph.r*Math.cos(sph.ph),tgt.z+sph.r*Math.sin(sph.ph)*Math.cos(sph.th));cam.lookAt(tgt);} uc();

            var draggingMob=null, dragPlane=new THREE.Plane(), dragOffset=new THREE.Vector3(), isShift=false;
            var tt=document.getElementById('v3d-tt'), coText=document.getElementById('v3d-co-text');

            window.addEventListener('keydown', e => { if(e.key==='Shift') isShift=true; });
            window.addEventListener('keyup', e => { if(e.key==='Shift') isShift=false; });

            canvas.addEventListener('dblclick', function(e) {
                var rc = canvas.getBoundingClientRect();
                var mouse = new THREE.Vector2(((e.clientX - rc.left) / rc.width) * 2 - 1, -((e.clientY - rc.top) / rc.height) * 2 + 1);
                var ray = new THREE.Raycaster();
                ray.setFromCamera(mouse, cam);
                var hits = ray.intersectObjects(mm);
                if (hits.length) {
                    var targetMob = hits[0].object;
                    tgtTarget.copy(targetMob.position);
                    slicerGrid.position.y = targetMob.position.y;
                    slicerGrid.visible = true;
                }
            });

            canvas.addEventListener('mousedown',function(e){
                if (isGizmoDragging) return;

                var rc=canvas.getBoundingClientRect(), mouse=new THREE.Vector2(((e.clientX-rc.left)/rc.width)*2-1,-((e.clientY-rc.top)/rc.height)*2+1);
                var ray=new THREE.Raycaster(); ray.setFromCamera(mouse,cam);

                if (e.button === 0) { 
                    var hits = ray.intersectObjects(mm);
                    if (hits.length) {
                        draggingMob = hits[0].object;
                        isShift = e.shiftKey;

                        if (transformControl) {
                            transformControl.attach(draggingMob);
                        }
                        slicerGrid.position.y = draggingMob.position.y;
                        slicerGrid.visible = true;

                        // Enable grab visuals
                        draggingMob.userData.dropLine.material.color.setHex(0x50c878);
                        draggingMob.userData.dropLine.material.opacity = 1;
                        draggingMob.userData.baseDot.material.color.setHex(0x50c878);
                        draggingMob.userData.baseDot.material.opacity = 1;
                        radarRing.visible = true;

                        if (isShift) {
                            var camDir = new THREE.Vector3(); cam.getWorldDirection(camDir); camDir.y=0; camDir.normalize();
                            dragPlane.setFromNormalAndCoplanarPoint(camDir, draggingMob.position);
                        } else {
                            dragPlane.setFromNormalAndCoplanarPoint(new THREE.Vector3(0,1,0), draggingMob.position);
                        }
                        var planeHit = new THREE.Vector3();
                        ray.ray.intersectPlane(dragPlane, planeHit);
                        dragOffset.copy(draggingMob.position).sub(planeHit);
                        return; 
                    }
                }

                dragCam=true; rd=e.button===2; lm={x:e.clientX,y:e.clientY};
            });

            canvas.addEventListener('mousemove',function(e){
                var rc=canvas.getBoundingClientRect(), mouse=new THREE.Vector2(((e.clientX-rc.left)/rc.width)*2-1,-((e.clientY-rc.top)/rc.height)*2+1);
                var ray=new THREE.Raycaster(); ray.setFromCamera(mouse,cam);

                if (draggingMob) {
                    var planeHit = new THREE.Vector3();
                    ray.ray.intersectPlane(dragPlane, planeHit);
                    if (planeHit) {
                        if (isShift) { draggingMob.position.y = (planeHit.add(dragOffset)).y; } 
                        else { var np = planeHit.add(dragOffset); draggingMob.position.x = np.x; draggingMob.position.z = np.z; }
                    }

                    radarRing.position.set(draggingMob.position.x, draggingMob.position.y, draggingMob.position.z);
                    
                    var curH = Math.max(10, draggingMob.position.y);
                    var hScale = 1 + (curH / 2500);
                    var hOpacity = Math.max(0.15, 0.7 - (curH / 5000));

                    var bd = draggingMob.userData.baseDot;
                    if(bd) { 
                        bd.position.x = draggingMob.position.x; 
                        bd.position.z = draggingMob.position.z; 
                        bd.scale.set(hScale, hScale, hScale);
                        bd.material.opacity = hOpacity;
                    }

                    var dl = draggingMob.userData.dropLine;
                    if (dl) {
                        dl.geometry.dispose();
                        dl.geometry = createTicksGeometry(curH);
                        dl.position.set(draggingMob.position.x, 0, draggingMob.position.z);
                    }

                    coText.textContent = formatCoords(draggingMob.position.x, draggingMob.position.z, draggingMob.position.y);
                    return;
                }

                var ph=ray.intersectObject(plane);
                if(ph.length) coText.textContent = formatCoords(ph[0].point.x, ph[0].point.z, 0);

                var hits=ray.intersectObjects(mm);
                if(hits.length){
                    var mob=hits[0].object.userData.mob;
                    tt.style.display='block';tt.style.left=(e.clientX-rc.left+14)+'px';tt.style.top=(e.clientY-rc.top-10)+'px';
                    tt.innerHTML='<b>'+esc(mob.Name)+'</b><br>Lv'+mob.Level+' · Model #'+mob.Model+'<br><span class="acp-s-10c18496">'+formatCoords(mob.lx, mob.ly, mob.Z || 0)+'</span>';
                }else{ tt.style.display='none'; }

                if(!dragCam || isGizmoDragging)return; var dx=e.clientX-lm.x, dy=e.clientY-lm.y; lm={x:e.clientX,y:e.clientY};
                tgtTarget.copy(tgt);
                if(rd){ var spd=sph.r*0.001, rv=new THREE.Vector3(); rv.crossVectors(cam.getWorldDirection(new THREE.Vector3()),new THREE.Vector3(0,1,0)).normalize(); tgt.addScaledVector(rv,-dx*spd); tgt.y+=dy*spd; tgtTarget.copy(tgt); }
                else{ sph.th-=dx*0.005; sph.ph=Math.max(0.05,Math.min(Math.PI*0.44,sph.ph+dy*0.005)); }
                uc();
            });

            canvas.addEventListener('mouseup',function(e){
                if (draggingMob) {
                    var mob = draggingMob.userData.mob;
                    var targetMesh = draggingMob;
                    
                    targetMesh.userData.dropLine.material.color.setHex(0x555577);
                    targetMesh.userData.dropLine.material.opacity = 0.6;
                    targetMesh.userData.baseDot.material.color.setHex(0x111115);
                    radarRing.visible = false;

                    var savePosition = function(finalZ) {
                        mob.lx = targetMesh.position.x; 
                        mob.ly = targetMesh.position.z; 
                        mob.Z = finalZ;
                        targetMesh.position.y = finalZ;

                        var curH = Math.max(10, finalZ);
                        var hScale = 1 + (curH / 2500);
                        var hOpacity = Math.max(0.15, 0.7 - (curH / 5000));
                        var bd = targetMesh.userData.baseDot;
                        if(bd) { bd.scale.set(hScale, hScale, hScale); bd.material.opacity = hOpacity; }
                        var dl = targetMesh.userData.dropLine;
                        if(dl) { dl.geometry.dispose(); dl.geometry = createTicksGeometry(curH); dl.position.set(mob.lx, 0, mob.ly); }

                        var fd = new URLSearchParams();
                        fd.append('mob_id', mob.Mob_ID);
                        fd.append('gx', Math.round(_OFFX + mob.lx));
                        fd.append('gy', Math.round(_OFFY + mob.ly));
                        fd.append('z', Math.round(mob.Z));
                        fd.append('csrf_token', _CSRF);

                        fetch('acp.php?s=mob_editor&action=update_mob_pos&zone_id=' + _ZID, { method:'POST', body:fd })
                            .then(r=>r.json()).then(d=>{
                                if(d.success) {
                                    var st = document.getElementById('v3d-save-status');
                                    st.classList.add('show');
                                    setTimeout(()=>st.classList.remove('show'), 2500);
                                }
                            }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
                    };

                    if (terrainLock && !isShift) {
                        fetch('acp.php?s=mob_editor&action=get_nearest_z&zone_id=' + _ZID + '&lx=' + Math.round(targetMesh.position.x) + '&ly=' + Math.round(targetMesh.position.z))
                            .then(r=>r.json())
                            .then(d=>{
                                var resolvedZ = (d.success && d.z) ? d.z : targetMesh.position.y;
                                savePosition(resolvedZ);
                            }).catch(function(){ savePosition(targetMesh.position.y); });
                    } else {
                        savePosition(targetMesh.position.y);
                    }

                    draggingMob = null;
                }
                dragCam=false; rd=false;
            });
            
            canvas.addEventListener('mouseleave',function(){dragCam=false;});
            canvas.addEventListener('wheel',function(e){sph.r=Math.max(400,Math.min(_Z*1.6,sph.r+e.deltaY*6));uc();e.preventDefault();},{passive:false});
            canvas.addEventListener('contextmenu',function(e){e.preventDefault();});

            window.addEventListener('resize',function(){var w=wrap.offsetWidth,h=wrap.offsetHeight;cam.aspect=w/h;cam.updateProjectionMatrix();r.setSize(w,h);});
            
            var t=0;(function animate(){
                requestAnimationFrame(animate);
                t+=0.025;
                if (tgt.distanceTo(tgtTarget) > 1) {
                    tgt.lerp(tgtTarget, 0.08);
                    uc();
                }
                mm.forEach(function(m){if(m.userData.mob&&m.userData.mob.PackageID==='mob_lab')m.scale.setScalar(1+Math.sin(t)*0.07);});
                r.render(scene,cam);
            })();
        },undefined,function(){_p(100,'⚠ Map unavailable');document.getElementById('v3d-load').classList.add('h');});
    }
    </script>
    <?php
    return;
}

$ai_active = isset($botSettings)
    && $botSettings->isActive()
    && !empty($botSettings->data['ai_provider'])
    && $botSettings->data['ai_provider'] !== 'none'
    && (!empty($botSettings->data['ai_api_key']) || !empty($botSettings->data['ai_api_key_enc']));

// ── AJAX HANDLER ──────────────────────────────────────────────
if (isset($_GET['action'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'];
    $zoneId = (int)($_GET['zone_id'] ?? 0);

    // ── MODEL IMAGE PROXY ─────────────────────────────────────
    if ($action === 'model_img') {
        $modelId  = max(1, (int)($_GET['model'] ?? 0));
        $localPath = __DIR__ . "/../assets/img/mobs/{$modelId}.jpg";

        if (file_exists($localPath)) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: image/jpeg');
            header('Cache-Control: public, max-age=2592000');
            readfile($localPath);
            exit;
        }
        // Transparent 1x1 fallback
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        exit;
    }

    // ── FAVOURITES ────────────────────────────────────────────
    if ($action === 'get_favourites') {
        $favFile = __DIR__ . '/../assets/data/mob_favourites.json';
        $favs = file_exists($favFile) ? json_decode(file_get_contents($favFile), true) : [];
        echo json_encode(['success' => true, 'favourites' => $favs]);
        exit;
    }
    if ($action === 'toggle_favourite' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $mobId   = trim($_POST['mob_id'] ?? '');
        $favFile = __DIR__ . '/../assets/data/mob_favourites.json';
        $dir     = dirname($favFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $favs = file_exists($favFile) ? json_decode(file_get_contents($favFile), true) : [];
        if (in_array($mobId, $favs)) {
            $favs = array_values(array_diff($favs, [$mobId]));
            $added = false;
        } else {
            $favs[] = $mobId;
            $added = true;
        }
        file_put_contents($favFile, json_encode($favs));
        echo json_encode(['success' => true, 'added' => $added]);
        exit;
    }

    try {
        $zStmt = $db->prepare("SELECT ZoneID, RegionID, Name AS ZoneName, OffsetX, OffsetY, Width, Height FROM zones WHERE ZoneID = ? LIMIT 1");
        $zStmt->execute([$zoneId]);
        $zone = $zStmt->fetch(PDO::FETCH_ASSOC);
        if (!$zone && !in_array($action, ['get_similar_mobs', 'search_npc_templates'])) {
            echo json_encode(['success'=>false,'error'=>"Zone $zoneId not found"]);
            exit;
        }

        $offsetX  = (int)($zone['OffsetX'] ?? 0);
        $offsetY  = (int)($zone['OffsetY'] ?? 0);
        $region   = (int)($zone['RegionID'] ?? 0);
        $tileSize = 8192;
        $minGX    = $offsetX * $tileSize;
        $minGY    = $offsetY * $tileSize;
        $maxGX    = $minGX + ($tileSize * 8);
        $maxGY    = $minGY + ($tileSize * 8);

        if ($action === 'get_mobs') {
            $stmt = $db->prepare(
                "SELECT Mob_ID, Name, X, Y, Z, Level, Model, Race, PackageID, AggroLevel, AggroRange
                 FROM mob WHERE Realm = 0 AND Region = ? ORDER BY Name"
            );
            $stmt->execute([$region]);
            $mobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($mobs as &$mob) {
                $mob['lx'] = (int)$mob['X'] - $minGX;
                $mob['ly'] = (int)$mob['Y'] - $minGY;
                unset($mob['X'], $mob['Y']);
            }
            unset($mob);
            echo json_encode(['success'=>true,'zone'=>$zone,'mobs'=>$mobs,'count'=>count($mobs)], JSON_NUMERIC_CHECK);
            exit;
        }

        if ($action === 'get_mob_detail') {
            $mobId = trim($_GET['mob_id'] ?? '');
            $stmt = $db->prepare(
                "SELECT mob.Mob_ID, mob.Name, mob.Level, mob.Model, mob.Race, mob.Size, mob.Speed,
                        mob.Strength, mob.Constitution, mob.Dexterity, mob.Quickness, mob.Intelligence, mob.Piety, mob.Charisma, mob.Empathy,
                        mob.AggroLevel, mob.AggroRange, mob.RespawnInterval, mob.Brain, mob.ClassType, mob.PackageID, mob.Z, mob.Region,
                        mob.NPCTemplateID, npctemplate.Name AS NPCTemplateName,
                        mob.Suffix, mob.Guild AS GuildName, mob.ExamineArticle, mob.MessageArticle, mob.Realm,
                        mob.MaxDistance, mob.RoamingRange, mob.MeleeDamageType, mob.BodyType,
                        mob.Gender, mob.Flags
                 FROM mob
                 LEFT JOIN npctemplate ON npctemplate.TemplateId = mob.NPCTemplateID
                 WHERE mob.Mob_ID = ? LIMIT 1"
            );
            $stmt->execute([$mobId]);
            $mob = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mob) { echo json_encode(['success'=>false,'error'=>'Mob not found']); exit; }

            $lStmt = $db->prepare(
                "SELECT mxl.MobXLootTemplate_ID, mxl.LootTemplateName, mxl.DropCount,
                        lt.ItemTemplateID, lt.Chance, lt.Count, lt.LootTemplate_ID
                 FROM mobxloottemplate mxl
                 LEFT JOIN loottemplate lt ON lt.TemplateName = mxl.LootTemplateName
                 WHERE mxl.MobName = ?
                 ORDER BY mxl.LootTemplateName, lt.Chance DESC"
            );
            $lStmt->execute([$mob['Name']]);
            $drops = $lStmt->fetchAll(PDO::FETCH_ASSOC);

            // Spawn% in Region
            $totalStmt = $db->prepare("SELECT COUNT(*) FROM mob WHERE Region = ?");
            $totalStmt->execute([$region]);
            $totalMobs = (int)$totalStmt->fetchColumn();

            $sameModelStmt = $db->prepare("SELECT COUNT(*) FROM mob WHERE Model = ? AND Region = ?");
            $sameModelStmt->execute([$mob['Model'], $region]);
            $sameModel = (int)$sameModelStmt->fetchColumn();

            $spawnPct = $totalMobs > 0 ? round(($sameModel / $totalMobs) * 100, 1) : 0;

            echo json_encode([
                'success'   => true,
                'mob'       => $mob,
                'drops'     => $drops,
                'spawn_pct' => $spawnPct,
                'total_mobs'=> $totalMobs,
                'same_model'=> $sameModel,
            ], JSON_NUMERIC_CHECK);
            exit;
        }

        if ($action === 'get_similar_mobs') {
            $mobId  = trim($_GET['mob_id'] ?? '');
            $level  = (int)($_GET['level'] ?? 1);
            $region = (int)($_GET['region'] ?? 0);
            $stmt = $db->prepare(
                "SELECT Mob_ID, Name, Level, Model, Race, Strength, Constitution,
                        Dexterity, Quickness, AggroLevel, AggroRange, RespawnInterval
                 FROM mob WHERE Region = ? AND Level BETWEEN ? AND ? AND Mob_ID != ?
                 ORDER BY ABS(Level - ?) ASC LIMIT 8"
            );
            $stmt->execute([$region, max(1,$level-5), $level+5, $mobId, $level]);
            echo json_encode(['success'=>true,'mobs'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        if ($action === 'search_npc_templates') {
            $q = trim($_GET['q'] ?? '');
            if ($q !== '') {
                $stmt = $db->prepare(
                    "SELECT TemplateId, Name, ClassType, Level FROM npctemplate
                     WHERE Name LIKE ? ORDER BY Name ASC LIMIT 20"
                );
                $stmt->execute(['%'.$q.'%']);
            } else {
                $stmt = $db->prepare("SELECT TemplateId, Name, ClassType, Level FROM npctemplate ORDER BY Name ASC LIMIT 20");
                $stmt->execute();
            }
            echo json_encode(['success'=>true,'templates'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_NUMERIC_CHECK);
            exit;
        }

        if ($action === 'get_heatmap') {
            $stmt = $db->prepare("SELECT (X - ?) AS lx, (Y - ?) AS ly, Level FROM mob WHERE Realm = 0 AND Region = ?");
            $stmt->execute([$minGX, $minGY, $region]);
            echo json_encode(['success'=>true,'points'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_NUMERIC_CHECK);
            exit;
        }

		if ($action === 'update_mob_pos' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $mobId = trim($_POST['mob_id'] ?? '');
            $gx    = (int)($_POST['gx'] ?? 0);
            $gy    = (int)($_POST['gy'] ?? 0);
            $z     = (int)($_POST['z'] ?? 0);
            if ($mobId === '') { echo json_encode(['success'=>false,'error'=>'No ID']); exit; }
            $stmt = $db->prepare("UPDATE mob SET X=?, Y=?, Z=?, LastTimeRowUpdated=NOW() WHERE Mob_ID=?");
            echo json_encode(['success'=>$stmt->execute([$gx, $gy, $z, $mobId])]);
            exit;
        }
        if ($action === 'update_mob' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $mobId = trim($_POST['mob_id'] ?? '');
            $name  = trim($_POST['name']   ?? '');
            $level = max(1, min(75,  (int)($_POST['level']  ?? 1)));
            $model = max(1,          (int)($_POST['model']  ?? 1));
            $size  = max(1, min(255, (int)($_POST['size']   ?? 50)));
            $aggro = max(0, min(100, (int)($_POST['aggro_level'] ?? 0)));
            $range = max(0,          (int)($_POST['aggro_range'] ?? 500));
            $resp  = max(1,          (int)($_POST['respawn'] ?? 120));
            $str   = max(0, min(500, (int)($_POST['str'] ?? 30)));
            $con   = max(0, min(500, (int)($_POST['con'] ?? 30)));
            $dex   = max(0, min(500, (int)($_POST['dex'] ?? 30)));
            $qui   = max(0, min(500, (int)($_POST['qui'] ?? 30)));
            $int   = max(0, min(500, (int)($_POST['int'] ?? 30)));
            $pie   = max(0, min(500, (int)($_POST['pie'] ?? 30)));
            $cha   = max(0, min(500, (int)($_POST['cha'] ?? 30)));
            $emp   = max(0, min(500, (int)($_POST['emp'] ?? 30)));
            if ($name === '') { echo json_encode(['success'=>false,'error'=>'Name empty']); exit; }

            $npcTplRaw = trim($_POST['npctemplate_id'] ?? '');
            $npcTplId  = -1;
            if ($npcTplRaw !== '' && (int)$npcTplRaw > 0) {
                $tplChk = $db->prepare("SELECT TemplateId FROM npctemplate WHERE TemplateId=? LIMIT 1");
                $tplChk->execute([(int)$npcTplRaw]);
                if (!$tplChk->fetchColumn()) { echo json_encode(['success'=>false,'error'=>'NPC template not found']); exit; }
                $npcTplId = (int)$npcTplRaw;
            }

            $suffix     = trim(mb_substr($_POST['suffix']      ?? '', 0, 255));
            $guildName  = trim(mb_substr($_POST['guild_name']  ?? '', 0, 255));
            $examArt    = trim(mb_substr($_POST['examine_article'] ?? '', 0, 255));
            $msgArt     = trim(mb_substr($_POST['message_article'] ?? '', 0, 255));
            $realm      = max(0, min(3,   (int)($_POST['realm']       ?? 0)));
            $race       = max(0,          (int)($_POST['race']        ?? 0));
            $speed      = max(0,          (int)($_POST['speed']       ?? 0));
            $maxDist    = (int)($_POST['max_distance'] ?? 0);
            $roaming    = max(-1,         (int)($_POST['roaming_range'] ?? -1));
            $dmgType    = max(0, min(3,   (int)($_POST['damage_type']  ?? 0)));
            $bodyType   = max(0,          (int)($_POST['body_type']   ?? 0));
            $gender     = max(0, min(2,   (int)($_POST['gender']      ?? 0)));
            $flags      = max(0,          (int)($_POST['flags']       ?? 0));

            $stmt = $db->prepare(
                "UPDATE mob SET Name=?,Level=?,Model=?,Size=?,AggroLevel=?,AggroRange=?,
                 RespawnInterval=?,Strength=?,Constitution=?,Dexterity=?,Quickness=?,
                 Intelligence=?,Piety=?,Charisma=?,Empathy=?,
                 Suffix=?,Guild=?,ExamineArticle=?,MessageArticle=?,Realm=?,Race=?,Speed=?,
                 MaxDistance=?,RoamingRange=?,MeleeDamageType=?,BodyType=?,Gender=?,Flags=?,
                 NPCTemplateID=?,LastTimeRowUpdated=NOW() WHERE Mob_ID=?"
            );
            echo json_encode(['success'=>$stmt->execute([
                $name,$level,$model,$size,$aggro,$range,$resp,$str,$con,$dex,$qui,
                $int,$pie,$cha,$emp,
                $suffix,$guildName,$examArt,$msgArt,$realm,$race,$speed,
                $maxDist,$roaming,$dmgType,$bodyType,$gender,$flags,
                $npcTplId,$mobId,
            ])]);
            exit;
        }

        if ($action === 'add_drop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $mobName      = trim($_POST['mob_name']      ?? '');
            $itemTemplate = trim($_POST['item_template'] ?? '');
            $chance       = max(1, min(100, (int)($_POST['chance'] ?? 10)));
            $count        = max(1,          (int)($_POST['count']  ?? 1));
            if ($mobName === '' || $itemTemplate === '') { echo json_encode(['success'=>false,'error'=>'Required fields empty']); exit; }
            $db->prepare("INSERT IGNORE INTO mobxloottemplate (MobName,LootTemplateName,DropCount) VALUES (?,?,1)")
               ->execute([$mobName, $mobName]);
            $ok = $db->prepare("INSERT INTO loottemplate (TemplateName,ItemTemplateID,Chance,Count) VALUES (?,?,?,?)")
                     ->execute([$mobName, $itemTemplate, $chance, $count]);
            echo json_encode(['success'=>$ok,'id'=>$db->lastInsertId()]);
            exit;
        }

        if ($action === 'delete_drop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $ok = $db->prepare("DELETE FROM loottemplate WHERE LootTemplate_ID=?")->execute([trim($_POST['loot_id']??'')]);
            echo json_encode(['success'=>$ok]);
            exit;
        }

        if ($action === 'add_mob' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $name  = trim($_POST['name']  ?? '');
            $level = max(1,min(75,(int)($_POST['level']??5)));
            $model = max(1,       (int)($_POST['model']??408));
            $leafX = (int)($_POST['x'] ?? 0);
            $leafY = (int)($_POST['y'] ?? 0);
            $zHint = (int)($_POST['z_hint'] ?? 0);
            if ($name === '') { echo json_encode(['success'=>false,'error'=>'Name empty']); exit; }
            $globalX = $minGX + $leafX;
            $globalY = $minGY + $leafY;

            // Z-value logic: 1. Manual hint, 2. otherwise the central fallback chain
            // (Calibration → TerrainService → Median → Zone default)
            if ($zHint > 100) {
                $globalZ = $zHint;
                $zSource = 'manual_hint';
            } else {
                $ground  = resolveGroundZ($db, $region, $zoneId, $globalX, $globalY, $leafX, $leafY);
                $globalZ = $ground['z'];
                $zSource = $ground['source'];
            }

            $mobId = 'mob_lab_' . bin2hex(random_bytes(8));
            $stmt = $db->prepare(
                "INSERT INTO mob (Mob_ID,Name,X,Y,Z,Region,Realm,Level,Model,ClassType,Brain,Speed,Size,
                 RespawnInterval,Constitution,Dexterity,Strength,Quickness,Intelligence,Piety,Charisma,
                 Empathy,AggroLevel,AggroRange,MeleeDamageType,BodyType,Flags,PackageID,LastTimeRowUpdated)
                 VALUES (?,?,?,?,?,?,0,?,?,'DOL.GS.GameNPC','DOL.AI.Brain.StandardMobBrain',
                 200,50,120,30,30,30,30,30,30,30,30,0,500,2,0,0,'mob_lab',NOW())"
            );
            $ok = $stmt->execute([$mobId,$name,$globalX,$globalY,$globalZ,$region,$level,$model]);
            echo json_encode(['success'=>$ok,'id'=>$mobId,'name'=>$name,'z_used'=>$globalZ,'z_source'=>$zSource]);
            exit;
        }

        if ($action === 'delete_mob' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $ok = $db->prepare("DELETE FROM mob WHERE Mob_ID=? AND PackageID='mob_lab'")->execute([trim($_POST['mob_id']??'')]);
            echo json_encode(['success'=>$ok]);
            exit;
        }

        // ── CALIBRATION ─────────────────────────────────────────
        if ($action === 'get_calibration') {
            $calFile = __DIR__ . "/../assets/data/zone_calibration.json";
            $calData = file_exists($calFile) ? json_decode(file_get_contents($calFile), true) : [];
            $zoneKey = "zone_{$zoneId}";
            echo json_encode(['success'=>true, 'points'=>$calData[$zoneKey] ?? []]);
            exit;
        }

        if ($action === 'add_calibration' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $lx = (int)($_POST['lx'] ?? 0);
            $ly = (int)($_POST['ly'] ?? 0);
            $z  = (int)($_POST['z']  ?? 0);
            $label = trim($_POST['label'] ?? '');
            if ($z < 100) { echo json_encode(['success'=>false,'error'=>'Z too small']); exit; }

            $calFile = __DIR__ . "/../assets/data/zone_calibration.json";
            $dir     = dirname($calFile);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $calData = file_exists($calFile) ? json_decode(file_get_contents($calFile), true) : [];
            $zoneKey = "zone_{$zoneId}";
            if (!isset($calData[$zoneKey])) $calData[$zoneKey] = [];
            $calData[$zoneKey][] = ['lx'=>$lx,'ly'=>$ly,'z'=>$z,'label'=>$label,'ts'=>time()];
            file_put_contents($calFile, json_encode($calData, JSON_PRETTY_PRINT));
            echo json_encode(['success'=>true, 'count'=>count($calData[$zoneKey])]);
            exit;
        }

        if ($action === 'delete_calibration' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $idx     = (int)($_POST['idx'] ?? -1);
            $calFile = __DIR__ . "/../assets/data/zone_calibration.json";
            $calData = file_exists($calFile) ? json_decode(file_get_contents($calFile), true) : [];
            $zoneKey = "zone_{$zoneId}";
            if (isset($calData[$zoneKey][$idx])) {
                array_splice($calData[$zoneKey], $idx, 1);
                file_put_contents($calFile, json_encode($calData, JSON_PRETTY_PRINT));
            }
            echo json_encode(['success'=>true]);
            exit;
        }

        // ── NEAREST Z (for spawn preview) ───────────────────────
        if ($action === 'get_nearest_z') {
            $lx = (int)($_GET['lx'] ?? 0);
            $ly = (int)($_GET['ly'] ?? 0);
            $globalX = $minGX + $lx;
            $globalY = $minGY + $ly;

            $ground = resolveGroundZ($db, $region, $zoneId, $globalX, $globalY, $lx, $ly);

            echo json_encode(['success'=>true,'z'=>$ground['z'],'source'=>$ground['source']]);
            exit;
        }

        // ── ONLINE PLAYERS ────────────────────────────────────
        if ($action === 'get_players') {
            try {
                $stmt = $db->prepare(
                    "SELECT Name, Xpos, Ypos, Zpos, Region, Level, Class
                     FROM dolcharacters
                     WHERE LastPlayed > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                     AND Region = ?
                     ORDER BY Name"
                );
                $stmt->execute([$region]);
                $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($players as &$p) {
                    $p['lx'] = (int)$p['Xpos'] - $minGX;
                    $p['ly'] = (int)$p['Ypos'] - $minGY;
                }
                echo json_encode(['success'=>true,'players'=>$players], JSON_NUMERIC_CHECK);
            } catch(Exception $e) {
                echo json_encode(['success'=>false,'error'=>$e->getMessage(),'players'=>[]]);
            }
            exit;
        }

        // ── PATROL PATHS ──────────────────────────────────────
        if ($action === 'search_patrol_npcs') {
            $q = trim($_GET['q'] ?? '');
            if ($q === '') {
                $stmt = $db->prepare(
                    "SELECT Mob_ID, Name, Level, Realm, Guild, ClassType, PathID
                     FROM mob
                     WHERE Region = ? AND X BETWEEN ? AND ? AND Y BETWEEN ? AND ?
                     ORDER BY (Realm = 0) ASC, Name ASC
                     LIMIT 30"
                );
                $stmt->execute([$region, $minGX, $maxGX, $minGY, $maxGY]);
            } else {
                $like = '%'.$q.'%';
                $prefix = $q.'%';
                $stmt = $db->prepare(
                    "SELECT Mob_ID, Name, Level, Realm, Guild, ClassType, PathID
                     FROM mob
                     WHERE Region = ? AND X BETWEEN ? AND ? AND Y BETWEEN ? AND ?
                       AND (Name LIKE ? OR Mob_ID LIKE ? OR Guild LIKE ?)
                     ORDER BY (Name LIKE ?) DESC, (Realm = 0) ASC, Name ASC
                     LIMIT 30"
                );
                $stmt->execute([$region, $minGX, $maxGX, $minGY, $maxGY, $like, $like, $like, $prefix]);
            }
            $npcs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($npcs as &$npcRow) {
                if (strcasecmp(trim((string)($npcRow['PathID'] ?? '')), 'NULL') === 0) {
                    $npcRow['PathID'] = null;
                }
            }
            unset($npcRow);
            echo json_encode(['success'=>true, 'npcs'=>$npcs]);
            exit;
        }

        if ($action === 'get_patrol_paths') {
            $stmt = $db->prepare(
                "SELECT p.PathID, p.PathType, m.Mob_ID, m.Name, m.Level, m.Realm
                 FROM path p
                 INNER JOIN mob m ON m.PathID = p.PathID
                 WHERE m.Region = ? AND m.X BETWEEN ? AND ? AND m.Y BETWEEN ? AND ?
                 ORDER BY p.PathID, m.Name"
            );
            $stmt->execute([$region, $minGX, $maxGX, $minGY, $maxGY]);

            $paths = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pathId = (string)$row['PathID'];
                if (!isset($paths[$pathId])) {
                    $paths[$pathId] = [
                        'path_id'   => $pathId,
                        'label'     => $pathId,
                        'path_type' => (int)$row['PathType'],
                        'npcs'      => [],
                        'points'    => [],
                    ];
                }
                $paths[$pathId]['npcs'][] = [
                    'mob_id' => (string)$row['Mob_ID'],
                    'name'   => (string)$row['Name'],
                    'level'  => (int)$row['Level'],
                    'realm'  => (int)$row['Realm'],
                ];
            }

            if ($paths) {
                $pointStmt = $db->prepare(
                    "SELECT Step, X, Y, Z, MaxSpeed, WaitTime
                     FROM pathpoints
                     WHERE PathID = ?
                     ORDER BY Step ASC"
                );
                foreach ($paths as $pathId => &$path) {
                    $pointStmt->execute([$pathId]);
                    foreach ($pointStmt->fetchAll(PDO::FETCH_ASSOC) as $point) {
                        $path['points'][] = [
                            'step'      => (int)$point['Step'],
                            'lx'        => (int)$point['X'] - $minGX,
                            'ly'        => (int)$point['Y'] - $minGY,
                            'z'         => (int)$point['Z'],
                            'max_speed' => (int)$point['MaxSpeed'],
                            'wait_time' => (int)$point['WaitTime'],
                        ];
                    }
                }
                unset($path);
            }

            echo json_encode(['success'=>true, 'paths'=>array_values($paths)]);
            exit;
        }

        if ($action === 'save_patrol_path' && $_SERVER['REQUEST_METHOD']==='POST') {
            checkToken($_POST['csrf_token'] ?? '');

            $pathId   = trim($_POST['label'] ?? '');
            $npcId    = trim($_POST['npc_id'] ?? $_POST['mob_id'] ?? '');
            $pathType = (int)($_POST['path_type'] ?? 2);
            $points   = json_decode($_POST['path'] ?? '[]', true);

            if ($pathId === '' || mb_strlen($pathId) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $pathId)) {
                echo json_encode(['success'=>false, 'error'=>'Enter a valid route name (maximum 255 characters).']);
                exit;
            }
            if ($npcId === '') {
                echo json_encode(['success'=>false, 'error'=>'Select an NPC before saving the route.']);
                exit;
            }
            if (!in_array($pathType, [1, 2, 3], true)) {
                echo json_encode(['success'=>false, 'error'=>'Invalid path type.']);
                exit;
            }
            if (!is_array($points) || count($points) < 2 || count($points) > 200) {
                echo json_encode(['success'=>false, 'error'=>'A route requires between 2 and 200 points.']);
                exit;
            }

            $npcStmt = $db->prepare(
                "SELECT Mob_ID, Name, PathID
                 FROM mob
                 WHERE Mob_ID = ? AND Region = ?
                   AND X BETWEEN ? AND ? AND Y BETWEEN ? AND ?
                 LIMIT 1"
            );
            $npcStmt->execute([$npcId, $region, $minGX, $maxGX, $minGY, $maxGY]);
            $npc = $npcStmt->fetch(PDO::FETCH_ASSOC);
            if (!$npc) {
                echo json_encode(['success'=>false, 'error'=>'NPC not found in the selected zone.']);
                exit;
            }
            $existingNpcPath = trim((string)($npc['PathID'] ?? ''));
            if ($existingNpcPath !== '' && strcasecmp($existingNpcPath, 'NULL') !== 0) {
                echo json_encode(['success'=>false, 'error'=>'This NPC already uses route "'.$npc['PathID'].'". Delete or unassign it first.']);
                exit;
            }

            $existsStmt = $db->prepare("SELECT 1 FROM path WHERE PathID = ? LIMIT 1");
            $existsStmt->execute([$pathId]);
            if ($existsStmt->fetchColumn()) {
                echo json_encode(['success'=>false, 'error'=>'A route with this name already exists.']);
                exit;
            }

            $resolvedPoints = [];
            foreach ($points as $point) {
                if (!is_array($point) || !isset($point['lx'], $point['ly']) || !is_numeric($point['lx']) || !is_numeric($point['ly'])) {
                    echo json_encode(['success'=>false, 'error'=>'The route contains an invalid point.']);
                    exit;
                }
                $lx = (int)round((float)$point['lx']);
                $ly = (int)round((float)$point['ly']);
                if ($lx < 0 || $lx > 65536 || $ly < 0 || $ly > 65536) {
                    echo json_encode(['success'=>false, 'error'=>'All route points must be inside the selected zone.']);
                    exit;
                }
                $gx = $minGX + $lx;
                $gy = $minGY + $ly;
                $ground = resolveGroundZ($db, $region, $zoneId, $gx, $gy, $lx, $ly);
                $resolvedPoints[] = ['x'=>$gx, 'y'=>$gy, 'z'=>$ground['z']];
            }

            $db->beginTransaction();
            try {
                $lockNpc = $db->prepare("SELECT PathID, X, Y FROM mob WHERE Mob_ID = ? AND Region = ? FOR UPDATE");
                $lockNpc->execute([$npcId, $region]);
                $lockedNpc = $lockNpc->fetch(PDO::FETCH_ASSOC);
                $lockedPath = trim((string)($lockedNpc['PathID'] ?? ''));
                $npcStillInZone = $lockedNpc
                    && (int)$lockedNpc['X'] >= $minGX && (int)$lockedNpc['X'] <= $maxGX
                    && (int)$lockedNpc['Y'] >= $minGY && (int)$lockedNpc['Y'] <= $maxGY;
                if (!$npcStillInZone || ($lockedPath !== '' && strcasecmp($lockedPath, 'NULL') !== 0)) {
                    throw new RuntimeException('The NPC was changed while the route was being prepared. Reload and try again.');
                }

                $db->prepare(
                    "INSERT INTO path (PathID, PathType, RegionID, LastTimeRowUpdated, Path_ID)
                     VALUES (?, ?, ?, NOW(), ?)"
                )->execute([$pathId, $pathType, $region, mobEditorUuid()]);

                $pointInsert = $db->prepare(
                    "INSERT INTO pathpoints
                     (PathID, Step, X, Y, Z, MaxSpeed, WaitTime, LastTimeRowUpdated, PathPoints_ID)
                     VALUES (?, ?, ?, ?, ?, 1000, 0, NOW(), ?)"
                );
                foreach ($resolvedPoints as $index => $point) {
                    $pointInsert->execute([
                        $pathId,
                        $index + 1,
                        $point['x'],
                        $point['y'],
                        $point['z'],
                        mobEditorUuid(),
                    ]);
                }

                $assign = $db->prepare(
                    "UPDATE mob SET PathID = ?, LastTimeRowUpdated = NOW()
                     WHERE Mob_ID = ? AND Region = ?"
                );
                $assign->execute([$pathId, $npcId, $region]);
                if ($assign->rowCount() !== 1) {
                    throw new RuntimeException('The route could not be assigned to the NPC.');
                }

                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }

            echo json_encode([
                'success'     => true,
                'path_id'     => $pathId,
                'npc_id'      => (string)$npc['Mob_ID'],
                'npc_name'    => (string)$npc['Name'],
                'point_count' => count($resolvedPoints),
            ]);
            exit;
        }

        if ($action === 'delete_patrol_path' && $_SERVER['REQUEST_METHOD']==='POST') {
            checkToken($_POST['csrf_token'] ?? '');
            $pathId = trim($_POST['path_id'] ?? '');
            if ($pathId === '') {
                echo json_encode(['success'=>false, 'error'=>'Missing route ID.']);
                exit;
            }

            $assignedStmt = $db->prepare("SELECT Region, X, Y FROM mob WHERE PathID = ?");
            $assignedStmt->execute([$pathId]);
            $assignedNpcs = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);
            $hasNpcInZone = false;
            $hasNpcOutsideZone = false;
            foreach ($assignedNpcs as $assignedNpc) {
                $inside = (int)$assignedNpc['Region'] === $region
                    && (int)$assignedNpc['X'] >= $minGX && (int)$assignedNpc['X'] <= $maxGX
                    && (int)$assignedNpc['Y'] >= $minGY && (int)$assignedNpc['Y'] <= $maxGY;
                if ($inside) $hasNpcInZone = true;
                else $hasNpcOutsideZone = true;
            }
            if (!$hasNpcInZone) {
                echo json_encode(['success'=>false, 'error'=>'Route not found in the selected zone.']);
                exit;
            }
            if ($hasNpcOutsideZone) {
                echo json_encode(['success'=>false, 'error'=>'This route is shared with an NPC outside this zone and cannot be deleted here.']);
                exit;
            }

            $db->beginTransaction();
            try {
                $db->prepare("UPDATE mob SET PathID = NULL, LastTimeRowUpdated = NOW() WHERE PathID = ? AND Region = ?")
                   ->execute([$pathId, $region]);
                $db->prepare("DELETE FROM pathpoints WHERE PathID = ?")->execute([$pathId]);
                $db->prepare("DELETE FROM path WHERE PathID = ?")->execute([$pathId]);
                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }

            echo json_encode(['success'=>true, 'path_id'=>$pathId]);
            exit;
        }

        // ── GROUP SPAWN ───────────────────────────────────────
        if ($action === 'group_spawn' && $_SERVER['REQUEST_METHOD']==='POST') {
            $name =$_POST['name']??'Group'; $level=max(1,min(75,(int)($_POST['level']??10)));
            $model=max(1,(int)($_POST['model']??408)); $count=max(1,min(20,(int)($_POST['count']??3)));
            $form =trim($_POST['formation']??'circle'); $spc=max(100,(int)($_POST['spacing']??500));
            $cx=(int)($_POST['cx']??0); $cy=(int)($_POST['cy']??0);
            $gcx=$minGX+$cx; $gcy=$minGY+$cy;
            $ns=$db->prepare("SELECT Z FROM mob WHERE Region=? AND Z>100 ORDER BY (POW(X-?,2)+POW(Y-?,2)) ASC LIMIT 5");
            $ns->execute([$region,$gcx,$gcy]); $zv=$ns->fetchAll(PDO::FETCH_COLUMN); sort($zv);
            $gz=count($zv)>=2?(int)$zv[floor(count($zv)/2)]:2500;
            $pos=[];
            for($i=0;$i<$count;$i++){
                if($form==='circle'){$a=(2*M_PI/$count)*$i;$pos[]=[$gcx+round($spc*cos($a)),$gcy+round($spc*sin($a))];}
                elseif($form==='line'){$pos[]=[$gcx+($i-floor($count/2))*$spc,$gcy];}
                elseif($form==='grid'){$c=(int)ceil(sqrt($count));$r=floor($i/$c);$cl=$i%$c;$pos[]=[$gcx+($cl-floor($c/2))*$spc,$gcy+($r-floor($c/2))*$spc];}
                else{$pos[]=[$gcx+rand(-$spc,$spc),$gcy+rand(-$spc,$spc)];}
            }
            $st=$db->prepare("INSERT INTO mob (Name,X,Y,Z,Region,Realm,Level,Model,ClassType,Brain,Speed,Size,RespawnInterval,Constitution,Dexterity,Strength,Quickness,Intelligence,Piety,Charisma,Empathy,AggroLevel,AggroRange,MeleeDamageType,BodyType,Flags,PackageID,LastTimeRowUpdated) VALUES (?,?,?,?,?,0,?,?,'DOL.GS.GameNPC','DOL.AI.Brain.StandardMobBrain',200,50,120,30,30,30,30,30,30,30,30,0,500,2,0,0,'mob_lab',NOW())");
            $ids=[];
            foreach($pos as $p){$st->execute([$name,$p[0],$p[1],$gz,$region,$level,$model]);$ids[]=$db->lastInsertId();}
            echo json_encode(['success'=>true,'spawned'=>count($ids),'ids'=>$ids,'z'=>$gz]);
            exit;
        }

        // ── UNDO ──────────────────────────────────────────────
        if ($action === 'undo' && $_SERVER['REQUEST_METHOD']==='POST') {
            $ids=json_decode($_POST['ids']??'[]',true);
            if(empty($ids)){echo json_encode(['success'=>false,'error'=>'No IDs']);exit;}
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $db->prepare("DELETE FROM mob WHERE Mob_ID IN ($ph) AND PackageID='mob_lab'")->execute($ids);
            echo json_encode(['success'=>true,'deleted'=>count($ids)]);
            exit;
        }

        // ── MEASURE ───────────────────────────────────────────
        if ($action === 'measure') {
            $x1=(int)($_GET['x1']??0);$y1=(int)($_GET['y1']??0);
            $x2=(int)($_GET['x2']??0);$y2=(int)($_GET['y2']??0);
            $dist=round(sqrt(pow(($minGX+$x2)-($minGX+$x1),2)+pow(($minGY+$y2)-($minGY+$y1),2)));
            echo json_encode(['success'=>true,'dist'=>$dist,'travel_sec'=>round($dist/200)]);
            exit;
        }

        // AI actions
        if (str_starts_with($action, 'ai_') && $ai_active) {
            if (!class_exists('AiManager')) { echo json_encode(['error'=>'AiManager not available']); exit; }
            $mob_id = trim($_POST['mob_id'] ?? '');
            $stmt = $db->prepare("SELECT * FROM mob WHERE Mob_ID=? LIMIT 1");
            $stmt->execute([$mob_id]);
            $mob = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mob) { echo json_encode(['error'=>'Mob not found']); exit; }
            $zStmt2 = $db->prepare("SELECT ZoneID,Name AS ZoneName FROM zones WHERE ZoneID=? LIMIT 1");
            $zStmt2->execute([$zoneId]);
            $zoneRow = $zStmt2->fetch(PDO::FETCH_ASSOC);
            $dStmt = $db->prepare("SELECT COUNT(*) FROM mob WHERE Region=? AND Level BETWEEN ? AND ?");
            $dStmt->execute([$mob['Region'],max(1,$mob['Level']-5),$mob['Level']+5]);
            $mob_density = (int)$dStmt->fetchColumn();
            global $botSettings;
            $ai = new AiManager($db,$botSettings,$currentUserId,$userPriv);
            $raceNames=[1=>'Human',2=>'Elf',9=>'Dwarf',25=>'Dragon',26=>'Drake',27=>'Wolf',28=>'Bear',48=>'Skeleton',49=>'Zombie',50=>'Ghost'];

            if ($action==='ai_full_analysis') {
                $result=$ai->request('mob_editor','full_analysis',['mob_name'=>$mob['Name'],'level'=>(int)$mob['Level'],'race'=>$raceNames[(int)$mob['Race']]??'Unknown','strength'=>(int)$mob['Strength'],'constitution'=>(int)$mob['Constitution'],'dexterity'=>(int)$mob['Dexterity'],'quickness'=>(int)$mob['Quickness'],'aggro_level'=>(int)$mob['AggroLevel'],'aggro_range'=>(int)$mob['AggroRange'],'respawn'=>(int)$mob['RespawnInterval'],'size'=>(int)$mob['Size'],'zone'=>$zoneRow['ZoneName']??'Unknown','region'=>(int)$mob['Region'],'mobs_in_area'=>$mob_density,'instruction'=>'Provide comprehensive analysis. Cover: STATS (appropriate for level?), AGGRO balance, SPAWN interval, OVERALL RATING 1-10.'],['save_suggestion'=>true,'target_id'=>(int)$mob['Mob_ID']]);
                echo json_encode($result); exit;
            }
            if ($action==='ai_suggest_model') {
                $result=$ai->request('mob_editor','suggest_model',['mob_name'=>$mob['Name'],'level'=>(int)$mob['Level'],'race'=>$raceNames[(int)$mob['Race']]??'Unknown','zone'=>$zoneRow['ZoneName']??'Unknown','current_model'=>(int)$mob['Model'],'instruction'=>'Suggest best DAoC model ID. Return JSON: {"model_id":INT,"model_name":"...","reasoning":"...","alternatives":[]}'],['save_suggestion'=>true,'target_id'=>(int)$mob['Mob_ID']]);
                echo json_encode($result); exit;
            }
            if ($action==='ai_generate_lore') {
                $result=$ai->request('mob_editor','generate_lore',['mob_name'=>$mob['Name'],'level'=>(int)$mob['Level'],'zone'=>$zoneRow['ZoneName']??'Unknown','race'=>$raceNames[(int)$mob['Race']]??'Creature','aggressive'=>(int)$mob['AggroLevel']>50,'instruction'=>'Write rich lore entry (3-4 sentences). DAoC medieval fantasy tone.'],['save_suggestion'=>true,'target_id'=>(int)$mob['Mob_ID']]);
                echo json_encode($result); exit;
            }
            if ($action==='ai_mob_spawn') {
                $result=$ai->request('mob_editor','suggest_spawn',['mob_name'=>$mob['Name'],'level'=>(int)$mob['Level'],'zone'=>$zoneRow['ZoneName']??'Unknown','current_respawn'=>(int)$mob['RespawnInterval'],'mobs_same_level'=>$mob_density,'aggro_level'=>(int)$mob['AggroLevel'],'instruction'=>'Suggest optimal spawn settings. Return JSON: {"respawn":int,"aggro_level":int,"aggro_range":int,"reasoning":"..."}'],['save_suggestion'=>true,'target_id'=>(int)$mob['Mob_ID']]);
                echo json_encode($result); exit;
            }
        }

    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
        exit;
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']);
    exit;
}
?>

<link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

<div class="mob-lab-scope" id="mob-lab-root">
    <div id="toolbar">
        <h1>⚗ mob Lab</h1>
        <select id="zone-select">
            <optgroup label="── Albion ──">
                <option value="0" selected>Zone Camelot Hills</option>
                <option value="1">Zone Salisbury Plains</option>
                <option value="2">Zone Black Mountains South</option>
                <option value="3">Zone Black Mountains North</option>
                <option value="4">Zone Dartmoor</option>
                <option value="6">Zone Cornwall</option>
                <option value="7">Zone Llyn Barfog</option>
                <option value="8">Zone Campacorentin Forest</option>
                <option value="9">Zone Avalon Marsh</option>
                <option value="12">Zone Snowdonia</option>
            </optgroup>
            <optgroup label="── Midgard ──">
                <option value="100">Zone Vale of Mularn</option>
                <option value="101">Zone East Svealand</option>
                <option value="102">Zone West Svealand</option>
                <option value="103">Zone Gotar</option>
                <option value="104">Zone Muspelheim</option>
                <option value="105">Zone Myrkwood Forest</option>
                <option value="106">Zone Skona Ravine</option>
                <option value="107">Zone Vanern Swamp</option>
                <option value="108">Zone Raumarik</option>
                <option value="116">Zone Malmohus</option>
            </optgroup>
            <optgroup label="── Hibernia ──">
                <option value="200">Zone Lough Derg</option>
                <option value="201">Zone Silvermine Mountains</option>
                <option value="202">Zone Shannon Estuary</option>
                <option value="203">Zone Cliffs of Moher</option>
                <option value="204">Zone Lough Gur</option>
                <option value="205">Zone Bog of Cullen</option>
                <option value="206">Zone Valley of Bri Leith</option>
                <option value="207">Zone Connacht</option>
                <option value="216">Zone Sheeroe Hills</option>
            </optgroup>
        </select>

        <button class="tb-btn" id="btn-reload"><?= t("mobeditor.btn_reload") ?></button>
        <button class="tb-btn" id="btn-heatmap" title="Spawn Heatmap"><?= t("mobeditor.btn_heatmap") ?></button>
        <button class="tb-btn" id="btn-drag-sidebar" title="<?= t('mobeditor.spawn.drag') ?>"><?= t("mobeditor.btn_spawn") ?></button>
        <button class="tb-btn" id="btn-favs" title="Favourites"><?= t("mobeditor.btn_favs") ?></button>
        <button class="tb-btn" id="btn-night" title="Night Mode"><?= t("mobeditor.btn_night") ?></button>
        <button class="tb-btn" id="btn-cluster" title="Cluster Mode"><?= t("mobeditor.btn_cluster") ?></button>
        <button class="tb-btn" id="btn-calibrate" title="Z-Calibration"><?= t("mobeditor.btn_calibration") ?></button>
        <button class="tb-btn" id="btn-3d" title="3D View"><?= t("mobeditor.btn_3d") ?></button>
        <button class="tb-btn" id="btn-2dedit" title="2D Position & Patrol Editor">✏ 2D Edit</button>

        <div class="acp-s-d0394fd2"></div>

        <div id="map-mode-wrap">
            <button class="vm-btn" id="mm-aggro"   onclick="toggleMapMode('aggro')"  title="Aggro Radius"><?= t("mobeditor.mode.aggro") ?></button>
            <button class="vm-btn" id="mm-lvlheat" onclick="toggleMapMode('lvlheat')" title="Level-Heatmap"><?= t("mobeditor.mode.lvlheat") ?></button>
            <button class="vm-btn" id="mm-patrol"  onclick="toggleMapMode('patrol')" title="Patrol Paths"><?= t("mobeditor.mode.patrol") ?></button>
            <button class="vm-btn" id="mm-measure" onclick="toggleMapMode('measure')" title="Measure Distance"><?= t("mobeditor.mode.measure") ?></button>
        </div>

        <div class="acp-s-d0394fd2"></div>

        <button class="tb-btn" id="btn-players"  title="Online Players"><?= t("mobeditor.btn_players") ?></button>
        <button class="tb-btn" id="btn-group-spawn" title="Group Spawn"><?= t("mobeditor.btn_group") ?></button>
        <button class="tb-btn acp-s-94294764" id="btn-undo"      title="Undo last spawn"><?= t("mobeditor.btn_undo") ?></button>
        <button class="tb-btn" id="btn-loc-import" title="/loc Import"><?= t("mobeditor.btn_loc") ?></button>

        <div id="view-mode-wrap">
            <button class="vm-btn active" id="vm-dots"  onclick="setViewMode('dots')"><?= t("mobeditor.view.dots") ?></button>
            <button class="vm-btn"        id="vm-icons" onclick="setViewMode('icons')"><?= t("mobeditor.view.icons") ?></button>
        </div>

        <div id="mob-jump-wrap" class="acp-s-c451fdb0">
            <input type="text" id="mob-jump-input" placeholder="🔍 Search mob…" autocomplete="off"
                   class="acp-s-48dc6de0">
            <div id="mob-jump-results" class="acp-s-ce94ac17"></div>
        </div>

        <div id="ui-scale-wrap" class="acp-s-33e62f80">
            <button class="tb-btn" id="btn-ui-scale-down" title="Smaller UI text">A-</button>
            <button class="tb-btn" id="btn-ui-scale-up"   title="Larger UI text">A+</button>
        </div>

        <span id="mob-count"><?= t("mobeditor.loading") ?></span>
    </div>

    <div id="map-wrap" class="acp-s-c451fdb0">
        <div id="map"></div>
        <div class="cal-mode-indicator" id="cal-mode-indicator"><?= t("mobeditor.calibration.mode") ?></div>
        <div id="coord-info-box"></div>

        <div id="mob-sidebar">
            <div id="mob-sidebar-header"><?= t("mobeditor.drag.title") ?></div>
            <div id="mob-sidebar-search">
                <input type="text" id="drag-search" placeholder="<?= t('mobeditor.search.model') ?>">
            </div>
            <div id="mob-drag-list"></div>
        </div>

        <div id="edit-panel" class="collapsed">
            <div id="ep-header">
                <div id="ep-title"><?= t("mobeditor.editor.title") ?></div>
                <div id="ep-subtitle">—</div>
            </div>
            <div id="ep-mob-img-wrap">
                <img id="ep-mob-img" src="" alt="">
                <div id="ep-mob-emoji">🐾</div>
                <div id="ep-mob-img-overlay">MODEL</div>
            </div>
            <div id="ep-tabs">
                <div class="ep-tab active" data-tab="stats"   onclick="switchEpTab('stats')"><?= t("mobeditor.tab.stats") ?></div>
                <div class="ep-tab"        data-tab="drops"   onclick="switchEpTab('drops')"><?= t("mobeditor.tab.drops") ?></div>
                <div class="ep-tab"        data-tab="compare" onclick="switchEpTab('compare')"><?= t("mobeditor.tab.compare") ?></div>
                <?php if ($ai_active): ?>
                <div class="ep-tab" data-tab="ai" onclick="switchEpTab('ai')"><?= t("mobeditor.tab.ai") ?></div>
                <?php endif; ?>
            </div>
            <div id="ep-body"><div class="ep-loading"><?= t("mobeditor.select.mob") ?></div></div>
            <div id="ep-footer" class="acp-s-cb458930">
                <button id="ep-fav-btn" title="<?= t('mobeditor.btn.add_fav') ?>">★</button>
                <button id="ep-save"><?= t("mobeditor.btn.save") ?></button>
                <button id="ep-delete">🗑</button>
            </div>
        </div>

        <div id="heatmap-legend">
            <div><?= t("mobeditor.heatmap.title") ?></div>
            <div class="heatmap-grad"></div>
            <div class="acp-s-59d85f3b"><span><?= t("mobeditor.heatmap.low") ?></span><span><?= t("mobeditor.heatmap.high") ?></span></div>
        </div>

        <div id="fav-panel">
            <div id="fav-panel-header">
                <span><?= t("mobeditor.favs.title") ?></span>
                <button id="fav-panel-close">✕</button>
            </div>
            <div id="fav-list"><div id="fav-empty"><?= t("mobeditor.favs.empty") ?></div></div>
        </div>

        <div id="cal-panel">
            <div id="cal-panel-header">
                <span><?= t("mobeditor.calibration.title") ?></span>
                <button id="cal-panel-close">✕</button>
            </div>
            <div id="cal-panel-body">
                <div id="cal-instructions">
                    Double-click Calibration to activate. Then click a map point and enter the Z value from /loc.
                </div>
                <div id="cal-form" class="acp-s-cb458930">
                    <div class="cal-field"><label>Z-Wert from /loc</label><input type="number" id="cal-z-input" placeholder="e.g. 2336" min="0" max="16000"></div>
                    <div class="cal-field"><label><?= t("mobeditor.calibration.label") ?></label><input type="text" id="cal-label-input" placeholder="e.g. Camelot Gate"></div>
                    <div id="cal-coords-preview"></div>
                    <div class="acp-s-e396ba4c">
                        <button id="cal-save-btn"><?= t("mobeditor.btn.save") ?></button>
                        <button id="cal-cancel-btn"><?= t("mobeditor.cancel") ?></button>
                    </div>
                </div>
                <div id="cal-list-wrap"><div id="cal-list"></div></div>
                <div id="cal-hint" class="acp-s-cb458930"><div id="cal-hint-text"></div></div>
            </div>
        </div>

        <div id="patrol-panel">
            <div id="patrol-panel-header">
                <span><?= t("mobeditor.patrol.title") ?></span>
                <button id="patrol-panel-close">✕</button>
            </div>
            <div id="patrol-panel-body">
                <div id="patrol-record-row" class="acp-s-eb684ed7">
                    <div class="acp-s-3ff40c70"><?= h(t('mobeditor.patrol.npc_hint', [], 'Search for a spawned NPC, then record at least two route points on the map. NPCs remain hidden from the map.')) ?></div>
                    <div class="patrol-npc-picker">
                        <input type="search" id="patrol-npc-search" placeholder="<?= h(t('mobeditor.patrol.npc_search_placeholder', [], 'Search NPC by name, guild or ID…')) ?>" autocomplete="off">
                        <input type="hidden" id="patrol-npc-id" value="">
                        <div id="patrol-npc-results" class="patrol-npc-results"></div>
                        <div id="patrol-npc-selected" class="patrol-npc-selected"><?= h(t('mobeditor.patrol.no_npc_selected', [], 'No NPC selected.')) ?></div>
                    </div>
                    <input type="text" id="patrol-label" placeholder="<?= h(t('mobeditor.patrol.route_name_pathid', [], 'Route name / Path ID…')) ?>" class="acp-s-daf10a43" maxlength="255">
                    <select id="patrol-path-type" class="patrol-path-type">
                        <option value="2" selected><?= h(t('mobeditor.patrol.path_reverse', [], 'Reverse (back and forth)')) ?></option>
                        <option value="3"><?= h(t('mobeditor.patrol.path_loop', [], 'Loop')) ?></option>
                        <option value="1"><?= h(t('mobeditor.patrol.path_once', [], 'Once')) ?></option>
                    </select>
                    <div class="acp-s-cfb7cc6b">
                        <button id="patrol-record-btn" class="acp-s-82145126"><?= t("mobeditor.patrol.record") ?></button>
                        <button id="patrol-save-btn"   class="acp-s-114e562d"><?= t("mobeditor.btn.save") ?></button>
                        <button id="patrol-clear-btn"  class="acp-s-c560ea4c"><?= t("mobeditor.patrol.clear") ?></button>
                    </div>
                    <div id="patrol-point-count" class="acp-s-5775021d"></div>
                </div>
                <div id="patrol-list"></div>
            </div>
        </div>

        <div id="lvl-legend">
            <div><?= t("mobeditor.lvlheat.title") ?></div>
            <div class="lvl-grad"></div>
            <div class="acp-s-59d85f3b"><span>Lv1</span><span>Lv75</span></div>
        </div>

        <!-- ── 2D EDIT OVERLAY ── -->
        <div id="edit2d-overlay">
            <div id="edit2d-map"></div>
            <div id="edit2d-sidebar">
                <div id="edit2d-header">
                    <span id="edit2d-title">✏ 2D Edit</span>
                    <button id="edit2d-close">✕ Close</button>
                </div>
                <div id="edit2d-tabs">
                    <button class="e2d-tab active" id="e2d-tab-move"   onclick="switchE2dTab('move')">📍 Move Mobs</button>
                    <button class="e2d-tab"        id="e2d-tab-patrol" onclick="switchE2dTab('patrol')">🛣 Patrol</button>
                </div>
                <div id="edit2d-content">

                    <!-- MOVE TAB -->
                    <div id="e2d-move-panel" class="active">
                        <div class="e2d-hint">Drag mob markers to reposition them. Changes are staged below — press <b>Save All</b> to write to DB.</div>
                        <div class="e2d-section-title">Pending Changes</div>
                        <div id="e2d-pending-list"><div class="acp-s-ed2a5afc">No changes yet.</div></div>
                        <button id="e2d-save-btn" disabled>✓ Save All Changes</button>
                        <div id="e2d-save-count" class="acp-s-b3a94e93"></div>
                    </div>

                    <!-- PATROL TAB -->
                    <div id="e2d-patrol-panel">
                        <div class="e2d-hint"><?= h(t('mobeditor.patrol.npc_hint', [], 'Search for a spawned NPC, then record at least two route points on the map. NPCs remain hidden from the map.')) ?></div>

                        <div class="e2d-section-title"><?= h(t('mobeditor.patrol.assign_npc', [], 'Assign to NPC')) ?></div>
                        <div class="patrol-npc-picker">
                            <input type="search" id="e2d-patrol-npc-search" placeholder="<?= h(t('mobeditor.patrol.npc_search_placeholder', [], 'Search NPC by name, guild or ID…')) ?>" autocomplete="off">
                            <input type="hidden" id="e2d-patrol-npc-id" value="">
                            <div id="e2d-patrol-npc-results" class="patrol-npc-results"></div>
                            <div id="e2d-patrol-npc-selected" class="patrol-npc-selected"><?= h(t('mobeditor.patrol.no_npc_selected', [], 'No NPC selected.')) ?></div>
                        </div>

                        <div class="e2d-section-title"><?= h(t('mobeditor.patrol.route_name_pathid', [], 'Route name / Path ID')) ?></div>
                        <input type="text" id="e2d-patrol-label" placeholder="<?= h(t('mobeditor.patrol.route_example_placeholder', [], 'e.g. Outer Wall Patrol')) ?>" maxlength="255">

                        <div class="e2d-section-title"><?= h(t('mobeditor.patrol.path_type', [], 'Path Type')) ?></div>
                        <select id="e2d-patrol-path-type" class="patrol-path-type">
                            <option value="2" selected><?= h(t('mobeditor.patrol.path_reverse', [], 'Reverse (back and forth)')) ?></option>
                            <option value="3"><?= h(t('mobeditor.patrol.path_loop', [], 'Loop')) ?></option>
                            <option value="1"><?= h(t('mobeditor.patrol.path_once', [], 'Once')) ?></option>
                        </select>

                        <button id="e2d-patrol-record-btn">● Start Recording</button>
                        <div id="e2d-patrol-pt-count"></div>
                        <button id="e2d-patrol-save-btn" disabled>✓ Save Patrol Route</button>

                        <div class="e2d-section-title acp-s-553a645f">Saved Routes</div>
                        <div id="e2d-patrol-route-list"><div class="acp-s-ed2a5afc">No routes yet.</div></div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<div class="mob-popup-wrap" id="mob-popup">
    <div class="mob-popup">
        <div class="mob-popup-hero">
            <img id="popup-img" src="" alt="">
            <div class="mob-popup-hero-overlay"></div>
            <div class="mob-popup-hero-badge" id="popup-model-badge"><?= t("mobeditor.popup.model") ?></div>
        </div>
        <div class="mob-popup-body">
            <div class="mob-popup-name" id="popup-name">—</div>
            <div class="mob-popup-sub"  id="popup-sub"><?= t("mobeditor.popup.level_region") ?></div>
            <div class="spawn-pct-row">
                <span class="spawn-pct-label"><?= t("mobeditor.popup.spawnpct") ?></span>
                <div class="spawn-pct-bar"><div class="spawn-pct-fill acp-s-9c33ea5e" id="popup-spawnbar"></div></div>
                <span class="spawn-pct-val" id="popup-spawnpct">—%</span>
            </div>
            <div class="popup-stats-grid" id="popup-stats"></div>
            <div class="popup-drops" id="popup-drops"></div>
        </div>
        <div class="mob-popup-footer">
            <button class="popup-btn" id="popup-edit-btn"  onclick="popupEdit()"><?= t("mobeditor.popup.edit") ?></button>
            <button class="popup-btn" id="popup-fav-btn"   onclick="popupToggleFav()"><?= t("mobeditor.popup.fav") ?></button>
            <button class="popup-btn" id="popup-close-btn" onclick="closePopup()"><?= t("mobeditor.popup.close") ?></button>
        </div>
    </div>
</div>

<div id="loc-modal-overlay">
    <div id="loc-modal">
        <h2><?= t("mobeditor.loc.title") ?></h2>
        <p class="acp-s-005eac80"><?= t("mobeditor.loc.desc") ?></p>
        <div class="field">
            <label><?= t("mobeditor.loc.coords") ?></label>
            <input type="text" id="loc-input" placeholder="<?= t('mobeditor.loc.placeholder') ?>">
        </div>
        <div id="loc-parsed" class="acp-s-a6c128bc"></div>
        <div class="modal-actions">
            <button id="loc-cancel"><?= t("mobeditor.cancel") ?></button>
            <button id="loc-confirm"><?= t("mobeditor.loc.confirm") ?></button>
        </div>
    </div>
</div>

<div id="group-modal-overlay">
    <div id="group-modal">
        <h2><?= t("mobeditor.group.title") ?></h2>
        <div id="group-preview-img-wrap" class="acp-s-54d99757">
            <img id="group-preview-img" src="" alt="" class="acp-s-bb27c763">
        </div>
        <div class="acp-s-f56ef11e">
            <div class="field"><label><?= t("mobeditor.group.name") ?></label><input type="text" id="g-name" value="Guard"></div>
            <div class="field"><label><?= t("mobeditor.group.count") ?></label><input type="number" id="g-count" value="4" min="1" max="20"></div>
            <div class="field"><label><?= t("mobeditor.group.level") ?></label><input type="number" id="g-level" value="10" min="1" max="75"></div>
            <div class="field"><label><?= t("mobeditor.group.model") ?></label><input type="number" id="g-model" value="408" min="1" max="9999"></div>
            <div class="field">
                <label><?= t("mobeditor.group.formation") ?></label>
                <select id="g-formation" class="acp-s-1b1a7765">
                    <option value="circle"><?= t("mobeditor.form.circle") ?></option>
                    <option value="line"><?= t("mobeditor.form.line") ?></option>
                    <option value="grid"><?= t("mobeditor.form.grid") ?></option>
                    <option value="random"><?= t("mobeditor.form.random") ?></option>
                </select>
            </div>
            <div class="field"><label><?= t("mobeditor.group.spacing") ?></label><input type="number" id="g-spacing" value="500" min="100" max="5000"></div>
        </div>
        <div id="group-coord-preview" class="acp-s-23b47a8d"></div>
        <div class="modal-actions">
            <button id="group-cancel"><?= t("mobeditor.cancel") ?></button>
            <button id="group-confirm"><?= t("mobeditor.group.spawn") ?></button>
        </div>
    </div>
</div>

<div id="measure-tooltip" class="acp-s-56d71b2f"></div>

<div id="modal-overlay">
    <div id="modal">
        <h2><?= t("mobeditor.spawn.title") ?></h2>
        <div id="modal-mob-preview">
            <img id="modal-preview-img" src="" alt="">
            <div>
                <div class="mob-popup-name" id="modal-preview-name"><?= t("mobeditor.spawn.newmob") ?></div>
                <div id="modal-mob-preview-sub"><?= t("mobeditor.spawn.drag") ?></div>
            </div>
        </div>
        <div class="field"><label><?= t("mobeditor.group.name") ?></label><input type="text" id="f-name" value="Mob Lab Specimen" maxlength="64"></div>
        <div class="field"><label><?= t("mobeditor.group.level") ?></label><input type="number" id="f-level" value="50" min="1" max="75"></div>
        <div class="field"><label><?= t("mobeditor.group.model") ?></label><input type="number" id="f-model" value="408" min="1" max="9999"></div>
        <div class="field"><label>Z-Height <span id="z-source-badge" class="acp-s-7b865cda"><?= t("mobeditor.field.z_auto") ?></span></label>
            <input type="number" id="f-z" value="0" min="0" max="16000">
            <div id="z-auto-hint" class="acp-s-c6a30caf"></div>
        </div>
        <div id="coord-preview"><?= t("mobeditor.coords") ?></div>
        <div class="modal-actions">
            <button id="modal-cancel"><?= t("mobeditor.cancel") ?></button>
            <button id="modal-confirm"><?= t("mobeditor.spawn.confirm") ?></button>
        </div>
    </div>
</div>

<div id="view3d-overlay">
    <div id="view3d-container">
        <div id="view3d-header">
            <span id="view3d-title"><?= t("mobeditor.3d.title") ?></span>
            <div id="view3d-controls">
                <span id="view3d-hint"><?= t("mobeditor.3d.controls") ?></span>
                <button class="tb-btn" id="view3d-close"><?= t("mobeditor.popup.close") ?></button>
            </div>
        </div>
        <canvas id="view3d-canvas"></canvas>
        <div id="view3d-loading">
            <div id="view3d-loading-text"><?= t("mobeditor.3d.loading") ?></div>
            <div id="view3d-loading-bar"><div id="view3d-loading-fill"></div></div>
        </div>
        <div id="view3d-mob-tooltip"></div>
        <div id="view3d-legend">
            <div class="v3l-item"><span class="acp-s-c7773693"></span> mob</div>
            <div class="v3l-item"><span class="acp-s-21a41d20"></span> mob_lab</div>
            <div class="v3l-item"><span class="acp-s-8bfe3670"></span> Favorite</div>
            <div class="v3l-item"><span class="acp-s-190cd1f7"></span> Calibration</div>
        </div>
    </div>
</div>

<div id="toast"></div>

</div><script src="assets/vendor/leaflet/leaflet.js"></script>
<script>
(function() {
'use strict';

const CMS_URL  = 'acp.php?s=mob_editor';
const TILE     = 8192;
const ZSIZE    = TILE * 8;
const BOUNDS   = [[0,0],[ZSIZE,ZSIZE]];
const MOB_IMG  = (id) => `assets/img/mobs/${id}.jpg`;
// Y-flip: Leaflet's axis runs inverted relative to the DB/gloc Y axis.
// Self-inverse: flipY(flipY(y)) === y, so it works in both directions
// (DB-Y -> Leaflet-Lat  AND  Leaflet-Lat -> DB-Y).
const flipY    = (y) => ZSIZE - y;
const AI_ACTIVE = <?= $ai_active ? 'true' : 'false' ?>;
const CSRF_TOKEN = '<?= generateToken() ?>';
const PATROL_I18N = <?= json_encode([
    'noNpcSelected' => t('mobeditor.patrol.no_npc_selected', [], 'No NPC selected.'),
    'noNpcsFound'   => t('mobeditor.patrol.no_npcs_found', [], 'No NPCs found in this zone.'),
    'searchFailed'  => t('mobeditor.patrol.npc_search_failed', [], 'NPC search failed.'),
    'selected'      => t('mobeditor.patrol.npc_selected', [], 'Selected: {name} (Lv{level})'),
    'selectedRoute' => t('mobeditor.patrol.npc_selected_route', [], 'Selected: {name} — currently uses {route}'),
    'recordMin'     => t('mobeditor.patrol.record_min_points', [], 'Record at least two route points.'),
    'selectNpc'     => t('mobeditor.patrol.select_npc_first', [], 'Select an NPC first.'),
    'enterName'     => t('mobeditor.patrol.enter_route_name', [], 'Enter a route name.'),
    'saveFailed'    => t('mobeditor.patrol.route_save_failed', [], 'Route save failed.'),
    'assigned'      => t('mobeditor.patrol.route_assigned', [], '✓ Route "{route}" assigned to {npc}'),
    'noRoutes'      => t('mobeditor.patrol.no_routes_zone', [], 'No saved routes for this zone.'),
    'deleteConfirm' => t('mobeditor.patrol.delete_confirm', [], 'Delete route "{route}" and unassign it from all assigned NPCs?'),
    'deleteFailed'  => t('mobeditor.patrol.delete_failed', [], 'Route deletion failed.'),
    'deleted'       => t('mobeditor.patrol.deleted', [], 'Route "{route}" deleted.'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function patrolText(key, replacements={}) {
    let text = PATROL_I18N[key] || key;
    Object.entries(replacements).forEach(([name, value]) => {
        text = text.split(`{${name}}`).join(String(value));
    });
    return text;
}

const RACE_EMOJI = { default:'🐾',27:'🐺',28:'🐺',29:'🐻',30:'🐻',1:'👤',2:'👤',9:'👤',48:'💀',49:'💀',50:'💀',57:'🔥',58:'💧',59:'🌿',60:'⛰️',25:'🐉',26:'🐉' };
const ZONE_IMAGES = {
    0:'assets/img/zones/albion/Camelot_Hills_map.webp',1:'assets/img/zones/albion/Salisbury_Plains_map.webp',
    2:'assets/img/zones/albion/Black_Mtns_South_map.webp',3:'assets/img/zones/albion/Black_Mtns_North_map.webp',
    4:'assets/img/zones/albion/Dartmoor_map.webp',6:'assets/img/zones/albion/Cornwall_map.webp',
    7:'assets/img/zones/albion/Llyn_Barfog_map.webp',8:'assets/img/zones/albion/Campacorentin_Forest_map.webp',
    9:'assets/img/zones/albion/Avalon_Marsh_map.webp',12:'assets/img/zones/albion/Snowdonia_map.webp',
    100:'assets/img/zones/midgard/Vale_of_Mularn_map.webp',101:'assets/img/zones/midgard/East_Svealand_map.webp',
    102:'assets/img/zones/midgard/West_Svealand_map.webp',103:'assets/img/zones/midgard/Gotar_map.webp',
    104:'assets/img/zones/midgard/Muspelheim_map.webp',105:'assets/img/zones/midgard/Myrkwood_Forest_map.webp',
    106:'assets/img/zones/midgard/Skona_Ravine_map.webp',107:'assets/img/zones/midgard/Vanern_Swamp_map.webp',
    108:'assets/img/zones/midgard/Raumarik_map.webp',116:'assets/img/zones/midgard/Malmohus_map.webp',
    200:'assets/img/zones/hibernia/Lough_Derg_map.webp',201:'assets/img/zones/hibernia/Silvermine_Mountains_map.webp',
    202:'assets/img/zones/hibernia/Shannon_Estuary_map.webp',203:'assets/img/zones/hibernia/Cliffs_of_Moher_map.webp',
    204:'assets/img/zones/hibernia/Lough_Gur_map.webp',205:'assets/img/zones/hibernia/Bog_of_Cullen_map.webp',
    206:'assets/img/zones/hibernia/Valley_of_Bri_Leith_map.webp',207:'assets/img/zones/hibernia/Connacht_map.webp',
    216:'assets/img/zones/hibernia/Sheeroe_Hills_map.webp'
};

// ── State ─────────────────────────────────────────────────────
let currentZoneId   = parseInt(document.getElementById('zone-select').value);
let pendingCoords   = {x:0,y:0};
let currentZoneMeta = null;
let selectedMarker  = null;
let selectedMobData = null;
let heatmapActive   = false;
let heatmapLayer    = null;
let clusterMode     = false;
let viewMode        = 'dots';   // 'dots' | 'icons'
let nightMode       = false;
let ai_last         = {};
let favourites      = [];
let allMobsData     = [];
let currentPopupMob = null;
let dragModelId     = null;
let dragMobName     = null;

// ── Map Init ──────────────────────────────────────────────────
const map = L.map('map', {
    crs: L.CRS.Simple, minZoom:-5, maxZoom:3,
    zoomSnap:0.5, zoomDelta:0.5, attributionControl:false
});
const renderer   = L.canvas({padding:0.5});
const layerGroup = L.layerGroup().addTo(map);
let   imageOverlay = null;

// ── Load Favourites ───────────────────────────────────────────
function loadFavourites() {
    fetch(`${CMS_URL}&action=get_favourites&zone_id=0`)
        .then(r=>r.json())
        .then(d=>{ favourites = d.favourites || []; renderFavPanel(); })
        .catch(e=>console.error("Fav load fail:", e));
}

function isFav(mobId) { return favourites.includes(String(mobId)); }

function toggleFav(mobId, mobName, modelId, level) {
    fetch(`${CMS_URL}&action=toggle_favourite&zone_id=0`, {
        method:'POST', body:new URLSearchParams({mob_id:mobId})
    }).then(r=>r.json()).then(d=>{
        if (d.added) {
            favourites.push(String(mobId));
            showToast('★ Added to favourites', false, true);
        } else {
            favourites = favourites.filter(f=>f!==String(mobId));
            showToast('Removed from favourites');
        }
        updateFavButtons(mobId);
        renderFavPanel();
    }).catch(e=>console.error("Fav toggle fail:", e));
}

function updateFavButtons(mobId) {
    const active = isFav(mobId);
    document.getElementById('ep-fav-btn')?.classList.toggle('active', active);
    const pb = document.getElementById('popup-fav-btn');
    if (pb) { pb.textContent = active ? '★ Unfav' : '★ Fav'; pb.classList.toggle('fav-active', active); }
}

function renderFavPanel() {
    const list = document.getElementById('fav-list');
    if (!favourites.length) {
        list.innerHTML = '<div id="fav-empty"><?= t("mobeditor.favs.empty") ?></div>';
        return;
    }
    // Resolve favourite mob records from allMobsData.
    const favMobs = allMobsData.filter(m=>isFav(m.Mob_ID));
    if (!favMobs.length) { list.innerHTML = '<div id="fav-empty">No favourites in this zone</div>'; return; }
    list.innerHTML = favMobs.map(m=>`
        <div class="fav-item" onclick="jumpToMob('${m.Mob_ID}')">
            <img src="${MOB_IMG(m.Model)}" onerror="this.style.display='none'" alt="">
            <span class="fav-item-name">${esc(m.Name)}</span>
            <span class="fav-item-level">Lv${m.Level}</span>
        </div>
    `).join('');
}

function jumpToMob(mobId) {
    const mob = allMobsData.find(m=>String(m.Mob_ID)===String(mobId));
    if (!mob) return;
    map.panTo([flipY(mob.ly), mob.lx]);
    document.getElementById('fav-panel').classList.remove('open');
}

// ── View Mode ─────────────────────────────────────────────────
window.setViewMode = function(mode) {
    viewMode = mode;
    document.getElementById('vm-dots').classList.toggle('active', mode==='dots');
    document.getElementById('vm-icons').classList.toggle('active', mode==='icons');
    if (allMobsData.length) renderMarkers(allMobsData);
};

// ── Night Mode ────────────────────────────────────────────────
document.getElementById('btn-night').addEventListener('click', ()=>{
    nightMode = !nightMode;
    document.getElementById('mob-lab-root').classList.toggle('night-mode', nightMode);
    document.getElementById('btn-night').classList.toggle('night-on', nightMode);
});

// ── UI Scale ──────────────────────────────────────────────────
const UI_SCALE_MIN = 0.8, UI_SCALE_MAX = 1.6, UI_SCALE_STEP = 0.1;
let uiScale = parseFloat(localStorage.getItem('mobEditorUiScale')) || 1;
function applyUiScale() {
    document.getElementById('mob-lab-root').style.setProperty('--ui-scale', uiScale);
    localStorage.setItem('mobEditorUiScale', uiScale);
}
applyUiScale();
document.getElementById('btn-ui-scale-down').addEventListener('click', ()=>{
    uiScale = Math.max(UI_SCALE_MIN, Math.round((uiScale - UI_SCALE_STEP)*10)/10);
    applyUiScale();
});
document.getElementById('btn-ui-scale-up').addEventListener('click', ()=>{
    uiScale = Math.min(UI_SCALE_MAX, Math.round((uiScale + UI_SCALE_STEP)*10)/10);
    applyUiScale();
});

// ── Cluster Mode ──────────────────────────────────────────────
document.getElementById('btn-cluster').addEventListener('click', ()=>{
    clusterMode = !clusterMode;
    document.getElementById('btn-cluster').classList.toggle('active', clusterMode);
    if (allMobsData.length) renderMarkers(allMobsData);
});

// ── Drag Sidebar ──────────────────────────────────────────────
document.getElementById('btn-drag-sidebar').addEventListener('click', ()=>{
    const s = document.getElementById('mob-sidebar');
    s.classList.toggle('open');
    if (s.classList.contains('open')) buildDragSidebar();
});

// Build a list of unique Model IDs for dragging
function buildDragSidebar(filter='') {
    const seen = new Set();
    const items = allMobsData.filter(m=>{
        if (seen.has(m.Model)) return false;
        seen.add(m.Model);
        return !filter || m.Name.toLowerCase().includes(filter.toLowerCase()) || String(m.Model).includes(filter);
    }).slice(0, 80);

    const list = document.getElementById('mob-drag-list');
    list.innerHTML = items.map(m=>`
        <div class="drag-mob-item" draggable="true"
             data-model="${m.Model}" data-name="${esc(m.Name)}"
             data-level="${m.Level}">
            <img src="${MOB_IMG(m.Model)}" onerror="this.style.opacity='0.2'" alt="">
            <div class="dmi-info">
                <div class="dmi-name">${esc(m.Name.substring(0,18))}</div>
                <div class="dmi-model">Model #${m.Model}</div>
            </div>
        </div>
    `).join('');

    // Drag events
    list.querySelectorAll('.drag-mob-item').forEach(el=>{
        el.addEventListener('dragstart', e=>{
            dragModelId  = parseInt(el.dataset.model);
            dragMobName  = el.dataset.name;
            e.dataTransfer.effectAllowed = 'copy';
            e.dataTransfer.setData('text/plain', dragModelId);
        });
        el.addEventListener('dragend', ()=>{
            document.getElementById('map').classList.remove('drag-over');
        });
    });
}

document.getElementById('drag-search').addEventListener('input', e=>{
    buildDragSidebar(e.target.value.trim());
});

// ── Mob Jump-Search (fuzzy) ──────────────────────────────────
function fuzzyScore(name, query) {
    name = name.toLowerCase(); query = query.toLowerCase();
    if (!query) return -1;
    if (name.includes(query)) return 1000 - name.indexOf(query); // substring hits rank highest
    // subsequence match: all query chars appear in order
    let qi = 0;
    for (let i = 0; i < name.length && qi < query.length; i++) {
        if (name[i] === query[qi]) qi++;
    }
    return qi === query.length ? 500 - name.length : -1;
}

function jumpToSearchedMob(mob) {
    const targetZoom = Math.max(map.getZoom(), 2);
    map.setView([flipY(mob.ly), mob.lx], targetZoom, {animate:true});
    setTimeout(()=>showPopup(mob, {latlng:{lat:flipY(mob.ly),lng:mob.lx}}), 250);
}

const jumpInput   = document.getElementById('mob-jump-input');
const jumpResults = document.getElementById('mob-jump-results');

jumpInput.addEventListener('input', () => {
    const q = jumpInput.value.trim();
    if (!q) { jumpResults.style.display = 'none'; jumpResults.innerHTML=''; return; }

    const scored = allMobsData
        .filter(m => m.lx>=0 && m.lx<=ZSIZE && m.ly>=0 && m.ly<=ZSIZE)
        .map(m => ({m, score: Math.max(fuzzyScore(m.Name, q), fuzzyScore(String(m.Model), q))}))
        .filter(x => x.score > -1)
        .sort((a,b) => b.score - a.score)
        .slice(0, 20);

    if (!scored.length) {
        jumpResults.innerHTML = '<div class="acp-s-56634fcb">No results</div>';
        jumpResults.style.display = 'block';
        return;
    }

    jumpResults.innerHTML = scored.map(({m}) => `
        <div class="mob-jump-item acp-s-c1675029" data-id="${esc(m.Mob_ID)}"
            >
            <b>${esc(m.Name)}</b> <span class="acp-s-433dbce5">Lv${m.Level} · #${m.Model}</span>
        </div>
    `).join('');
    jumpResults.style.display = 'block';

    jumpResults.querySelectorAll('.mob-jump-item').forEach(el => {
        el.addEventListener('mouseenter', () => el.style.background = 'rgba(197,160,89,.15)');
        el.addEventListener('mouseleave', () => el.style.background = 'transparent');
        el.addEventListener('click', () => {
            const mob = allMobsData.find(m => String(m.Mob_ID) === el.dataset.id);
            if (mob) jumpToSearchedMob(mob);
            jumpResults.style.display = 'none';
            jumpInput.value = '';
        });
    });
});

jumpInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
        const first = jumpResults.querySelector('.mob-jump-item');
        if (first) first.click();
    } else if (e.key === 'Escape') {
        jumpResults.style.display = 'none';
        jumpInput.blur();
    }
});

document.addEventListener('click', e => {
    if (!jumpInput.contains(e.target) && !jumpResults.contains(e.target)) {
        jumpResults.style.display = 'none';
    }
});

// Map drop target
const mapEl = document.getElementById('map');

mapEl.addEventListener('dragover', e => {
    e.preventDefault();
    mapEl.classList.add('drag-over');
});

mapEl.addEventListener('dragleave', () => {
    mapEl.classList.remove('drag-over');
});

mapEl.addEventListener('drop', e => {
    e.preventDefault();
    mapEl.classList.remove('drag-over');

    if (!dragModelId) return;

    // Mouse position within the map (pixels)
    const rect = mapEl.getBoundingClientRect();
    const px = e.clientX - rect.left;
    const py = e.clientY - rect.top;

    // Pixel -> Leaflet
    const latLng = map.containerPointToLatLng([px, py]);

    pendingCoords.x = Math.round(latLng.lng);
    pendingCoords.y = flipY(Math.round(latLng.lat));

    // Populate modal
    document.getElementById('f-model').value = dragModelId;
    document.getElementById('f-name').value = dragMobName || 'Mob Lab Specimen';
    document.getElementById('modal-preview-img').src = MOB_IMG(dragModelId);
    document.getElementById('modal-preview-name').textContent =
        dragMobName || 'Mob Lab Specimen';

    if (currentZoneMeta) {
        const gx = (parseInt(currentZoneMeta.OffsetX) * TILE) + pendingCoords.x;
        const gy = (parseInt(currentZoneMeta.OffsetY) * TILE) + pendingCoords.y;

        document.getElementById('coord-preview').textContent =
            `Pixel (${Math.round(px)}, ${Math.round(py)}) | Leaflet (${pendingCoords.x}, ${pendingCoords.y}) → Global (${gx}, ${gy})`;
    }

    document.getElementById('modal-overlay').classList.add('open');
});

// ── Rich mob Popup ────────────────────────────────────────────
let popupMobId = null;

function showPopup(mob, e) {
    currentPopupMob = mob;
    popupMobId = mob.Mob_ID;

    document.getElementById('popup-img').src          = MOB_IMG(mob.Model);
    document.getElementById('popup-name').textContent = mob.Name;
    document.getElementById('popup-sub').textContent  = `Level ${mob.Level} · Model #${mob.Model}`;
    document.getElementById('popup-model-badge').textContent = `Model #${mob.Model}`;
    document.getElementById('popup-stats').innerHTML  = `
        <div class="popup-stat"><span class="popup-stat-label">STR</span><span class="popup-stat-val">—</span></div>
        <div class="popup-stat"><span class="popup-stat-label">CON</span><span class="popup-stat-val">—</span></div>
        <div class="popup-stat"><span class="popup-stat-label">DEX</span><span class="popup-stat-val">—</span></div>
        <div class="popup-stat"><span class="popup-stat-label">QUI</span><span class="popup-stat-val">—</span></div>
    `;
    document.getElementById('popup-drops').innerHTML  = '<span class="acp-s-b0bc4717"><?= t("mobeditor.loading") ?></span>';
    document.getElementById('popup-spawnbar').style.width = '0%';
    document.getElementById('popup-spawnpct').textContent = '…%';

    // Fav button
    updateFavButtons(mob.Mob_ID);

    // Position popup near click, keep in viewport
    const popup = document.getElementById('mob-popup');
    popup.classList.add('visible');

    const vw = window.innerWidth, vh = window.innerHeight;
    let px = (e?.originalEvent?.clientX || e?.clientX || vw/2) + 12;
    let py = (e?.originalEvent?.clientY || e?.clientY || vh/2) - 60;
    if (px + 310 > vw) px -= 322;
    if (py + 400 > vh) py = vh - 410;
    if (py < 10) py = 10;
    popup.style.left = px + 'px';
    popup.style.top  = py + 'px';

    // Load detail async
    fetch(`${CMS_URL}&action=get_mob_detail&mob_id=${encodeURIComponent(mob.Mob_ID)}&zone_id=${currentZoneId}`)
        .then(r=>r.json())
        .then(d=>{
            if (!d.success) return;
            const m = d.mob;
            document.getElementById('popup-stats').innerHTML = `
                <div class="popup-stat"><span class="popup-stat-label">STR</span><span class="popup-stat-val">${m.Strength}</span></div>
                <div class="popup-stat"><span class="popup-stat-label">CON</span><span class="popup-stat-val">${m.Constitution}</span></div>
                <div class="popup-stat"><span class="popup-stat-label">DEX</span><span class="popup-stat-val">${m.Dexterity}</span></div>
                <div class="popup-stat"><span class="popup-stat-label">QUI</span><span class="popup-stat-val">${m.Quickness}</span></div>
                <div class="popup-stat"><span class="popup-stat-label">AGG</span><span class="popup-stat-val">${m.AggroLevel}</span></div>
                <div class="popup-stat"><span class="popup-stat-label">RNG</span><span class="popup-stat-val">${m.AggroRange}</span></div>
                <div class="popup-stat"><span class="popup-stat-label">RSP</span><span class="popup-stat-val">${m.RespawnInterval}s</span></div>
                <div class="popup-stat"><span class="popup-stat-label">SZE</span><span class="popup-stat-val">${m.Size}</span></div>
            `;
            // Spawn%
            const pct = d.spawn_pct || 0;
            document.getElementById('popup-spawnbar').style.width  = Math.min(100,pct*4) + '%';
            document.getElementById('popup-spawnpct').textContent  = pct + '%';
            // Drops
            if (d.drops && d.drops.length) {
                document.getElementById('popup-drops').innerHTML =
                    d.drops.slice(0,5).map(dr=>`
                        <div class="popup-drop-item">
                            <span class="popup-drop-name">${esc((dr.ItemTemplateID||'—').substring(0,24))}</span>
                            <span class="popup-drop-chance">${dr.Chance||0}%</span>
                        </div>
                    `).join('');
            } else {
                document.getElementById('popup-drops').innerHTML = '<span class="acp-s-b0bc4717">No Drops</span>';
            }
        }).catch(err=>console.error("Detail load error:", err));
}

window.closePopup = function() {
    document.getElementById('mob-popup').classList.remove('visible');
    currentPopupMob = null;
    popupMobId = null;
};

window.popupEdit = function() {
    if (!currentPopupMob) return;
    const mob = currentPopupMob;
    closePopup();
    setTimeout(() => {
        openEditPanel(mob.Mob_ID, mob.Name, mob.Model, mob.Race);
        switchEpTab('stats');
    }, 50);
};

window.popupToggleFav = function() {
    if (!currentPopupMob) return;
    toggleFav(currentPopupMob.Mob_ID, currentPopupMob.Name, currentPopupMob.Model, currentPopupMob.Level);
};

// Close popup on map click
map.on('click', ()=>closePopup());

// ── Render Markers ────────────────────────────────────────────
function renderMarkers(mobs) {
    layerGroup.clearLayers();

    const visible = mobs.filter(m=>m.lx>=0&&m.lx<=ZSIZE&&m.ly>=0&&m.ly<=ZSIZE);

    if (clusterMode) {
        renderClusters(visible);
    } else if (viewMode === 'icons') {
        renderIconMarkers(visible);
    } else {
        renderDotMarkers(visible);
    }
}

function renderDotMarkers(mobs) {
    mobs.forEach(mob=>{
        const isLab = mob.PackageID === 'mob_lab';
        const isFavMob = isFav(mob.Mob_ID);
        const marker = L.circleMarker([flipY(mob.ly), mob.lx], {
            renderer,
            radius:      isLab ? 7 : (isFavMob ? 6 : 4),
            color:       isFavMob ? '#ffcc44' : (isLab ? '#ff8800' : '#3366cc'),
            fillColor:   isFavMob ? '#ffcc44' : (isLab ? '#ff8800' : '#3366cc'),
            fillOpacity: 0.65,
            weight:      isLab || isFavMob ? 2 : 1
        }).addTo(layerGroup);
        marker._mob = mob;
        attachMarkerEvents(marker, mob);
    });
}

function renderIconMarkers(mobs) {
    mobs.forEach(mob=>{
        const isFavMob = isFav(mob.Mob_ID);
        const iconHtml = `<div class="mob-icon-marker${isFavMob?' fav':''}">
            <img src="${MOB_IMG(mob.Model)}" onerror="this.style.display='none';this.nextSibling.style.display='flex'" alt="${esc(mob.Name)}"><span class="acp-s-dd6e40df">${RACE_EMOJI[mob.Race]||RACE_EMOJI.default}</span>
        </div>`;
        const icon = L.divIcon({
            html: iconHtml,
            className: '',
            iconSize:   [28, 28],
            iconAnchor: [14, 14],
        });
        const marker = L.marker([flipY(mob.ly), mob.lx], {icon}).addTo(layerGroup);
        marker._mob = mob;
        attachMarkerEvents(marker, mob);
    });
}

function renderClusters(mobs) {
    const zoom    = map.getZoom();
    const cellPx  = Math.max(40, 200 / Math.pow(2, zoom + 5));
    const grid    = {};

    mobs.forEach(mob=>{
        const cx = Math.floor(mob.lx / cellPx);
        const cy = Math.floor(mob.ly / cellPx);
        const key = `${cx}_${cy}`;
        if (!grid[key]) grid[key] = [];
        grid[key].push(mob);
    });

    Object.values(grid).forEach(group=>{
        const cx = group.reduce((s,m)=>s+m.lx,0)/group.length;
        const cy = group.reduce((s,m)=>s+m.ly,0)/group.length;

        if (group.length === 1) {
            viewMode === 'icons' ? renderIconMarkers(group) : renderDotMarkers(group);
            return;
        }

        const size = Math.min(48, 20 + group.length * 1.2);
        const iconHtml = `<div class="mob-cluster acp-s-3e397295">${group.length}</div>`;
        const icon = L.divIcon({ html:iconHtml, className:'', iconSize:[size,size], iconAnchor:[size/2,size/2] });
        const marker = L.marker([flipY(cy), cx], {icon}).addTo(layerGroup);
        marker.on('click', ()=>{
            map.setView([flipY(cy),cx], map.getZoom()+1.5);
        });
        marker.bindTooltip(group.slice(0,5).map(m=>m.Name).join(', ') + (group.length>5?'…':''), {direction:'top'});
    });
}

function attachMarkerEvents(marker, mob) {
    marker.bindTooltip(`<b>${mob.Name}</b> · Lv${mob.Level}`, {direction:'top', offset:[0,-6]});

    marker.on('click', e=>{
        L.DomEvent.stopPropagation(e);
        closePopup();
        if (selectedMarker && selectedMarker !== marker) {
            selectedMarker.getElement()?.classList.remove('selected-mob');
        }
        selectedMarker = marker;
        setTimeout(()=>marker.getElement()?.classList.add('selected-mob'), 10);

        showPopup(mob, e);
    });

    marker.on('contextmenu', e=>{
        L.DomEvent.stopPropagation(e);
        L.DomEvent.preventDefault(e);
        showMobCoordBox(mob, e.originalEvent);
    });
}

function showMobCoordBox(mob, evt) {
    const offX = currentZoneMeta ? parseInt(currentZoneMeta.OffsetX)*TILE : 0;
    const offY = currentZoneMeta ? parseInt(currentZoneMeta.OffsetY)*TILE : 0;

    const gx = offX + mob.lx;
    const gy = offY + mob.ly;                 
    const locX = gx;
    const locY = gy;      
    const z    = mob.Z ?? '?';
    const region = currentZoneMeta?.RegionID ?? '?';

    const glocStr = `${gx}, ${gy}, ${z}`;
    const locStr  = `${locX}, ${locY}, ${z}, ${region}`;

    const box = document.getElementById('coord-info-box');
    box.innerHTML = `
        <div class="cib-title">${esc(mob.Name)}</div>
        <div class="cib-row"><span>gloc</span><code>${glocStr}</code><button class="cib-copy" data-copy="${glocStr}">📋</button></div>
        <div class="cib-row"><span>/loc</span><code>${locStr}</code><button class="cib-copy" data-copy="${locStr}">📋</button></div>
        ${mob.PackageID==='mob_lab' ? `<button class="cib-delete" id="cib-delete-btn"><?= addslashes(t("mobeditor.btn.delete")) ?></button>` : ''}
    `;

    const wrap = document.getElementById('map-wrap').getBoundingClientRect();
    box.style.left = (evt.clientX - wrap.left + 10) + 'px';
    box.style.top  = (evt.clientY - wrap.top + 10) + 'px';
    box.classList.add('open');

    box.querySelectorAll('.cib-copy').forEach(btn=>{
        btn.addEventListener('click', ()=>{
            navigator.clipboard?.writeText(btn.dataset.copy);
            showToast('Kopiert!', false, true);
        });
    });

    const delBtn = document.getElementById('cib-delete-btn');
    if (delBtn) {
        delBtn.addEventListener('click', ()=>{
            box.classList.remove('open');
            if (confirm(`<?= addslashes(t("mobeditor.confirm.delete_prefix")) ?> "${mob.Name}"?`)) {
                fetch(`${CMS_URL}&action=delete_mob&zone_id=${currentZoneId}`,{
                    method:'POST',body:new URLSearchParams({mob_id:mob.Mob_ID})
                }).then(()=>loadZone(currentZoneId)).catch(err=>console.error("Delete failed:", err));
            }
        });
    }

    setTimeout(()=>{
        document.addEventListener('click', function closer(ev){
            if (!box.contains(ev.target)) {
                box.classList.remove('open');
                document.removeEventListener('click', closer);
            }
        });
    }, 10);
}

// ── Zone Loader ───────────────────────────────────────────────
function loadZone(zoneId) {
    currentZoneId = zoneId;
    allMobsData   = [];
    layerGroup.clearLayers();
    if (heatmapLayer) { map.removeLayer(heatmapLayer); heatmapLayer=null; }
    selectedMarker  = null;
    selectedMobData = null;
    patrolPoints = [];
    isRecordingPatrol = false;
    patrolDrawLayer.clearLayers();
    resetPatrolNpcPicker('main');
    resetPatrolNpcPicker('edit2d');
    closePopup();
    document.getElementById('edit-panel').classList.add('collapsed');
    document.getElementById('mob-count').textContent = 'Loading…';

    const imgSrc = ZONE_IMAGES[zoneId] || ZONE_IMAGES[0];
    if (imageOverlay) imageOverlay.remove();
    imageOverlay = L.imageOverlay(imgSrc, BOUNDS).addTo(map);
    map.fitBounds(BOUNDS, {maxZoom:-4});
    if (activeMapModes.has('patrol')) loadPatrolPaths();

    fetch(`${CMS_URL}&action=get_mobs&zone_id=${zoneId}`)
        .then(r=>r.json())
        .then(data=>{
            if (!data.success) { showToast('Error: '+data.error,true); return; }
            currentZoneMeta = data.zone;
            allMobsData     = data.mobs;
            renderMarkers(allMobsData);
            const visible = allMobsData.filter(m=>m.lx>=0&&m.lx<=ZSIZE&&m.ly>=0&&m.ly<=ZSIZE).length;
            document.getElementById('mob-count').textContent = `${visible} Mobs · Zone ${zoneId}`;
            renderFavPanel();
            if (heatmapActive) renderHeatmap();
        })
        .catch(()=>showToast('Load error',true));
}

// ── mob Image / Edit Panel ────────────────────────────────────
function loadMobImage(modelId, raceId) {
    const img   = document.getElementById('ep-mob-img');
    const emoji = document.getElementById('ep-mob-emoji');
    document.getElementById('ep-mob-img-overlay').textContent = 'Model #' + modelId;
    img.style.display  = 'none';
    emoji.style.display = 'block';
    emoji.textContent  = RACE_EMOJI[raceId] || RACE_EMOJI.default;
    const t = new Image();
    t.onload = ()=>{ if(t.naturalWidth>1){img.src=MOB_IMG(modelId);img.style.display='block';emoji.style.display='none';} };
    t.src = MOB_IMG(modelId);
}

window.switchEpTab = function(name) {
    document.querySelectorAll('.ep-tab').forEach(t=>t.classList.toggle('active',t.dataset.tab===name));
    document.querySelectorAll('.ep-tab-panel').forEach(p=>p.classList.toggle('active',p.id==='eptab-'+name));
    if (name==='compare' && selectedMobData) loadSimilarMobs();
    if (name==='ai') resetAiPanel();
};

function openEditPanel(mobId, mobName, modelId, raceId) {
    const panel = document.getElementById('edit-panel');
    panel.classList.remove('collapsed');
    document.getElementById('ep-title').textContent   = '✦ mob Editor';
    document.getElementById('ep-subtitle').textContent = mobName;
    document.getElementById('ep-body').innerHTML = '<div class="ep-loading">Loading details…</div>';
    document.getElementById('ep-footer').style.display = 'none';
    loadMobImage(modelId, raceId);

    fetch(`${CMS_URL}&action=get_mob_detail&mob_id=${encodeURIComponent(mobId)}&zone_id=${currentZoneId}`)
        .then(r=>r.json())
        .then(data=>{
            if (!data.success) { showToast('Error: '+data.error,true); return; }
            selectedMobData = data.mob;
            renderEditPanel(data.mob, data.drops, data.spawn_pct||0);
            document.getElementById('ep-footer').style.display = 'flex';
            updateFavButtons(mobId);
        })
        .catch(()=>showToast('Panel load error',true));
}

function statBar(label,val,max) {
    const pct = Math.min(100,Math.round((val/max)*100));
    return `<div class="stat-bar-wrap">
        <span class="stat-bar-label">${label}</span>
        <div class="stat-bar"><div class="stat-bar-fill acp-s-80e2a0ae"></div></div>
        <span class="stat-bar-val">${val}</span>
    </div>`;
}

function renderFlagCheckbox(label, bit, currentFlags) {
    const checked = ((currentFlags||0) & bit) !== 0;
    return `<label class="ep-flag-item">
        <input type="checkbox" class="ep-flag-check" data-bit="${bit}" ${checked?'checked':''}>
        <span>${esc(label)}</span>
    </label>`;
}

function renderEditPanel(mob, drops, spawnPct) {
    const isLab = mob.PackageID==='mob_lab';
    const statsHtml = `
    <div class="ep-tab-panel active" id="eptab-stats">
        <div class="ep-section">
            <div class="ep-section-title">Identity</div>
            <div class="ep-grid">
                <div class="ep-field full"><label><?= t("mobeditor.group.name") ?></label><input id="ep-name" type="text" value="${esc(mob.Name)}" maxlength="64"></div>
                <div class="ep-field"><label><?= t("mobeditor.group.level") ?></label><input id="ep-level" type="number" value="${mob.Level}" min="1" max="75"></div>
                <div class="ep-field"><label><?= t("mobeditor.group.model") ?></label><input id="ep-model" type="number" value="${mob.Model}" min="1" max="9999"></div>
                <div class="ep-field"><label>Size</label><input id="ep-size" type="number" value="${mob.Size}" min="1" max="255"></div>
                <div class="ep-field"><label>Respawn (s)</label><input id="ep-respawn" type="number" value="${mob.RespawnInterval}" min="1"></div>
                <div class="ep-field"><label>Speed</label><input id="ep-speed" type="number" value="${mob.Speed||0}" min="0"></div>
            </div>
        </div>
        <div class="ep-section">
            <div class="ep-section-title">Text &amp; Flavor</div>
            <div class="ep-grid">
                <div class="ep-field full"><label>Suffix</label><input id="ep-suffix" type="text" value="${esc(mob.Suffix||'')}" maxlength="255"></div>
                <div class="ep-field full"><label>Guild Name</label><input id="ep-guild" type="text" value="${esc(mob.GuildName||'')}" maxlength="255"></div>
                <div class="ep-field full"><label>Examine Article</label><input id="ep-examine" type="text" value="${esc(mob.ExamineArticle||'')}" maxlength="255"></div>
                <div class="ep-field full"><label>Message Article</label><input id="ep-message" type="text" value="${esc(mob.MessageArticle||'')}" maxlength="255"></div>
            </div>
        </div>
        <div class="ep-section">
            <div class="ep-section-title">Behavior</div>
            <div class="ep-grid">
                <div class="ep-field"><label>Realm</label>
                    <select id="ep-realm">
                        <option value="0" ${!mob.Realm?'selected':''}>None</option>
                        <option value="1" ${mob.Realm==1?'selected':''}>Albion</option>
                        <option value="2" ${mob.Realm==2?'selected':''}>Midgard</option>
                        <option value="3" ${mob.Realm==3?'selected':''}>Hibernia</option>
                    </select>
                </div>
                <div class="ep-field"><label>Race</label><input id="ep-race" type="number" value="${mob.Race||0}" min="0"></div>
                <div class="ep-field"><label>Gender</label>
                    <select id="ep-gender">
                        <option value="0" ${!mob.Gender?'selected':''}>Neutral</option>
                        <option value="1" ${mob.Gender==1?'selected':''}>Male</option>
                        <option value="2" ${mob.Gender==2?'selected':''}>Female</option>
                    </select>
                </div>
                <div class="ep-field"><label>Damage Type</label>
                    <select id="ep-damagetype">
                        <option value="0" ${!mob.MeleeDamageType?'selected':''}>Natural</option>
                        <option value="1" ${mob.MeleeDamageType==1?'selected':''}>Crush</option>
                        <option value="2" ${mob.MeleeDamageType==2?'selected':''}>Slash</option>
                        <option value="3" ${mob.MeleeDamageType==3?'selected':''}>Thrust</option>
                    </select>
                </div>
                <div class="ep-field"><label>Body Type</label><input id="ep-bodytype" type="number" value="${mob.BodyType||0}" min="0"></div>
                <div class="ep-field"><label>Max Distance</label><input id="ep-maxdist" type="number" value="${mob.MaxDistance||0}"></div>
                <div class="ep-field"><label>Roaming Range</label><input id="ep-roaming" type="number" value="${mob.RoamingRange??-1}" min="-1"></div>
            </div>
        </div>
        <div class="ep-section">
            <div class="ep-section-title">NPC Template</div>
            <div class="ep-field full acp-s-c451fdb0">
                <label>Linked Template</label>
                <input id="ep-npctemplate-search" type="text" autocomplete="off" placeholder="Search NPC template…"
                       value="${mob.NPCTemplateID>0 ? esc(mob.NPCTemplateName || ('#'+mob.NPCTemplateID)) : ''}">
                <input type="hidden" id="ep-npctemplate-id" value="${mob.NPCTemplateID>0 ? mob.NPCTemplateID : -1}">
                <button id="ep-npctemplate-clear" type="button" title="Unlink template"
                        class="acp-s-a496761e">✕</button>
                <div id="ep-npctemplate-results" class="acp-s-cc7d5298"></div>
            </div>
            <div class="acp-s-75288b0b">Grants this mob the template's Spells, Styles, TetherRange, ParryChance, EvadeChance, BlockChance and LeftHandSwingChance — those are not stored per-mob (in-game equivalent: <code>/mob npctemplate &lt;ID&gt;</code>).</div>
        </div>
        <div class="ep-section">
            <div class="ep-section-title"><?= t("mobeditor.popup.spawnpct") ?></div>
            <div class="spawn-pct-row">
                <span class="spawn-pct-label">This Model</span>
                <div class="spawn-pct-bar"><div class="spawn-pct-fill acp-s-d13a351a"></div></div>
                <span class="spawn-pct-val">${spawnPct}%</span>
            </div>
        </div>
        <div class="ep-section">
            <div class="ep-section-title">Aggro</div>
            <div class="ep-grid">
                <div class="ep-field"><label>Aggro Level</label><input id="ep-aggro" type="number" value="${mob.AggroLevel}" min="0" max="100"></div>
                <div class="ep-field"><label>Aggro Range</label><input id="ep-range" type="number" value="${mob.AggroRange}" min="0"></div>
            </div>
        </div>
        <div class="ep-section">
            <div class="ep-section-title"><?= t("mobeditor.tab.stats") ?></div>
            ${statBar('STR',mob.Strength,500)}
            ${statBar('CON',mob.Constitution,500)}
            ${statBar('DEX',mob.Dexterity,500)}
            ${statBar('QUI',mob.Quickness,500)}
            ${statBar('INT',mob.Intelligence,500)}
            ${statBar('PIE',mob.Piety,500)}
            ${statBar('CHA',mob.Charisma,500)}
            ${statBar('EMP',mob.Empathy,500)}
            <div class="ep-grid acp-s-d4316970">
                <div class="ep-field"><label>STR</label><input id="ep-str" type="number" value="${mob.Strength}" min="0" max="500"></div>
                <div class="ep-field"><label>CON</label><input id="ep-con" type="number" value="${mob.Constitution}" min="0" max="500"></div>
                <div class="ep-field"><label>DEX</label><input id="ep-dex" type="number" value="${mob.Dexterity}" min="0" max="500"></div>
                <div class="ep-field"><label>QUI</label><input id="ep-qui" type="number" value="${mob.Quickness}" min="0" max="500"></div>
                <div class="ep-field"><label>INT</label><input id="ep-int" type="number" value="${mob.Intelligence}" min="0" max="500"></div>
                <div class="ep-field"><label>PIE</label><input id="ep-pie" type="number" value="${mob.Piety}" min="0" max="500"></div>
                <div class="ep-field"><label>CHA</label><input id="ep-cha" type="number" value="${mob.Charisma}" min="0" max="500"></div>
                <div class="ep-field"><label>EMP</label><input id="ep-emp" type="number" value="${mob.Empathy}" min="0" max="500"></div>
            </div>
        </div>
        <div class="ep-section">
            <div class="ep-section-title">Flags</div>
            <input type="hidden" id="ep-flags-value" value="${mob.Flags||0}">
            <div class="ep-flags-grid">
                ${renderFlagCheckbox('Peace',        0x10,  mob.Flags)}
                ${renderFlagCheckbox('Ghost',         0x01,  mob.Flags)}
                ${renderFlagCheckbox('Stealth',       0x02,  mob.Flags)}
                ${renderFlagCheckbox('Torch',         0x40,  mob.Flags)}
                ${renderFlagCheckbox('Statue',        0x80,  mob.Flags)}
                ${renderFlagCheckbox('Flying',        0x20,  mob.Flags)}
                ${renderFlagCheckbox('Swimming',      0x100, mob.Flags)}
                ${renderFlagCheckbox('No Name',       0x04,  mob.Flags)}
                ${renderFlagCheckbox('Not Targetable',0x08,  mob.Flags)}
            </div>
        </div>
        <div class="acp-s-5cf90edb">ID: ${mob.Mob_ID} · Region: ${mob.Region} · Pkg: ${mob.PackageID||'—'}</div>
    </div>`;

    const dropsHtml = `
    <div class="ep-tab-panel" id="eptab-drops">
        <div class="ep-section">
            <div class="ep-section-title"><?= t("mobeditor.tab.drops") ?></div>
            <table id="drop-table">
                <thead><tr><th>Item</th><th>%</th><th>#</th><th></th></tr></thead>
                <tbody id="drop-tbody">${renderDropRows(drops)}</tbody>
            </table>
            <div id="drop-add-row">
                <input id="drop-item" type="text" placeholder="ItemTemplateID">
                <input id="drop-chance" type="number" value="10" min="1" max="100">
                <input id="drop-count" type="number" value="1" min="1">
                <button id="drop-add-btn">+</button>
            </div>
        </div>
    </div>`;

    const compareHtml = `
    <div class="ep-tab-panel" id="eptab-compare">
        <div class="ep-section">
            <div class="ep-section-title">Similar Mobs</div>
            <div id="similar-mobs-list"><div class="ep-loading"><?= t("mobeditor.loading") ?></div></div>
        </div>
    </div>`;

    const aiHtml = AI_ACTIVE ? `
    <div class="ep-tab-panel" id="eptab-ai">
        <div class="ai-section">
            <div class="ai-section-title">AI Analysis</div>
            <div class="ai-btn-row">
                <button class="ai-btn" id="ai-btn-analysis" onclick="aiFullAnalysis()">Analysis</button>
                <button class="ai-btn" id="ai-btn-model"    onclick="aiSuggestModel()">Model</button>
                <button class="ai-btn" id="ai-btn-lore"     onclick="aiGenerateLore()">Lore</button>
                <button class="ai-btn" id="ai-btn-spawn"    onclick="aiSuggestSpawn()">Spawn</button>
            </div>
            <div id="ai-result"></div>
            <div id="ai-model-preview"><img id="ai-model-img" src="" alt=""><div class="ai-model-info" id="ai-model-info"></div></div>
            <div class="ai-apply-row">
                <button class="ai-apply-btn" id="ai-apply-model" onclick="aiApplyModel()">✓ Model</button>
                <button class="ai-apply-btn" id="ai-apply-spawn" onclick="aiApplySpawn()">✓ Spawn</button>
            </div>
        </div>
    </div>` : '';

    document.getElementById('ep-body').innerHTML = statsHtml + dropsHtml + compareHtml + aiHtml;

    document.getElementById('ep-model')?.addEventListener('change', e=>{
        loadMobImage(parseInt(e.target.value), selectedMobData?.Race||0);
    });
    ['ep-str','ep-con','ep-dex','ep-qui','ep-int','ep-pie','ep-cha','ep-emp'].forEach(id=>{
        const lmap={'ep-str':'STR','ep-con':'CON','ep-dex':'DEX','ep-qui':'QUI','ep-int':'INT','ep-pie':'PIE','ep-cha':'CHA','ep-emp':'EMP'};
        document.getElementById(id)?.addEventListener('input', e=>{
            const val=parseInt(e.target.value)||0;
            document.querySelectorAll('.stat-bar-wrap').forEach(row=>{
                if(row.querySelector('.stat-bar-label')?.textContent===lmap[id]){
                    const f=row.querySelector('.stat-bar-fill');
                    const v=row.querySelector('.stat-bar-val');
                    if(f) f.style.width=Math.min(100,Math.round(val/500*100))+'%';
                    if(v) v.textContent=val;
                }
            });
        });
    });
    document.getElementById('drop-add-btn')?.addEventListener('click', ()=>addDrop(mob.Name));
    const delBtn=document.getElementById('ep-delete');
    if(delBtn){delBtn.disabled=!isLab;delBtn.style.opacity=isLab?'1':'0.2';delBtn.title=isLab?'Delete mob':'Only mob_lab mobs deletable';}
    document.querySelectorAll('.ep-flag-check').forEach(cb=>{
        cb.addEventListener('change', ()=>{
            const hidden = document.getElementById('ep-flags-value');
            const bit = parseInt(cb.dataset.bit);
            let v = parseInt(hidden.value) || 0;
            v = cb.checked ? (v | bit) : (v & ~bit);
            hidden.value = v;
        });
    });
    setupNpcTemplatePicker();
}

// ── NPC Template picker ─────────────────────────────────────────
function setupNpcTemplatePicker() {
    const searchInput = document.getElementById('ep-npctemplate-search');
    const hiddenId     = document.getElementById('ep-npctemplate-id');
    const results       = document.getElementById('ep-npctemplate-results');
    if (!searchInput || !hiddenId || !results) return;

    let searchTimer = null;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = searchInput.value.trim();
        searchTimer = setTimeout(() => {
            fetch(`${CMS_URL}&action=search_npc_templates&zone_id=${currentZoneId}&q=${encodeURIComponent(q)}`)
                .then(r=>r.json()).then(data=>{
                    if (!data.success || !data.templates.length) {
                        results.innerHTML = '<div class="acp-s-56634fcb">No matches</div>';
                        results.style.display = 'block';
                        return;
                    }
                    results.innerHTML = data.templates.map(tpl => `
                        <div class="npctemplate-item acp-s-c1675029" data-id="${tpl.TemplateId}" data-name="${esc(tpl.Name)}"
                            >
                            <b>${esc(tpl.Name)}</b> <span class="acp-s-433dbce5">Lv${tpl.Level} · ${esc(tpl.ClassType||'')}</span>
                        </div>
                    `).join('');
                    results.style.display = 'block';
                    results.querySelectorAll('.npctemplate-item').forEach(el=>{
                        el.addEventListener('mouseenter', () => el.style.background = 'rgba(197,160,89,.15)');
                        el.addEventListener('mouseleave', () => el.style.background = 'transparent');
                        el.addEventListener('click', () => {
                            hiddenId.value = el.dataset.id;
                            searchInput.value = el.dataset.name;
                            results.style.display = 'none';
                        });
                    });
                }).catch(err=>console.error("NPC template search error:", err));
        }, 250);
    });

    document.addEventListener('click', e => {
        if (!searchInput.contains(e.target) && !results.contains(e.target)) {
            results.style.display = 'none';
        }
    });

    document.getElementById('ep-npctemplate-clear')?.addEventListener('click', () => {
        hiddenId.value = -1;
        searchInput.value = '';
        results.style.display = 'none';
    });
}

function renderDropRows(drops) {
    if (!drops||!drops.length) return '<tr><td colspan="4" class="acp-s-19bda548">No Drops</td></tr>';
    return drops.map(d=>`
        <tr><td title="${esc(d.ItemTemplateID||'')}">${esc((d.ItemTemplateID||'—').substring(0,16))}</td>
        <td>${d.Chance||0}%</td><td>${d.Count||1}</td>
        <td><button class="drop-del" data-id="${d.LootTemplate_ID}">✕</button></td></tr>
    `).join('');
}

// ── Similar Mobs ──────────────────────────────────────────────
function loadSimilarMobs() {
    if (!selectedMobData) return;
    const c=document.getElementById('similar-mobs-list');
    if(!c) return;
    c.innerHTML='<div class="ep-loading"><?= t("mobeditor.loading") ?></div>';
    fetch(`${CMS_URL}&action=get_similar_mobs&mob_id=${selectedMobData.Mob_ID}&level=${selectedMobData.Level}&region=${selectedMobData.Region}&zone_id=${currentZoneId}`)
        .then(r=>r.json()).then(data=>{
            if(!data.success||!data.mobs.length){c.innerHTML='<div class="ep-loading">No similar found</div>';return;}
            c.innerHTML=data.mobs.map(m=>{
                const ind=m.Strength>(selectedMobData.Strength*1.2)?'↑':m.Strength<(selectedMobData.Strength*0.8)?'↓':'≈';
                return `<div class="similar-mob-row" onclick="loadSimilarMobDetail('${m.Mob_ID}')">
                    <span class="similar-mob-level">Lv${m.Level}</span>
                    <span class="similar-mob-name">${esc(m.Name)}</span>
                    <span class="similar-mob-stats">${ind} S${m.Strength||0}</span>
                </div>`;
            }).join('');
        }).catch(err=>console.error("Similar mobs load error:", err));
}
window.loadSimilarMobDetail=function(mobId){
    fetch(`${CMS_URL}&action=get_mob_detail&mob_id=${encodeURIComponent(mobId)}&zone_id=${currentZoneId}`)
        .then(r=>r.json()).then(data=>{
            if(!data.success) return;
            const m=data.mob,c=document.getElementById('similar-mobs-list');
            c.insertAdjacentHTML('afterbegin',`<div style="background:var(--bg0);border:1px solid var(--border);padding:10px;margin-bottom:10px;font-size:0.7em;">
                <div style="font-family:'Cinzel',serif;font-size:0.85em;color:var(--gold);margin-bottom:6px;">${esc(m.Name)} (Lv${m.Level})</div>
                <div style="color:#444;font-family:monospace;line-height:1.6;">STR ${m.Strength} · CON ${m.Constitution} · DEX ${m.Dexterity} · QUI ${m.Quickness}<br>Aggro ${m.AggroLevel} · Range ${m.AggroRange} · Respawn ${m.RespawnInterval}s</div>
            </div>`);
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
};

// ── AI ────────────────────────────────────────────────────────
function aiPost(action){const fd=new FormData();fd.append('mob_id',selectedMobData?.Mob_ID||'');fd.append('zone_id',currentZoneId);return fetch(`${CMS_URL}&action=${action}&zone_id=${currentZoneId}`,{method:'POST',body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}
function aiShow(text,state='ok'){const el=document.getElementById('ai-result');if(!el)return;el.textContent=text;el.className='visible '+state;}
function resetAiPanel(){const el=document.getElementById('ai-result');if(el){el.className='';el.textContent='';}document.getElementById('ai-model-preview')?.classList.remove('visible');document.querySelectorAll('.ai-apply-btn').forEach(b=>b.classList.remove('visible'));ai_last={};}
window.aiFullAnalysis=async function(){if(!selectedMobData)return;const btn=document.getElementById('ai-btn-analysis');if(btn)btn.disabled=true;aiShow('Analyzing…','loading');try{const r=await aiPost('ai_full_analysis');const d=await r.json();if(btn)btn.disabled=false;d.status==='ok'?aiShow(d.result?.suggestion||'No analysis available.'):aiShow('Error: '+(d.message||'?'),'err');}catch(e){if(btn)btn.disabled=false;aiShow('Error: '+e,'err');}};
window.aiSuggestModel=async function(){if(!selectedMobData)return;const btn=document.getElementById('ai-btn-model');if(btn)btn.disabled=true;aiShow('Search model…','loading');document.getElementById('ai-model-preview')?.classList.remove('visible');try{const r=await aiPost('ai_suggest_model');const d=await r.json();if(btn)btn.disabled=false;if(d.status==='ok'){const s=d.result?.suggestion||'';aiShow(s);try{const m=s.match(/\{[\s\S]*\}/);if(m){const p=JSON.parse(m[0]);ai_last.model=p;const prev=document.getElementById('ai-model-preview');const img=document.getElementById('ai-model-img');const info=document.getElementById('ai-model-info');if(prev&&img&&info){img.src=MOB_IMG(p.model_id);info.innerHTML=`<strong>Model #${p.model_id}</strong><br>${esc(p.model_name||'')}<br><span style="color:#333;">${esc((p.reasoning||'').substring(0,80))}</span>`;prev.classList.add('visible');}document.getElementById('ai-apply-model')?.classList.add('visible');}}catch(e){}}else aiShow('Error: '+(d.message||'?'),'err');}catch(e){if(btn)btn.disabled=false;aiShow('Error: '+e,'err');}};
window.aiGenerateLore=async function(){if(!selectedMobData)return;const btn=document.getElementById('ai-btn-lore');if(btn)btn.disabled=true;aiShow('Generating lore…','loading');try{const r=await aiPost('ai_generate_lore');const d=await r.json();if(btn)btn.disabled=false;d.status==='ok'?aiShow(d.result?.suggestion||'—'):aiShow('Error: '+(d.message||'?'),'err');}catch(e){if(btn)btn.disabled=false;aiShow('Error: '+e,'err');}};
window.aiSuggestSpawn=async function(){if(!selectedMobData)return;const btn=document.getElementById('ai-btn-spawn');if(btn)btn.disabled=true;aiShow('Analysing spawn…','loading');try{const r=await aiPost('ai_mob_spawn');const d=await r.json();if(btn)btn.disabled=false;if(d.status==='ok'){const s=d.result?.suggestion||'';aiShow(s);try{const m=s.match(/\{[\s\S]*\}/);if(m){ai_last.spawn=JSON.parse(m[0]);document.getElementById('ai-apply-spawn')?.classList.add('visible');}}catch(e){}}else aiShow('Error: '+(d.message||'?'),'err');}catch(e){if(btn)btn.disabled=false;aiShow('Error: '+e,'err');}};
window.aiApplyModel=function(){if(!ai_last.model)return;const el=document.getElementById('ep-model');if(el){el.value=ai_last.model.model_id;loadMobImage(ai_last.model.model_id,selectedMobData?.Race||0);}showToast('Model #'+ai_last.model.model_id+' <?= addslashes(t("mobeditor.toast.applied")) ?>',false,true);document.getElementById('ai-apply-model')?.classList.remove('visible');};
window.aiApplySpawn=function(){if(!ai_last.spawn)return;const s=ai_last.spawn;if(s.respawn!==undefined){const el=document.getElementById('ep-respawn');if(el)el.value=s.respawn;}if(s.aggro_level!==undefined){const el=document.getElementById('ep-aggro');if(el)el.value=s.aggro_level;}if(s.aggro_range!==undefined){const el=document.getElementById('ep-range');if(el)el.value=s.aggro_range;}showToast('Spawn applied',false,true);document.getElementById('ai-apply-spawn')?.classList.remove('visible');ai_last.spawn=null;};

// ── Drops ─────────────────────────────────────────────────────
function addDrop(mobName){const item=document.getElementById('drop-item')?.value.trim();const chance=document.getElementById('drop-chance')?.value;const count=document.getElementById('drop-count')?.value;if(!item){showToast('Enter ItemTemplateID',true);return;}fetch(`${CMS_URL}&action=add_drop&zone_id=${currentZoneId}`,{method:'POST',body:new URLSearchParams({mob_name:mobName,item_template:item,chance,count})}).then(r=>r.json()).then(d=>{if(d.success){showToast('Drop added',false,true);if(document.getElementById('drop-item'))document.getElementById('drop-item').value='';fetch(`${CMS_URL}&action=get_mob_detail&mob_id=${encodeURIComponent(selectedMobData.Mob_ID)}&zone_id=${currentZoneId}`).then(r=>r.json()).then(data=>{if(data.success){const tb=document.getElementById('drop-tbody');if(tb)tb.innerHTML=renderDropRows(data.drops);}}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}else showToast(d.error||'<?= addslashes(t("mobeditor.error.generic")) ?>',true);}).catch(err=>console.error("Add drop error:", err));}
document.getElementById('edit-panel').addEventListener('click',e=>{const btn=e.target.closest('.drop-del');if(!btn)return;const lootId=btn.dataset.id;if(!lootId||!confirm('Remove drop?'))return;fetch(`${CMS_URL}&action=delete_drop&zone_id=${currentZoneId}`,{method:'POST',body:new URLSearchParams({loot_id:lootId})}).then(r=>r.json()).then(d=>{if(d.success){btn.closest('tr')?.remove();showToast('Drop removed',false,true);}}).catch(err=>console.error("Delete drop error:", err));});

// ── Save / Delete ─────────────────────────────────────────────
document.getElementById('ep-save').addEventListener('click',()=>{
    if(!selectedMobData)return;
    const val=id=>document.getElementById(id)?.value;
    const body=new URLSearchParams({
        mob_id:selectedMobData.Mob_ID,
        name:val('ep-name')||'',
        level:val('ep-level')||1,
        model:val('ep-model')||1,
        size:val('ep-size')||50,
        speed:val('ep-speed')||0,
        aggro_level:val('ep-aggro')||0,
        aggro_range:val('ep-range')||500,
        respawn:val('ep-respawn')||120,
        str:val('ep-str')||30, con:val('ep-con')||30, dex:val('ep-dex')||30, qui:val('ep-qui')||30,
        int:val('ep-int')||30, pie:val('ep-pie')||30, cha:val('ep-cha')||30, emp:val('ep-emp')||30,
        suffix:val('ep-suffix')||'',
        guild_name:val('ep-guild')||'',
        examine_article:val('ep-examine')||'',
        message_article:val('ep-message')||'',
        realm:val('ep-realm')||0,
        race:val('ep-race')||0,
        gender:val('ep-gender')||0,
        damage_type:val('ep-damagetype')||0,
        body_type:val('ep-bodytype')||0,
        max_distance:val('ep-maxdist')||0,
        roaming_range:val('ep-roaming')??-1,
        flags:val('ep-flags-value')||0,
        npctemplate_id:val('ep-npctemplate-id')??-1,
    });
    fetch(`${CMS_URL}&action=update_mob&zone_id=${currentZoneId}`,{method:'POST',body})
        .then(r=>r.json()).then(d=>{
            if(d.success){showToast('✓ Saved',false,true);document.getElementById('ep-subtitle').textContent=val('ep-name')||'';}
            else showToast(d.error||'<?= addslashes(t("mobeditor.error.generic")) ?>',true);
        }).catch(err=>console.error("Save failed:", err));
});
document.getElementById('ep-delete').addEventListener('click',()=>{if(!selectedMobData||selectedMobData.PackageID!=='mob_lab')return;if(!confirm(`<?= addslashes(t("mobeditor.confirm.delete_prefix")) ?> "${selectedMobData.Name}"?`))return;fetch(`${CMS_URL}&action=delete_mob&zone_id=${currentZoneId}`,{method:'POST',body:new URLSearchParams({mob_id:selectedMobData.Mob_ID})}).then(()=>{document.getElementById('edit-panel').classList.add('collapsed');loadZone(currentZoneId);}).catch(err=>console.error("Delete call failed:", err));});
document.getElementById('ep-fav-btn').addEventListener('click',()=>{if(!selectedMobData)return;toggleFav(selectedMobData.Mob_ID,selectedMobData.Name,selectedMobData.Model,selectedMobData.Level);});

// ── Heatmap ───────────────────────────────────────────────────
function renderHeatmap(){if(heatmapLayer){map.removeLayer(heatmapLayer);heatmapLayer=null;}fetch(`${CMS_URL}&action=get_heatmap&zone_id=${currentZoneId}`).then(r=>r.json()).then(data=>{if(!data.success)return;const cellSize=256,grid={};let maxVal=0;data.points.forEach(p=>{const cx=Math.floor(p.lx/cellSize),cy=Math.floor(p.ly/cellSize),key=`${cx},${cy}`;grid[key]=(grid[key]||0)+1;if(grid[key]>maxVal)maxVal=grid[key];});if(!maxVal)return;const layers=[];Object.entries(grid).forEach(([key,val])=>{const[cx,cy]=key.split(',').map(Number);const x=cx*cellSize,y=cy*cellSize,intensity=val/maxVal;const r=Math.round(intensity<0.5?0:(intensity-0.5)*2*255);const g=Math.round(intensity<0.5?intensity*2*200:(1-(intensity-0.5)*2)*200);const b=Math.round(intensity<0.5?(1-intensity*2)*255:0);layers.push(L.rectangle([[y,x],[y+cellSize,x+cellSize]],{color:'transparent',fillColor:`rgb(${r},${g},${b})`,fillOpacity:0.15+intensity*0.5,weight:0}));});heatmapLayer=L.layerGroup(layers).addTo(map);document.getElementById('heatmap-legend').classList.add('visible');}).catch(err=>console.error("Heatmap load fail:", err));}
document.getElementById('btn-heatmap').addEventListener('click',()=>{heatmapActive=!heatmapActive;document.getElementById('btn-heatmap').classList.toggle('active',heatmapActive);if(heatmapActive){renderHeatmap();}else{if(heatmapLayer){map.removeLayer(heatmapLayer);heatmapLayer=null;}document.getElementById('heatmap-legend').classList.remove('visible');}});

// ── Favourites Panel ──────────────────────────────────────────
document.getElementById('btn-favs').addEventListener('click',()=>{const p=document.getElementById('fav-panel');p.classList.toggle('open');});
document.getElementById('fav-panel-close').addEventListener('click',()=>{document.getElementById('fav-panel').classList.remove('open');});

// ── Spawn Modal (right-click) ─────────────────────────────────
map.on('contextmenu',e=>{
    pendingCoords.x=Math.round(e.latlng.lng);
    pendingCoords.y=flipY(Math.round(e.latlng.lat));
    if(calMode) return; 
    document.getElementById('f-model').value=408;
    document.getElementById('f-name').value='Mob Lab Specimen';
    document.getElementById('modal-preview-img').src=MOB_IMG(408);
    document.getElementById('modal-preview-name').textContent='Mob Lab Specimen';
    document.getElementById('f-z').value='…';
    document.getElementById('z-source-badge').textContent='(detecting…)';
    document.getElementById('z-auto-hint').textContent='';
    if(currentZoneMeta){
        const gx=(parseInt(currentZoneMeta.OffsetX)*TILE)+pendingCoords.x;
        const gy=(parseInt(currentZoneMeta.OffsetY)*TILE)+pendingCoords.y;
        document.getElementById('coord-preview').textContent=
            `Leaflet (${pendingCoords.x}, ${pendingCoords.y}) → Global (${gx}, ${gy})`;
    }
    document.getElementById('modal-overlay').classList.add('open');

    fetch(`${CMS_URL}&action=get_nearest_z&zone_id=${currentZoneId}&lx=${pendingCoords.x}&ly=${pendingCoords.y}`)
        .then(r=>r.json()).then(d=>{
            if(d.success){
                document.getElementById('f-z').value = d.z;
                const src = d.source==='calibration' ? '📍 from calibration point' :
                            d.source==='terrain'      ? '🗺 TerrainService' :
                            d.source==='nearest_mob'  ? '🎯 from nearest mob' : '⚙ Default';
                document.getElementById('z-source-badge').textContent = `(${src})`;
                document.getElementById('z-auto-hint').textContent =
                    d.source==='terrain'      ? '✓ Exact Height (Client-Data)' :
                    d.source==='nearest_mob'  ? 'Tip: Inaccurate? use Z-Calibration!' :
                    d.source==='calibration'  ? '✓ Calibrated value' : '';
            }
        }).catch(() => {
            document.getElementById('f-z').value = 2500;
            document.getElementById('z-source-badge').textContent = '(⚙ Fallback)';
        });
});
document.getElementById('f-model').addEventListener('change',e=>{const id=parseInt(e.target.value)||408;document.getElementById('modal-preview-img').src=MOB_IMG(id);});
document.getElementById('modal-confirm').addEventListener('click',()=>{
    const name = document.getElementById('f-name').value;
    const body=new URLSearchParams({name,level:document.getElementById('f-level').value,model:document.getElementById('f-model').value,z_hint:document.getElementById('f-z').value,x:pendingCoords.x,y:pendingCoords.y});
    fetch(`${CMS_URL}&action=add_mob&zone_id=${currentZoneId}`,{method:'POST',body})
        .then(r=>r.json()).then(d=>{
            document.getElementById('modal-overlay').classList.remove('open');
            if (d.success && d.id) {
                pushUndo([d.id], `Spawn "${name}"`);
                showToast(`✓ ${name} spawned (Z:${d.z_used||'?'})`,false,true);
            } else {
                showToast('Mob spawned!',false,true);
            }
            loadZone(currentZoneId);
        }).catch(err=>console.error("Spawn fail:", err));
});
document.getElementById('modal-cancel').addEventListener('click',()=>document.getElementById('modal-overlay').classList.remove('open'));
document.getElementById('zone-select').addEventListener('change',e=>loadZone(parseInt(e.target.value)));
document.getElementById('btn-reload').addEventListener('click',()=>loadZone(currentZoneId));

// ── UNDO Stack ────────────────────────────────────────────────
let undoStack = []; 

function pushUndo(ids, label) {
    undoStack.push({ids, label});
    if (undoStack.length > 20) undoStack.shift();
    updateUndoBtn();
}
function updateUndoBtn() {
    const btn = document.getElementById('btn-undo');
    if (undoStack.length) {
        const last = undoStack[undoStack.length-1];
        btn.style.color = 'var(--red)';
        btn.title = `Undo: ${last.label} (${last.ids.length} mob${last.ids.length>1?'s':''})`;
    } else {
        btn.style.color = '';
        btn.title = 'Nothing to undo';
    }
}
document.getElementById('btn-undo').addEventListener('click', ()=>{
    if (!undoStack.length) { showToast('Nothing to undo', true); return; }
    const last = undoStack.pop();
    updateUndoBtn();
    fetch(`${CMS_URL}&action=undo&zone_id=${currentZoneId}`, {
        method:'POST', body: new URLSearchParams({ids: JSON.stringify(last.ids)})
    }).then(r=>r.json()).then(d=>{
        if (d.success) { showToast(`↩ ${last.label} undone (${d.deleted} Mobs)`, false, true); loadZone(currentZoneId); }
        else showToast('Undo failed', true);
    }).catch(err=>console.error("Undo server call fail:", err));
});

// ── /loc Import ───────────────────────────────────────────────
document.getElementById('btn-loc-import').addEventListener('click', ()=>{
    document.getElementById('loc-modal-overlay').classList.add('open');
    document.getElementById('loc-input').value = '';
    document.getElementById('loc-parsed').style.display = 'none';
    setTimeout(()=>document.getElementById('loc-input').focus(), 100);
});
document.getElementById('loc-input').addEventListener('input', e=>{
    const val = e.target.value;
    const m = val.match(/X[:\s]+(\d+)\s+Y[:\s]+(\d+)\s+Z[:\s]+(\d+)/i);
    const parsed = document.getElementById('loc-parsed');
    if (m) {
        const gx=parseInt(m[1]), gy=parseInt(m[2]), gz=parseInt(m[3]);
        const lx = currentZoneMeta ? gx - (parseInt(currentZoneMeta.OffsetX)*TILE) : gx;
        const ly = currentZoneMeta ? gy - (parseInt(currentZoneMeta.OffsetY)*TILE) : gy;
        parsed.style.display = 'block';
        parsed.style.color = '#50c878';
        parsed.textContent = `✓ Global: (${gx}, ${gy}, Z:${gz}) → Map: (${lx}, ${ly})`;
        parsed._lx = lx; parsed._ly = ly; parsed._gz = gz;
    } else if (val.length > 5) {
        parsed.style.display = 'block';
        parsed.style.color = 'var(--red)';
        parsed.textContent = '✗ Format not recognized. Expected: X:12345 Y:67890 Z:1234';
        parsed._lx = null;
    } else { parsed.style.display = 'none'; }
});
document.getElementById('loc-confirm').addEventListener('click', ()=>{
    const parsed = document.getElementById('loc-parsed');
    if (parsed._lx === null || parsed._lx === undefined) { showToast('<?= addslashes(t("mobeditor.error.invalid_coords")) ?>', true); return; }
    pendingCoords.x = parsed._lx;
    pendingCoords.y = parsed._ly;
    document.getElementById('loc-modal-overlay').classList.remove('open');
    document.getElementById('f-model').value = 408;
    document.getElementById('f-name').value  = 'Mob Lab Specimen';
    document.getElementById('modal-preview-img').src = MOB_IMG(408);
    document.getElementById('f-z').value = parsed._gz;
    document.getElementById('z-source-badge').textContent = '(📍 from /loc)';
    if (currentZoneMeta) {
        const gx=(parseInt(currentZoneMeta.OffsetX)*TILE)+pendingCoords.x;
        const gy=(parseInt(currentZoneMeta.OffsetY)*TILE)+pendingCoords.y;
        document.getElementById('coord-preview').textContent=`Leaflet (${pendingCoords.x},${pendingCoords.y}) → Global (${gx},${gy})`;
    }
    document.getElementById('modal-overlay').classList.add('open');
    map.panTo([flipY(pendingCoords.y), pendingCoords.x]);
});
document.getElementById('loc-cancel').addEventListener('click', ()=>document.getElementById('loc-modal-overlay').classList.remove('open'));

// ── Group Spawn ───────────────────────────────────────────────
document.getElementById('btn-group-spawn').addEventListener('click', ()=>{
    if (!pendingCoords.x && !pendingCoords.y) {
        showToast('Right-click the map first to set position!', true); return;
    }
    document.getElementById('group-modal-overlay').classList.add('open');
    document.getElementById('group-coord-preview').textContent =
        `Center: Leaflet (${pendingCoords.x}, ${pendingCoords.y})`;
    document.getElementById('group-preview-img').src = MOB_IMG(408);
});
document.getElementById('g-model').addEventListener('change', e=>{
    document.getElementById('group-preview-img').src = MOB_IMG(parseInt(e.target.value)||408);
});
document.getElementById('group-confirm').addEventListener('click', ()=>{
    const body = new URLSearchParams({
        name:      document.getElementById('g-name').value,
        level:     document.getElementById('g-level').value,
        model:     document.getElementById('g-model').value,
        count:     document.getElementById('g-count').value,
        formation: document.getElementById('g-formation').value,
        spacing:   document.getElementById('g-spacing').value,
        cx: pendingCoords.x, cy: pendingCoords.y
    });
    fetch(`${CMS_URL}&action=group_spawn&zone_id=${currentZoneId}`,{method:'POST',body})
        .then(r=>r.json()).then(d=>{
            if (d.success) {
                document.getElementById('group-modal-overlay').classList.remove('open');
                showToast(`✓ ${d.spawned} mobs spawned (Z:${d.z})`, false, true);
                pushUndo(d.ids, `<?= addslashes(t("mobeditor.group.label")) ?> "${document.getElementById('g-name').value}" ×${d.spawned}`);
                loadZone(currentZoneId);
            } else showToast('Group spawn failed', true);
        }).catch(err=>console.error("Group spawn error:", err));
});
document.getElementById('group-cancel').addEventListener('click', ()=>document.getElementById('group-modal-overlay').classList.remove('open'));

const origModalConfirm = document.getElementById('modal-confirm');
origModalConfirm.addEventListener('click', ()=>{
    sessionStorage.setItem('pendingUndoName', document.getElementById('f-name').value);
});

// ── Online Players ────────────────────────────────────────────
let playersLayer  = L.layerGroup().addTo(map);
let playersActive = false;
let playersTimer  = null;

document.getElementById('btn-players').addEventListener('click', ()=>{
    playersActive = !playersActive;
    document.getElementById('btn-players').classList.toggle('active', playersActive);
    if (playersActive) { loadPlayers(); playersTimer = setInterval(loadPlayers, 10000); }
    else { clearInterval(playersTimer); playersLayer.clearLayers(); showToast('Player tracker disabled'); }
});

function loadPlayers() {
    fetch(`${CMS_URL}&action=get_players&zone_id=${currentZoneId}`)
        .then(r=>r.json()).then(d=>{
            playersLayer.clearLayers();
            if (!d.success || !d.players?.length) return;
            d.players.forEach(p=>{
                if (p.lx<0||p.lx>ZSIZE||p.ly<0||p.ly>ZSIZE) return;
                const icon = L.divIcon({html:`<div class="player-marker"></div>`,className:'',iconSize:[14,14],iconAnchor:[7,7]});
                const m = L.marker([flipY(p.ly),p.lx],{icon,zIndexOffset:1000}).addTo(playersLayer);
                m.bindTooltip(`🟢 <b>${esc(p.Name)}</b> · Lv${p.Level||'?'}<br>${esc(p.Class||'')}`,{direction:'top',permanent:false});
                m.on('click',()=>{
                    pendingCoords.x = p.lx; pendingCoords.y = p.ly;
                    document.getElementById('f-name').value = 'Mob Lab Specimen';
                    document.getElementById('f-model').value = 408;
                    document.getElementById('modal-preview-img').src = MOB_IMG(408);
                    document.getElementById('modal-overlay').classList.add('open');
                    showToast(`Spawn near ${p.Name}`);
                });
            });
            showToast(`👥 ${d.players.length} players online in this zone`);
        }).catch(err=>console.error("Player data fetch fail:", err));
}

// ── Map Modes ─────────────────────────────────────────────────
let activeMapModes = new Set();
let aggroLayer     = L.layerGroup();
let lvlHeatLayer   = null;
let patrolDrawLayer= L.layerGroup().addTo(map);
let measureLayer   = L.layerGroup().addTo(map);
let measurePoints  = [];
let patrolPoints   = [];
let isRecordingPatrol = false;

window.toggleMapMode = function(mode) {
    const btn = document.getElementById(`mm-${mode}`);
    if (activeMapModes.has(mode)) {
        activeMapModes.delete(mode);
        btn?.classList.remove('active');
        disableMapMode(mode);
    } else {
        activeMapModes.add(mode);
        btn?.classList.add('active');
        enableMapMode(mode);
    }
};

function enableMapMode(mode) {
    if (mode === 'aggro') {
        aggroLayer.addTo(map);
        renderAggroCircles();
        showToast('🎯 Aggro radii enabled');
    } else if (mode === 'lvlheat') {
        renderLevelHeatmap();
        document.getElementById('lvl-legend')?.classList.add('visible');
    } else if (mode === 'patrol') {
        document.getElementById('patrol-panel').classList.add('open');
        loadPatrolPaths();
    } else if (mode === 'measure') {
        showToast('📏 Click point 1 on the map');
        measurePoints = [];
        measureLayer.clearLayers();
    }
}

function disableMapMode(mode) {
    if (mode === 'aggro') { map.removeLayer(aggroLayer); aggroLayer.clearLayers(); }
    else if (mode === 'lvlheat') { if(lvlHeatLayer){map.removeLayer(lvlHeatLayer);lvlHeatLayer=null;} document.getElementById('lvl-legend')?.classList.remove('visible'); }
    else if (mode === 'patrol') { document.getElementById('patrol-panel').classList.remove('open'); }
    else if (mode === 'measure') { measurePoints=[]; measureLayer.clearLayers(); document.getElementById('measure-tooltip').style.display='none'; }
}

function renderAggroCircles() {
    aggroLayer.clearLayers();
    allMobsData.filter(m=>m.lx>=0&&m.ly>=0&&(m.AggroRange||0)>0).forEach(mob=>{
        const r = mob.AggroRange || 500;
        L.circle([flipY(mob.ly), mob.lx], {
            radius: r, className:'aggro-circle', renderer,
            color:'rgba(255,80,80,0.5)', fillColor:'rgba(255,80,80,0.04)', fillOpacity:1, weight:1
        }).addTo(aggroLayer).bindTooltip(`${esc(mob.Name)}: Aggro ${r}u`,{direction:'top'});
    });
}

function renderLevelHeatmap() {
    if(lvlHeatLayer){map.removeLayer(lvlHeatLayer);lvlHeatLayer=null;}
    if(!allMobsData.length) return;
    const cellSize=256, grid={}, gridLvl={};
    allMobsData.filter(m=>m.lx>=0&&m.ly>=0).forEach(m=>{
        const cx=Math.floor(m.lx/cellSize),cy=Math.floor(m.ly/cellSize),k=`${cx},${cy}`;
        grid[k]=(grid[k]||0)+1;
        gridLvl[k]=((gridLvl[k]||0)*(grid[k]-1)+(m.Level||1))/grid[k]; 
    });
    const maxLvl=75, layers=[];
    Object.entries(gridLvl).forEach(([k,avgLvl])=>{
        const[cx,cy]=k.split(',').map(Number);
        const pct=avgLvl/maxLvl;
        const r=Math.round(pct<0.5?68+(pct*2*(255-68)):255);
        const g=Math.round(pct<0.5?136+(pct*2*(200-136)):Math.max(0,200-(pct-0.5)*2*200));
        const b=Math.round(pct<0.5?212-(pct*2*212):0);
        layers.push(L.rectangle([[cy*cellSize,cx*cellSize],[(cy+1)*cellSize,(cx+1)*cellSize]],
            {color:'transparent',fillColor:`rgb(${r},${g},${b})`,fillOpacity:0.35,weight:0}));
    });
    lvlHeatLayer=L.layerGroup(layers).addTo(map);
}

// ── Patrol System ─────────────────────────────────────────────
const patrolNpcState = {main:null, edit2d:null};
const patrolNpcPickers = {
    main: {
        input:'patrol-npc-search', hidden:'patrol-npc-id',
        results:'patrol-npc-results', selected:'patrol-npc-selected'
    },
    edit2d: {
        input:'e2d-patrol-npc-search', hidden:'e2d-patrol-npc-id',
        results:'e2d-patrol-npc-results', selected:'e2d-patrol-npc-selected'
    }
};

function resetPatrolNpcPicker(key) {
    const cfg = patrolNpcPickers[key];
    if (!cfg) return;
    patrolNpcState[key] = null;
    const input = document.getElementById(cfg.input);
    const hidden = document.getElementById(cfg.hidden);
    const results = document.getElementById(cfg.results);
    const selected = document.getElementById(cfg.selected);
    if (input) input.value = '';
    if (hidden) hidden.value = '';
    if (results) { results.innerHTML = ''; results.classList.remove('open'); }
    if (selected) { selected.textContent = patrolText('noNpcSelected'); selected.classList.remove('has-selection'); }
}

function setupPatrolNpcPicker(key) {
    const cfg = patrolNpcPickers[key];
    const input = document.getElementById(cfg.input);
    const hidden = document.getElementById(cfg.hidden);
    const results = document.getElementById(cfg.results);
    const selected = document.getElementById(cfg.selected);
    let timer = null;

    const search = () => {
        const query = input.value.trim();
        fetch(`${CMS_URL}&action=search_patrol_npcs&zone_id=${currentZoneId}&q=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(d => {
                const npcs = d.success ? (d.npcs || []) : [];
                if (!npcs.length) {
                    results.innerHTML = `<div class="patrol-npc-result">${esc(patrolText('noNpcsFound'))}</div>`;
                    results.classList.add('open');
                    return;
                }
                results.innerHTML = npcs.map((npc, index) => {
                    const guild = npc.Guild ? ` · ${esc(npc.Guild)}` : '';
                    const route = npc.PathID ? ` · Route: ${esc(npc.PathID)}` : '';
                    return `<div class="patrol-npc-result" data-index="${index}">
                        <strong>${esc(npc.Name)}</strong>
                        <span class="patrol-npc-meta">Lv${esc(npc.Level)} · Realm ${esc(npc.Realm)}${guild}${route}</span>
                    </div>`;
                }).join('');
                results.classList.add('open');
                results.querySelectorAll('.patrol-npc-result[data-index]').forEach(item => {
                    item.addEventListener('click', () => {
                        const npc = npcs[parseInt(item.dataset.index, 10)];
                        patrolNpcState[key] = npc;
                        hidden.value = npc.Mob_ID;
                        input.value = npc.Name;
                        selected.textContent = npc.PathID
                            ? patrolText('selectedRoute', {name:npc.Name, route:npc.PathID})
                            : patrolText('selected', {name:npc.Name, level:npc.Level});
                        selected.classList.add('has-selection');
                        results.classList.remove('open');
                    });
                });
            })
            .catch(() => {
                results.innerHTML = `<div class="patrol-npc-result">${esc(patrolText('searchFailed'))}</div>`;
                results.classList.add('open');
            });
    };

    input.addEventListener('input', () => {
        patrolNpcState[key] = null;
        hidden.value = '';
        selected.textContent = patrolText('noNpcSelected');
        selected.classList.remove('has-selection');
        clearTimeout(timer);
        timer = setTimeout(search, 180);
    });
    input.addEventListener('focus', () => {
        clearTimeout(timer);
        timer = setTimeout(search, 0);
    });
    document.addEventListener('click', event => {
        if (!input.contains(event.target) && !results.contains(event.target)) {
            results.classList.remove('open');
        }
    });
}

setupPatrolNpcPicker('main');
setupPatrolNpcPicker('edit2d');

document.getElementById('patrol-panel-close').addEventListener('click',()=>{
    document.getElementById('patrol-panel').classList.remove('open');
    activeMapModes.delete('patrol');
    document.getElementById('mm-patrol')?.classList.remove('active');
});

document.getElementById('patrol-record-btn').addEventListener('click',()=>{
    isRecordingPatrol = !isRecordingPatrol;
    const btn = document.getElementById('patrol-record-btn');
    const saveBtn = document.getElementById('patrol-save-btn');
    if (isRecordingPatrol) {
        patrolPoints = [];
        btn.textContent = '⬛ Stop';
        btn.style.borderColor = 'rgba(255,80,80,0.5)';
        btn.style.color = 'var(--red)';
        saveBtn.style.display = 'block';
        showToast('● Recording — click points on the map');
    } else {
        btn.textContent = '● Record';
        btn.style.borderColor = 'rgba(80,200,120,0.4)';
        btn.style.color = '#50c878';
        document.getElementById('patrol-point-count').textContent = `${patrolPoints.length} points recorded`;
    }
});

document.getElementById('patrol-save-btn').addEventListener('click',()=>{
    if (patrolPoints.length < 2) { showToast(patrolText('recordMin'), true); return; }
    const npcId = document.getElementById('patrol-npc-id').value;
    if (!npcId) { showToast(patrolText('selectNpc'), true); return; }
    const label = document.getElementById('patrol-label').value.trim();
    if (!label) { showToast(patrolText('enterName'), true); return; }
    const saveBtn = document.getElementById('patrol-save-btn');
    saveBtn.disabled = true;
    fetch(`${CMS_URL}&action=save_patrol_path&zone_id=${currentZoneId}`,{
        method:'POST',body:new URLSearchParams({
            path:JSON.stringify(patrolPoints), label, npc_id:npcId,
            path_type:document.getElementById('patrol-path-type').value,
            csrf_token:CSRF_TOKEN
        })
    }).then(r=>r.json()).then(d=>{
        if (!d.success) { showToast(d.error || patrolText('saveFailed'), true); return; }
        showToast(patrolText('assigned', {route:label, npc:d.npc_name}), false, true);
        isRecordingPatrol = false;
        patrolPoints = [];
        document.getElementById('patrol-label').value = '';
        document.getElementById('patrol-point-count').textContent = '';
        document.getElementById('patrol-save-btn').style.display = 'none';
        document.getElementById('patrol-record-btn').textContent = '● Record';
        resetPatrolNpcPicker('main');
        loadPatrolPaths();
        if (edit2dMap) loadEdit2dPatrolRoutes();
    }).catch(err=>{
        console.error("Save patrol fail:", err);
        showToast(patrolText('saveFailed'), true);
    }).finally(()=>{ saveBtn.disabled = false; });
});

document.getElementById('patrol-clear-btn').addEventListener('click',()=>{
    patrolPoints=[]; isRecordingPatrol=false;
    patrolDrawLayer.clearLayers();
    document.getElementById('patrol-point-count').textContent='';
    document.getElementById('patrol-record-btn').textContent='● Record';
    document.getElementById('patrol-record-btn').style.color='#50c878';
    document.getElementById('patrol-save-btn').style.display='none';
    loadPatrolPaths();
});

function loadPatrolPaths() {
    fetch(`${CMS_URL}&action=get_patrol_paths&zone_id=${currentZoneId}`)
        .then(r=>r.json()).then(d=>renderPatrolPaths(d.paths||[]))
        .catch(err=>console.error("Patrol paths load fail:", err));
}

function renderPatrolPaths(paths) {
    patrolDrawLayer.clearLayers();
    const list = document.getElementById('patrol-list');
    if (!paths.length) { list.innerHTML=`<div class="acp-s-1d33c57a">${esc(patrolText('noRoutes'))}</div>`; }
    else {
        list.innerHTML = paths.map((p,i)=>`
            <div class="patrol-item">
                <span class="patrol-item-name">${esc(p.label)}<small>${esc((p.npcs||[]).map(n=>n.name).join(', '))}</small></span>
                <span class="patrol-item-pts">${p.points.length}pts</span>
                <button class="patrol-item-del" data-index="${i}">✕</button>
            </div>
        `).join('');
        list.querySelectorAll('.patrol-item-del').forEach(button => {
            button.addEventListener('click', () => deletePatrol(paths[parseInt(button.dataset.index, 10)].path_id));
        });
    }
    paths.forEach(p=>{
        if(p.points.length<2) return;
        const lls = p.points.map(pt=>[flipY(pt.ly ?? pt.y ?? 0), pt.lx ?? pt.x ?? 0]);
        const npcNames = (p.npcs||[]).map(n=>n.name).join(', ');
        L.polyline(lls,{color:'rgba(197,160,89,0.6)',weight:2,dashArray:'8,4'})
         .addTo(patrolDrawLayer).bindTooltip(`🛣 ${esc(p.label)} · ${esc(npcNames)}`,{permanent:false});
        lls.forEach(ll=>L.circleMarker(ll,{radius:4,color:'var(--gold)',fillColor:'var(--gold)',fillOpacity:0.8,weight:1}).addTo(patrolDrawLayer));
    });
}

window.deletePatrol = function(pathId) {
    if(!confirm(patrolText('deleteConfirm', {route:pathId}))) return;
    fetch(`${CMS_URL}&action=delete_patrol_path&zone_id=${currentZoneId}`,{
        method:'POST',body:new URLSearchParams({path_id:pathId, csrf_token:CSRF_TOKEN})
    }).then(r=>r.json()).then(d=>{
        if (!d.success) { showToast(d.error || patrolText('deleteFailed'), true); return; }
        loadPatrolPaths();
        if (edit2dMap) loadEdit2dPatrolRoutes();
        showToast(patrolText('deleted', {route:pathId}), false, true);
    }).catch(err=>{
        console.error("Delete patrol fail:", err);
        showToast(patrolText('deleteFailed'), true);
    });
};

// ── Consolidated Map Click Handler ───────────────────────────
map.on('click', e=>{
    const pt = {lx: Math.round(e.latlng.lng), ly: flipY(Math.round(e.latlng.lat))};

    if (activeMapModes.has('measure')) {
        measurePoints.push(pt);
        const icon = L.divIcon({html:`<div class="acp-s-1dbf6f21"></div>`,className:'',iconSize:[10,10],iconAnchor:[5,5]});
        L.marker([flipY(pt.ly),pt.lx],{icon}).addTo(measureLayer);
        if (measurePoints.length===2) {
            const p1=measurePoints[0],p2=measurePoints[1];
            L.polyline([[flipY(p1.ly),p1.lx],[flipY(p2.ly),p2.lx]],{color:'rgba(197,160,89,0.8)',weight:2,dashArray:'6,4'}).addTo(measureLayer);
            fetch(`${CMS_URL}&action=measure&zone_id=${currentZoneId}&x1=${p1.lx}&y1=${p1.ly}&x2=${p2.lx}&y2=${p2.ly}`)
                .then(r=>r.json()).then(d=>{
                    if(d.success){
                        L.marker([flipY((p1.ly+p2.ly)/2),(p1.lx+p2.lx)/2],{
                            icon:L.divIcon({html:`<div class="acp-s-2e06cf4e">📏 ${d.dist.toLocaleString()} u · ~${d.travel_sec}s</div>`,className:'',iconAnchor:[60,10]})
                        }).addTo(measureLayer);
                        showToast(`📏 ${d.dist.toLocaleString()} units · ~${d.travel_sec}s travel`);
                    }
                }).catch(err=>console.error("Measure action error:", err));
            measurePoints=[];
        } else {
            showToast(`📏 Point 1 set — now click point 2`);
        }
        return; 
    }

    if (isRecordingPatrol) {
        patrolPoints.push(pt);
        L.circleMarker([flipY(pt.ly),pt.lx],{radius:5,color:'#50c878',fillColor:'#50c878',fillOpacity:0.8,weight:1}).addTo(patrolDrawLayer);
        if(patrolPoints.length>=2){
            const l2=[patrolPoints[patrolPoints.length-2],patrolPoints[patrolPoints.length-1]];
            L.polyline([[flipY(l2[0].ly),l2[0].lx],[flipY(l2[1].ly),l2[1].lx]],{color:'rgba(80,200,120,0.5)',weight:2,dashArray:'5,3'}).addTo(patrolDrawLayer);
        }
        document.getElementById('patrol-point-count').textContent=`${patrolPoints.length} Punkt${patrolPoints.length!==1?'e':''} aufgenommen`;
        return;
    }

    if (calMode) {
        calPending={lx:pt.lx,ly:pt.ly};
        document.getElementById('cal-instructions').style.display='none';
        document.getElementById('cal-form').style.display='block';
        document.getElementById('cal-z-input').value='';
        document.getElementById('cal-label-input').value='';
        document.getElementById('cal-coords-preview').textContent=`Leaflet: lx=${pt.lx}, ly=${pt.ly}`;
        document.getElementById('cal-z-input').focus();
        return;
    }
});

// ── Calibration ───────────────────────────────────────────────
let calMode     = false;
let calPending  = null;

document.getElementById('btn-calibrate').addEventListener('click', ()=>{
    const p = document.getElementById('cal-panel');
    p.classList.toggle('open');
    if (p.classList.contains('open')) loadCalPoints();
});
document.getElementById('cal-panel-close').addEventListener('click', ()=>{
    document.getElementById('cal-panel').classList.remove('open');
    exitCalMode();
});

function enterCalMode() {
    calMode = true;
    document.getElementById('cal-mode-indicator').classList.add('active');
    document.getElementById('map').style.cursor = 'crosshair';
    document.getElementById('btn-calibrate').classList.add('active');
}
function exitCalMode() {
    calMode = false;
    calPending = null;
    document.getElementById('cal-mode-indicator').classList.remove('active');
    document.getElementById('map').style.cursor = 'crosshair';
    document.getElementById('cal-form').style.display = 'none';
    document.getElementById('cal-instructions').style.display = 'block';
}

function loadCalPoints() {
    fetch(`${CMS_URL}&action=get_calibration&zone_id=${currentZoneId}`)
        .then(r=>r.json()).then(d=>{
            renderCalPoints(d.points || []);
        }).catch(err=>console.error("Calibration load fail:", err));
}

function renderCalPoints(points) {
    const list = document.getElementById('cal-list');
    if (!points.length) {
        list.innerHTML = '<div class="acp-s-ed2a5afc">No reference points for this zone yet.</div>';
    } else {
        list.innerHTML = points.map((p,i)=>`
            <div class="cal-point">
                <div class="cal-point-info">
                    <span class="cal-point-label">${esc(p.label||'Punkt '+(i+1))}</span>
                    <span class="cal-point-coords">Z:${p.z} · lx:${p.lx} ly:${p.ly}</span>
                </div>
                <button class="cal-point-del" onclick="deleteCalPoint(${i})">✕</button>
            </div>
        `).join('');
    }
    renderCalMarkers(points);
}

let calMarkerLayer = L.layerGroup().addTo(map);
function renderCalMarkers(points) {
    calMarkerLayer.clearLayers();
    points.forEach((p,i)=>{
        const icon = L.divIcon({
            html:`<div class="acp-s-d4e83b0a"></div>`,
            className:'', iconSize:[12,12], iconAnchor:[6,6]
        });
        L.marker([p.ly, p.lx], {icon})
         .bindTooltip(`📍 ${p.label||'Z:'+p.z}`, {direction:'top'})
         .addTo(calMarkerLayer);
    });
}

window.deleteCalPoint = function(idx) {
    if (!confirm('Delete reference point?')) return;
    fetch(`${CMS_URL}&action=delete_calibration&zone_id=${currentZoneId}`, {
        method:'POST', body: new URLSearchParams({idx})
    }).then(()=>loadCalPoints()).catch(err=>console.error("Delete point failed:", err));
};

document.getElementById('cal-save-btn').addEventListener('click', ()=>{
    if (!calPending) return;
    const z     = parseInt(document.getElementById('cal-z-input').value);
    const label = document.getElementById('cal-label-input').value.trim();
    if (!z || z < 100) { showToast('Please enter a valid Z value', true); return; }
    fetch(`${CMS_URL}&action=add_calibration&zone_id=${currentZoneId}`, {
        method:'POST', body: new URLSearchParams({lx:calPending.lx, ly:calPending.ly, z, label})
    }).then(r=>r.json()).then(d=>{
        if (d.success) {
            showToast(`✓ Reference point saved (${d.count} total)`, false, true);
            exitCalMode();
            loadCalPoints();
        }
    }).catch(err=>console.error("Save validation failed:", err));
});
document.getElementById('cal-cancel-btn').addEventListener('click', ()=>exitCalMode());

document.getElementById('btn-calibrate').addEventListener('dblclick', ()=>{
    enterCalMode();
});

// ── 3D View ───────────────────────────────────────────────────
let three = null;

document.getElementById('btn-3d').addEventListener('click', ()=>{
    window.open(
        'acp.php?s=mob_editor&view=3d&zone_id=' + currentZoneId,
        'daoc_3d',
        'width=1400,height=900,menubar=no,toolbar=no,scrollbars=no'
    );
});
document.getElementById('view3d-close').addEventListener('click', ()=>{
    document.getElementById('view3d-overlay').classList.remove('open');
    destroy3DView();
});

function setLoadingProgress(pct, text) {
    document.getElementById('view3d-loading-fill').style.width = pct + '%';
    if (text) document.getElementById('view3d-loading-text').textContent = text;
}

function build3DScene(canvas, loading) {
    const W = window.innerWidth - (document.getElementById('view3d-overlay').querySelector('#edit-panel')?.offsetWidth || 0);
    const H = window.innerHeight - 44;
    canvas.width  = W;
    canvas.height = H;
    canvas.style.width  = W + 'px';
    canvas.style.height = H + 'px';

    const renderer = new THREE.WebGLRenderer({canvas, antialias:true});
    renderer.setSize(W, H);
    renderer.setClearColor(0x050507);
    renderer.shadowMap.enabled = true;

    const scene  = new THREE.Scene();
    scene.background = new THREE.Color(0x050507);

    const camera = new THREE.PerspectiveCamera(55, W/H, 10, 300000);
    camera.position.set(ZSIZE/2, 20000, ZSIZE * 0.55);
    camera.lookAt(ZSIZE/2, 0, ZSIZE/2);

    scene.add(new THREE.AmbientLight(0xffffff, 1.2));
    const dir = new THREE.DirectionalLight(0xffffff, 0.4);
    dir.position.set(ZSIZE*0.3, 15000, ZSIZE*0.2);
    scene.add(dir);

    setLoadingProgress(35, '⚗ Lade Kartentextur…');
    const loader   = new THREE.TextureLoader();
    const zoneImgPath = ZONE_IMAGES[currentZoneId] || ZONE_IMAGES[0] || 'assets/img/zones/albion/Camelot_Hills_map.webp';
    loader.load(zoneImgPath, tex=>{
        setLoadingProgress(55, '⚗ Creating terrain…');
        tex.anisotropy = renderer.capabilities.getMaxAnisotropy();
        tex.needsUpdate = true;
        tex.flipY = true;
        const plane = new THREE.Mesh(
            new THREE.PlaneGeometry(ZSIZE, ZSIZE, 1, 1),
            new THREE.MeshBasicMaterial({map:tex, side: THREE.DoubleSide})
        );
        plane.rotation.x = -Math.PI/2;
        plane.position.set(ZSIZE/2, 0, ZSIZE/2);
        plane.receiveShadow = true;
        scene.add(plane);
        scene.add(new THREE.GridHelper(ZSIZE, 32, 0x111122, 0x0a0a18));
        scene.children[scene.children.length-1].position.set(ZSIZE/2, 3, ZSIZE/2);

        setLoadingProgress(70, '⚗ Placing Mobs…');
        const mobMeshes = [];
        const mats = {
            normal: new THREE.MeshPhongMaterial({color:0x4a8fd4, emissive:0x112244, shininess:40}),
            lab:    new THREE.MeshPhongMaterial({color:0xff8800, emissive:0x331500, shininess:60}),
            fav:    new THREE.MeshPhongMaterial({color:0xffcc44, emissive:0x332200, shininess:80}),
        };
        const geos = {
            sphere: new THREE.SphereGeometry(55,8,6),
            cone:   new THREE.ConeGeometry(45,110,6),
        };
        allMobsData.filter(m=>m.lx>=0&&m.lx<=ZSIZE&&m.ly>=0&&m.ly<=ZSIZE).forEach(mob=>{
            const isLab   = mob.PackageID==='mob_lab';
            const isFavMob = isFav(mob.Mob_ID);
            const mat  = isFavMob ? mats.fav : (isLab ? mats.lab : mats.normal);
            const geo  = (isLab||isFavMob) ? geos.cone : geos.sphere;
            const mesh = new THREE.Mesh(geo, mat.clone());
            mesh.position.set(mob.lx, 80, mob.ly);
            mesh.castShadow = true;
            mesh.userData   = {mob};
            scene.add(mesh);
            mobMeshes.push(mesh);
        });

        setLoadingProgress(85, '⚗ Calibration points…');
        fetch(`${CMS_URL}&action=get_calibration&zone_id=${currentZoneId}`)
            .then(r=>r.json()).then(d=>{
                (d.points||[]).forEach(p=>{
                    const m = new THREE.Mesh(
                        new THREE.CylinderGeometry(25,25,280,8),
                        new THREE.MeshPhongMaterial({color:0xc5a059,emissive:0x331100,transparent:true,opacity:0.85})
                    );
                    m.position.set(p.lx,140,p.ly);
                    scene.add(m);
                    const sp = makeTextSprite(p.label||`Z:${p.z}`);
                    sp.position.set(p.lx,380,p.ly);
                    scene.add(sp);
                });
            }).catch(e=>console.error("3D calibration points fetch error:", e));

        setLoadingProgress(100,'✓ Done!');
        setTimeout(()=>loading.classList.add('hidden'),400);

        let drag=false, rightDrag=false, lastM={x:0,y:0};
        let sph={theta:0.3, phi:0.75, r:ZSIZE*0.7};
        const tgt = new THREE.Vector3(ZSIZE/2,0,ZSIZE/2);
        function updCam(){
            camera.position.set(
                tgt.x + sph.r*Math.sin(sph.phi)*Math.sin(sph.theta),
                tgt.y + sph.r*Math.cos(sph.phi),
                tgt.z + sph.r*Math.sin(sph.phi)*Math.cos(sph.theta)
            );
            camera.lookAt(tgt);
        }
        updCam();
        canvas.addEventListener('mousedown',e=>{drag=true;rightDrag=e.button===2;lastM={x:e.clientX,y:e.clientY};});
        canvas.addEventListener('mouseup',()=>drag=false);
        canvas.addEventListener('mouseleave',()=>drag=false);
        const tooltip = document.getElementById('view3d-mob-tooltip');
        canvas.addEventListener('mousemove',e=>{
            const rect=canvas.getBoundingClientRect();
            const mouse=new THREE.Vector2(((e.clientX-rect.left)/rect.width)*2-1,-((e.clientY-rect.top)/rect.height)*2+1);
            const ray=new THREE.Raycaster();
            ray.setFromCamera(mouse,camera);
            const hits=ray.intersectObjects(mobMeshes);
            if(hits.length){
                const mob=hits[0].object.userData.mob;
                tooltip.style.display='block';
                tooltip.style.left=(e.clientX-rect.left+14)+'px';
                tooltip.style.top=(e.clientY-rect.top-8)+'px';
                tooltip.innerHTML=`<b>${esc(mob.Name)}</b><br>Lv${mob.Level} · Model #${mob.Model}<br><span class="acp-s-ccd4f110">Click to edit</span>`;
            } else { tooltip.style.display='none'; }
            if(!drag)return;
            const dx=e.clientX-lastM.x, dy=e.clientY-lastM.y;
            lastM={x:e.clientX,y:e.clientY};
            if(rightDrag){
                const spd=sph.r*0.001;
                const right=new THREE.Vector3();
                right.crossVectors(camera.getWorldDirection(new THREE.Vector3()),new THREE.Vector3(0,1,0)).normalize();
                tgt.addScaledVector(right,-dx*spd); tgt.y+=dy*spd;
            } else {
                sph.theta-=dx*0.005;
                sph.phi=Math.max(0.08,Math.min(Math.PI*0.44,sph.phi+dy*0.005));
            }
            updCam();
        });
        canvas.addEventListener('wheel',e=>{sph.r=Math.max(400,Math.min(ZSIZE*1.6,sph.r+e.deltaY*6));updCam();e.preventDefault();},{passive:false});

        canvas.addEventListener('click',e=>{
            const rect=canvas.getBoundingClientRect();
            const mouse=new THREE.Vector2(((e.clientX-rect.left)/rect.width)*2-1,-((e.clientY-rect.top)/rect.height)*2+1);
            const ray=new THREE.Raycaster(); ray.setFromCamera(mouse,camera);
            const hits=ray.intersectObjects(mobMeshes);
            if(hits.length){
                const mob=hits[0].object.userData.mob;
                document.getElementById('view3d-overlay').classList.remove('open');
                setTimeout(()=>{openEditPanel(mob.Mob_ID,mob.Name,mob.Model,mob.Race);switchEpTab('stats');},50);
            }
        });

        canvas.addEventListener('contextmenu',e=>{
            e.preventDefault();
            const rect=canvas.getBoundingClientRect();
            const mouse=new THREE.Vector2(((e.clientX-rect.left)/rect.width)*2-1,-((e.clientY-rect.top)/rect.height)*2+1);
            const ray=new THREE.Raycaster(); ray.setFromCamera(mouse,camera);
            const hits=ray.intersectObject(plane);
            if(hits.length){
                const pt=hits[0].point;
                pendingCoords.x=Math.round(pt.x); pendingCoords.y=Math.round(pt.z);
                document.getElementById('view3d-overlay').classList.remove('open');
                document.getElementById('f-model').value=408;
                document.getElementById('f-name').value='Mob Lab Specimen';
                document.getElementById('modal-preview-img').src=MOB_IMG(408);
                document.getElementById('f-z').value='…';
                document.getElementById('z-source-badge').textContent='(detecting…)';
                if(currentZoneMeta){
                    const gx=(parseInt(currentZoneMeta.OffsetX)*TILE)+pendingCoords.x;
                    const gy=(parseInt(currentZoneMeta.OffsetY)*TILE)+pendingCoords.y;
                    document.getElementById('coord-preview').textContent=`Leaflet (${pendingCoords.x},${pendingCoords.y}) → Global (${gx},${gy})`;
                }
                document.getElementById('modal-overlay').classList.add('open');
                fetch(`${CMS_URL}&action=get_nearest_z&zone_id=${currentZoneId}&lx=${pendingCoords.x}&ly=${pendingCoords.y}`)
                    .then(r=>r.json()).then(d=>{ if(d.success){document.getElementById('f-z').value=d.z;const lbl=d.source==='calibration'?'(📍 <?= addslashes(t("mobeditor.zsource.calibration_point")) ?>)':d.source==='terrain'?'(🗺 TerrainService)':d.source==='nearest_mob'?'(🎯 nearest mob)':'(⚙ <?= addslashes(t("mobeditor.zsource.default")) ?>)';document.getElementById('z-source-badge').textContent=lbl;} }).catch(()=>document.getElementById('f-z').value=2500);
            }
        });

        let pulseT=0;
        three = {renderer, scene, camera, af:null}; 
        function animate(){
            three.af=requestAnimationFrame(animate);
            pulseT+=0.025;
            mobMeshes.forEach(m=>{ if(m.userData.mob?.PackageID==='mob_lab') m.scale.setScalar(1+Math.sin(pulseT)*0.07); });
            renderer.render(scene,camera);
        }
        animate();
    }, undefined, ()=>setLoadingProgress(0,'❌ Textur-Ladefehler'));
}

function makeTextSprite(text) {
    const c=document.createElement('canvas'); c.width=256; c.height=64;
    const ctx=c.getContext('2d'); ctx.fillStyle='#c5a059'; ctx.font='bold 22px sans-serif';
    ctx.textAlign='center'; ctx.fillText(text,128,42);
    const sp=new THREE.Sprite(new THREE.SpriteMaterial({map:new THREE.CanvasTexture(c),transparent:true}));
    sp.scale.set(600,150,1); return sp;
}

function destroy3DView() {
    if (!three) return;
    if (three.af) cancelAnimationFrame(three.af);
    try { three.renderer.dispose(); } catch(e) {}
    three = null;
}

function init3DView() {
    const container = document.getElementById('view3d-container');
    const oldCanvas = document.getElementById('view3d-canvas');
    if (oldCanvas) oldCanvas.remove();
    const canvas = document.createElement('canvas');
    canvas.id = 'view3d-canvas';
    canvas.style.flex = '1';
    canvas.style.display = 'block';
    canvas.style.width = '100%';
    const loading = document.getElementById('view3d-loading');
    container.insertBefore(canvas, loading);
    loading.classList.remove('hidden');
    setLoadingProgress(5, '⚗ Loading Three.js…');

    if (typeof THREE !== 'undefined') {
        setLoadingProgress(20, '⚗ Building scene…');
        setTimeout(()=>build3DScene(canvas, loading), 100);
        return;
    }
    if (!document.querySelector('script[src*="three.min.js"]')) {
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
        script.onload = () => { setLoadingProgress(20,'⚗ Building scene…'); setTimeout(()=>build3DScene(canvas,loading),100); };
        document.head.appendChild(script);
    } else {
        let tries = 0;
        const wait = setInterval(()=>{
            tries++;
            if (typeof THREE !== 'undefined') { clearInterval(wait); setLoadingProgress(20,'⚗ Building scene…'); setTimeout(()=>build3DScene(canvas,loading),100); }
            if (tries > 20) { clearInterval(wait); setLoadingProgress(0,'❌ Failed to load Three.js'); }
        }, 200);
    }
}

function showToast(msg,isErr=false,isOk=false){const t=document.getElementById('toast');t.textContent=msg;t.className='show'+(isErr?' error':isOk?' success':'');setTimeout(()=>t.className='',3000);}
function esc(str){return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── 2D Edit Mode ───────────────────────────────────────────────
let edit2dMap     = null;
let edit2dMarkers = [];         // L.marker references
let edit2dPending = {};         // mob_id -> {mob, origLx, origLy, newLx, newLy, marker}
let edit2dPatrolPts   = [];
let edit2dPatrolLayer = null;
let edit2dPatrolRecording = false;
let edit2dMobLayer = null;

document.getElementById('btn-2dedit').addEventListener('click', () => {
    openEdit2d();
});
document.getElementById('edit2d-close').addEventListener('click', () => {
    closeEdit2d();
});

function openEdit2d() {
    const overlay = document.getElementById('edit2d-overlay');
    overlay.classList.add('open');

    if (!edit2dMap) {
        edit2dMap = L.map('edit2d-map', {
            crs: L.CRS.Simple,
            minZoom: -5,
            maxZoom: 2,
            zoomControl: true,
            attributionControl: false
        });

        const imgSrc = ZONE_IMAGES[currentZoneId] || ZONE_IMAGES[0];
        L.imageOverlay(imgSrc, BOUNDS).addTo(edit2dMap);
        edit2dMap.fitBounds(BOUNDS, {maxZoom: -4});

        edit2dMobLayer  = L.layerGroup().addTo(edit2dMap);
        edit2dPatrolLayer = L.layerGroup().addTo(edit2dMap);

        edit2dMap.on('click', e => {
            if (edit2dPatrolRecording) {
                const pt = {lx: Math.round(e.latlng.lng), ly: flipY(Math.round(e.latlng.lat))};
                edit2dPatrolPts.push(pt);
                L.circleMarker([flipY(pt.ly), pt.lx], {
                    radius: 5, color: '#50c878', fillColor: '#50c878', fillOpacity: 0.9, weight: 1
                }).addTo(edit2dPatrolLayer);
                if (edit2dPatrolPts.length >= 2) {
                    const a = edit2dPatrolPts[edit2dPatrolPts.length-2];
                    const b = edit2dPatrolPts[edit2dPatrolPts.length-1];
                    L.polyline([[flipY(a.ly),a.lx],[flipY(b.ly),b.lx]], {
                        color:'rgba(80,200,120,0.6)', weight:2, dashArray:'5,3'
                    }).addTo(edit2dPatrolLayer);
                }
                const cnt = edit2dPatrolPts.length;
                document.getElementById('e2d-patrol-pt-count').textContent = `${cnt} point${cnt!==1?'s':''} recorded`;
            }
        });
    } else {
        // Zone might have changed — reload map image
        const imgSrc = ZONE_IMAGES[currentZoneId] || ZONE_IMAGES[0];
        edit2dMap.eachLayer(l => { if (l instanceof L.ImageOverlay) edit2dMap.removeLayer(l); });
        L.imageOverlay(imgSrc, BOUNDS).addTo(edit2dMap);
        edit2dMap.fitBounds(BOUNDS, {maxZoom: -4});
    }

    edit2dPending = {};
    edit2dMarkers = [];
    edit2dMobLayer.clearLayers();
    renderEdit2dMarkers();
    renderEdit2dPendingList();
    loadEdit2dPatrolRoutes();
    resetPatrolNpcPicker('edit2d');

    setTimeout(() => edit2dMap.invalidateSize(), 100);
}

function closeEdit2d() {
    document.getElementById('edit2d-overlay').classList.remove('open');
    edit2dPatrolRecording = false;
    document.getElementById('e2d-patrol-record-btn').classList.remove('recording');
    document.getElementById('e2d-patrol-record-btn').textContent = '● Start Recording';
}

function renderEdit2dMarkers() {
    edit2dMobLayer.clearLayers();
    edit2dMarkers = [];

    allMobsData.filter(m => m.lx>=0 && m.lx<=ZSIZE && m.ly>=0 && m.ly<=ZSIZE).forEach(mob => {
        const isLab   = mob.PackageID === 'mob_lab';
        const isFavMob = isFav(mob.Mob_ID);
        const color   = isFavMob ? '#ffcc44' : (isLab ? '#ff8800' : '#4a8fd4');
        const pending = edit2dPending[mob.Mob_ID];
        const lx = pending ? pending.newLx : mob.lx;
        const ly = pending ? pending.newLy : mob.ly;

        const marker = L.circleMarker([flipY(ly), lx], {
            renderer,
            radius:      isLab ? 8 : 5,
            color:       pending ? '#50c878' : color,
            fillColor:   pending ? '#50c878' : color,
            fillOpacity: 0.8,
            weight:      pending ? 2.5 : 1,
            draggable:   false
        }).addTo(edit2dMobLayer);

        marker._mob = mob;
        marker._origLx = mob.lx;
        marker._origLy = mob.ly;
        marker._curLx  = lx;
        marker._curLy  = ly;

        marker.bindTooltip(`<b>${esc(mob.Name)}</b> Lv${mob.Level}${pending?' <span class="acp-s-7992c2cd">●</span>':''}`, {direction:'top'});

        // Make draggable via mousedown on the marker
        marker.on('mousedown', function(e) {
            L.DomEvent.stopPropagation(e);
            if (edit2dPatrolRecording) return;

            edit2dMap.dragging.disable();
            const thisMob = mob;
            const thisMarker = marker;

            function onMouseMove(me) {
                const ll = edit2dMap.containerPointToLatLng(
                    edit2dMap.mouseEventToContainerPoint(me.originalEvent || me)
                );
                thisMarker.setLatLng(ll);
                thisMarker._curLx = Math.round(ll.lng);
                thisMarker._curLy = flipY(Math.round(ll.lat));
            }

            function onMouseUp(me) {
                edit2dMap.dragging.enable();
                edit2dMap.off('mousemove', onMouseMove);
                edit2dMap.off('mouseup',   onMouseUp);

                const ll   = thisMarker.getLatLng();
                const newLx = Math.round(ll.lng);
                const newLy = flipY(Math.round(ll.lat));

                // Only stage if actually moved
                if (newLx === thisMarker._origLx && newLy === thisMarker._origLy) return;

                edit2dPending[thisMob.Mob_ID] = {
                    mob:    thisMob,
                    origLx: thisMarker._origLx,
                    origLy: thisMarker._origLy,
                    newLx,
                    newLy
                };

                // Highlight marker as pending
                thisMarker.setStyle({color:'#50c878', fillColor:'#50c878', weight:2.5});
                thisMarker.setTooltipContent(`<b>${esc(thisMob.Name)}</b> Lv${thisMob.Level} <span class="acp-s-7992c2cd">● moved</span>`);

                renderEdit2dPendingList();
                showToast(`Staged: ${thisMob.Name}`);
            }

            edit2dMap.on('mousemove', onMouseMove);
            edit2dMap.on('mouseup',   onMouseUp);
        });

        edit2dMarkers.push(marker);
    });
}

function renderEdit2dPendingList() {
    const list = document.getElementById('e2d-pending-list');
    const saveBtn = document.getElementById('e2d-save-btn');
    const count = Object.keys(edit2dPending).length;

    if (!count) {
        list.innerHTML = '<div class="acp-s-ed2a5afc">No changes yet. Drag mob markers to move them.</div>';
        saveBtn.disabled = true;
        document.getElementById('e2d-save-count').textContent = '';
        return;
    }

    saveBtn.disabled = false;
    document.getElementById('e2d-save-count').textContent = `${count} mob${count!==1?'s':''} staged`;

    list.innerHTML = Object.values(edit2dPending).map(p => `
        <div class="e2d-pending-item">
            <span class="e2d-pi-name">${esc(p.mob.Name)}</span>
            <button class="e2d-pi-undo" onclick="undoEdit2dPending('${p.mob.Mob_ID}')" title="Undo">↩</button>
        </div>
    `).join('');
}

window.undoEdit2dPending = function(mobId) {
    const p = edit2dPending[mobId];
    if (!p) return;
    delete edit2dPending[mobId];
    // Reset marker visually
    const m = edit2dMarkers.find(mk => mk._mob.Mob_ID === mobId);
    if (m) {
        m.setLatLng([flipY(p.origLy), p.origLx]);
        const isLab = m._mob.PackageID === 'mob_lab';
        const isFavMob = isFav(m._mob.Mob_ID);
        const color = isFavMob ? '#ffcc44' : (isLab ? '#ff8800' : '#4a8fd4');
        m.setStyle({color, fillColor:color, weight:1});
        m.setTooltipContent(`<b>${esc(m._mob.Name)}</b> Lv${m._mob.Level}`);
    }
    renderEdit2dPendingList();
};

document.getElementById('e2d-save-btn').addEventListener('click', async () => {
    const entries = Object.values(edit2dPending);
    if (!entries.length) return;

    const btn = document.getElementById('e2d-save-btn');
    btn.disabled = true;
    btn.textContent = '⏳ Saving…';

    let saved = 0, failed = 0;
    const offX = currentZoneMeta ? parseInt(currentZoneMeta.OffsetX)*TILE : 0;
    const offY = currentZoneMeta ? parseInt(currentZoneMeta.OffsetY)*TILE : 0;

    for (const p of entries) {
        const gx = offX + p.newLx;
        const gy = offY + p.newLy;
        const z  = p.mob.Z || 2500;
        try {
            const fd = new URLSearchParams({
                mob_id: p.mob.Mob_ID,
                gx, gy, z,
                csrf_token: '<?= generateToken() ?>'
            });
            const r = await fetch(`${CMS_URL}&action=update_mob_pos&zone_id=${currentZoneId}`, {method:'POST', body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
            const d = await r.json();
            if (d.success) {
                // Update local mob data so main map reflects the move
                const mob = allMobsData.find(m => m.Mob_ID === p.mob.Mob_ID);
                if (mob) { mob.lx = p.newLx; mob.ly = p.newLy; }
                saved++;
            } else { failed++; }
        } catch(e) { failed++; }
    }

    edit2dPending = {};
    btn.textContent = '✓ Save All Changes';
    renderEdit2dPendingList();
    renderEdit2dMarkers(); // Redraw without pending highlights
    renderMarkers(allMobsData); // Refresh main map too

    if (failed) showToast(`Saved ${saved}, failed ${failed}`, true);
    else showToast(`✓ ${saved} mob${saved!==1?'s':''} saved`, false, true);
});

// ── 2D Edit Tab Switching ─────────────────────────────────────
window.switchE2dTab = function(tab) {
    document.querySelectorAll('.e2d-tab').forEach(t => t.classList.toggle('active', t.id === `e2d-tab-${tab}`));
    document.getElementById('e2d-move-panel').classList.toggle('active',   tab === 'move');
    document.getElementById('e2d-patrol-panel').classList.toggle('active', tab === 'patrol');
};

// ── 2D Edit Patrol ────────────────────────────────────────────
function loadEdit2dPatrolRoutes() {
    fetch(`${CMS_URL}&action=get_patrol_paths&zone_id=${currentZoneId}`)
        .then(r=>r.json())
        .then(d => renderEdit2dPatrolRoutes(d.paths || []))
        .catch(err => console.error('2D patrol load fail:', err));
}

function renderEdit2dPatrolRoutes(paths) {
    edit2dPatrolLayer.clearLayers();
    const list = document.getElementById('e2d-patrol-route-list');

    if (!paths.length) {
        list.innerHTML = `<div class="acp-s-ed2a5afc">${esc(patrolText('noRoutes'))}</div>`;
        return;
    }

    list.innerHTML = paths.map((p, i) => `
        <div class="e2d-route-item">
            <span class="e2d-ri-dot"></span>
            <span class="e2d-ri-name" title="${esc(p.label)}">${esc(p.label)}<small>${esc((p.npcs||[]).map(n=>n.name).join(', '))}</small></span>
            <span class="e2d-ri-pts">${p.points.length}pt</span>
            <button class="e2d-ri-del" data-index="${i}" title="Delete">✕</button>
        </div>
    `).join('');
    list.querySelectorAll('.e2d-ri-del').forEach(button => {
        button.addEventListener('click', () => deletePatrol(paths[parseInt(button.dataset.index, 10)].path_id));
    });

    // Draw routes on edit2d map
    paths.forEach(p => {
        if (p.points.length < 2) return;
        const lls = p.points.map(pt => [flipY(pt.ly ?? pt.y ?? 0), pt.lx ?? pt.x ?? 0]);
        const npcNames = (p.npcs||[]).map(n=>n.name).join(', ');
        L.polyline(lls, {color:'rgba(197,160,89,0.7)', weight:2, dashArray:'8,4'})
         .addTo(edit2dPatrolLayer)
         .bindTooltip(`🛣 ${esc(p.label)} · ${esc(npcNames)}`, {permanent:false});
        lls.forEach(ll => L.circleMarker(ll, {radius:4, color:'var(--gold)', fillColor:'var(--gold)', fillOpacity:0.8, weight:1}).addTo(edit2dPatrolLayer));
    });
}

document.getElementById('e2d-patrol-record-btn').addEventListener('click', () => {
    edit2dPatrolRecording = !edit2dPatrolRecording;
    const btn = document.getElementById('e2d-patrol-record-btn');
    const saveBtn = document.getElementById('e2d-patrol-save-btn');

    if (edit2dPatrolRecording) {
        edit2dPatrolPts = [];
        // Clear only the drawing layer for the current in-progress route
        // (keep saved routes visible)
        btn.classList.add('recording');
        btn.textContent = '⬛ Stop Recording';
        saveBtn.disabled = true;
        document.getElementById('e2d-patrol-pt-count').textContent = '0 points recorded';
        showToast('● Recording — click points on the map');
    } else {
        btn.classList.remove('recording');
        btn.textContent = '● Start Recording';
        if (edit2dPatrolPts.length >= 2) {
            saveBtn.disabled = false;
            document.getElementById('e2d-patrol-pt-count').textContent = `${edit2dPatrolPts.length} points — ready to save`;
        } else {
            document.getElementById('e2d-patrol-pt-count').textContent = 'Need at least 2 points';
        }
    }
});

document.getElementById('e2d-patrol-save-btn').addEventListener('click', () => {
    if (edit2dPatrolPts.length < 2) { showToast(patrolText('recordMin'), true); return; }
    const npcId = document.getElementById('e2d-patrol-npc-id').value;
    if (!npcId) { showToast(patrolText('selectNpc'), true); return; }
    const label = document.getElementById('e2d-patrol-label').value.trim();
    if (!label) { showToast(patrolText('enterName'), true); return; }
    const saveBtn = document.getElementById('e2d-patrol-save-btn');
    saveBtn.disabled = true;

    fetch(`${CMS_URL}&action=save_patrol_path&zone_id=${currentZoneId}`, {
        method: 'POST',
        body: new URLSearchParams({
            path:JSON.stringify(edit2dPatrolPts), label, npc_id:npcId,
            path_type:document.getElementById('e2d-patrol-path-type').value,
            csrf_token:CSRF_TOKEN
        })
    }).then(r=>r.json()).then(d => {
        if (d.success) {
            showToast(patrolText('assigned', {route:label, npc:d.npc_name}), false, true);
            edit2dPatrolPts = [];
            edit2dPatrolRecording = false;
            document.getElementById('e2d-patrol-record-btn').classList.remove('recording');
            document.getElementById('e2d-patrol-record-btn').textContent = '● Start Recording';
            document.getElementById('e2d-patrol-save-btn').disabled = true;
            document.getElementById('e2d-patrol-pt-count').textContent = '';
            document.getElementById('e2d-patrol-label').value = '';
            resetPatrolNpcPicker('edit2d');
            loadEdit2dPatrolRoutes();
            loadPatrolPaths(); // also refresh the main map patrol overlay
        } else {
            showToast(d.error || patrolText('saveFailed'), true);
        }
    }).catch(err => {
        console.error('Patrol save fail:', err);
        showToast(patrolText('saveFailed'), true);
    }).finally(()=>{
        if (edit2dPatrolPts.length >= 2) saveBtn.disabled = false;
    });
});

// ── Start ─────────────────────────────────────────────────────
loadFavourites();
loadZone(currentZoneId);

})();
</script>
