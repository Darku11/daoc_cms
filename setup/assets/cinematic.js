/* ==========================================================================
   DAoC CMS — Cinematic Setup
   Times the cinematic transition and animates the embers.
   ========================================================================== */
(function () {
  'use strict';

  var calm = window.matchMedia &&
             window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* -------------------------------------------------------------- Transition */

  var cinema = document.getElementById('cinema');
  var stage  = document.getElementById('stage');
  var timer  = null;

  function revealStage() {
    if (stage) stage.classList.add('is-in');
  }

  function cut() {
    if (!cinema || cinema.classList.contains('is-out')) return;
    clearTimeout(timer);
    cinema.classList.add('is-out');
    revealStage();
    setTimeout(function () {
      if (cinema && cinema.parentNode) cinema.parentNode.removeChild(cinema);
    }, 700);
  }

  if (cinema && !calm) {
    // Run shortly after the subtitle animation finishes.
    timer = setTimeout(cut, 4400);

    var skip = document.getElementById('cineSkip');
    if (skip) skip.addEventListener('click', cut);

    cinema.addEventListener('click', cut);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' || e.key === 'Enter' || e.key === ' ') cut();
    });
  } else {
    if (cinema && cinema.parentNode) cinema.parentNode.removeChild(cinema);
    revealStage();
  }

  /* ------------------------------------------------------------------ Copy */

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-copy-target]') : null;
    if (!btn) return;

    var src = document.getElementById(btn.getAttribute('data-copy-target'));
    if (!src) return;

    var text = ('value' in src) ? src.value : src.textContent;
    var original = btn.textContent;

    function done(ok) {
      btn.textContent = ok ? 'Copied' : 'Select it';
      btn.classList.toggle('is-copied', ok);
      setTimeout(function () {
        btn.textContent = original;
        btn.classList.remove('is-copied');
      }, 1600);
    }

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
    } else {
      // The Clipboard API is unavailable without HTTPS.
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        done(document.execCommand('copy'));
        document.body.removeChild(ta);
      } catch (err) {
        done(false);
      }
    }
  });

  /* ---------------------------------------------------------------- Embers */

  var canvas = document.getElementById('embers');
  if (!canvas || calm) return;

  var ctx = canvas.getContext('2d');
  var dpr = Math.min(window.devicePixelRatio || 1, 2);
  var w = 0, h = 0, sparks = [];

  function count() {
    return window.innerWidth < 768 ? 16 : 42;
  }

  function seed(atBottom) {
    return {
      x: Math.random() * w,
      y: atBottom ? h + Math.random() * 60 : Math.random() * h,
      r: 0.5 + Math.random() * 1.5,
      vy: 0.12 + Math.random() * 0.42,
      drift: (Math.random() - 0.5) * 0.22,
      phase: Math.random() * Math.PI * 2,
      alpha: 0.12 + Math.random() * 0.42
    };
  }

  function resize() {
    w = canvas.width  = Math.floor(window.innerWidth  * dpr);
    h = canvas.height = Math.floor(window.innerHeight * dpr);
    canvas.style.width  = window.innerWidth + 'px';
    canvas.style.height = window.innerHeight + 'px';

    sparks = [];
    for (var i = 0; i < count(); i++) sparks.push(seed(false));
  }

  function frame() {
    ctx.clearRect(0, 0, w, h);

    for (var i = 0; i < sparks.length; i++) {
      var s = sparks[i];

      s.y -= s.vy * dpr;
      s.phase += 0.012;
      s.x += (s.drift + Math.sin(s.phase) * 0.28) * dpr;

      if (s.y < -20) sparks[i] = seed(true);

      // Fade near the top edge to avoid an abrupt cutoff.
      var fade = Math.min(1, s.y / (h * 0.35));

      ctx.beginPath();
      ctx.arc(s.x, s.y, s.r * dpr, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(197, 160, 89, ' + (s.alpha * fade).toFixed(3) + ')';
      ctx.fill();
    }

    requestAnimationFrame(frame);
  }

  window.addEventListener('resize', resize);
  resize();
  requestAnimationFrame(frame);
})();
