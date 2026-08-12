<?php
// SPDX-License-Identifier: GPL-3.0-only
// Raw SVG markup for Britty's portrait medallion. Included directly (not linked)
// so it inlines into the page — no extra request, and colors stay themeable
// alongside cinematic.css without a separate build step.
if (!isset($installer)) { exit; }
?>
<svg viewBox="0 0 120 120" class="britty-portrait" role="img" aria-label="Britty">
  <defs>
    <radialGradient id="brittyBg" cx="50%" cy="35%" r="75%">
      <stop offset="0%" stop-color="#2a2118"/>
      <stop offset="100%" stop-color="#0f0d0b"/>
    </radialGradient>
    <linearGradient id="brittyHair" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#5c3a22"/>
      <stop offset="100%" stop-color="#3a230f"/>
    </linearGradient>
  </defs>

  <circle cx="60" cy="60" r="58" fill="url(#brittyBg)" stroke="#c5a059" stroke-width="2"/>

  <!-- collar / shoulders -->
  <path d="M22 118 C22 92 40 82 60 82 C80 82 98 92 98 118 Z" fill="#7d2b26"/>
  <path d="M22 118 C22 92 40 82 60 82 C80 82 98 92 98 118" fill="none" stroke="#c5a059" stroke-width="1.5" opacity=".6"/>

  <!-- neck -->
  <rect x="50" y="66" width="20" height="24" rx="8" fill="#d9b48f"/>

  <!-- hair back -->
  <path d="M28 58 C26 30 40 12 60 12 C80 12 94 30 92 58 C92 74 84 84 84 84 L36 84 C36 84 28 74 28 58 Z" fill="url(#brittyHair)"/>

  <!-- face -->
  <ellipse cx="60" cy="54" rx="24" ry="27" fill="#e8caa4"/>

  <!-- hair front / fringe -->
  <path d="M36 46 C36 26 46 16 60 16 C74 16 84 26 84 46 C78 34 70 30 60 30 C50 30 42 34 36 46 Z" fill="url(#brittyHair)"/>

  <!-- circlet -->
  <path d="M38 34 C46 28 74 28 82 34" fill="none" stroke="#c5a059" stroke-width="2" stroke-linecap="round"/>
  <circle cx="60" cy="30" r="2.4" fill="#c5a059"/>

  <!-- eyes -->
  <path d="M48 55 q4 -4 8 0" fill="none" stroke="#241a10" stroke-width="1.6" stroke-linecap="round"/>
  <path d="M64 55 q4 -4 8 0" fill="none" stroke="#241a10" stroke-width="1.6" stroke-linecap="round"/>

  <!-- brows -->
  <path d="M47 50 q5 -3 9 -1" fill="none" stroke="#3a230f" stroke-width="1.4" stroke-linecap="round"/>
  <path d="M64 49 q4 -2 9 1" fill="none" stroke="#3a230f" stroke-width="1.4" stroke-linecap="round"/>

  <!-- nose -->
  <path d="M60 56 q-2 6 0 9" fill="none" stroke="#c79a72" stroke-width="1.3" stroke-linecap="round"/>

  <!-- lips -->
  <path d="M53 70 q7 4 14 0" fill="none" stroke="#a3453d" stroke-width="2" stroke-linecap="round"/>
</svg>
