/**
 * TTN Shared JavaScript
 * Tennessee Technological Community · ttn.radio
 *
 * LOCATION: /home/obdswlpx/dev.ttn.radio/ttn.js
 *
 * Loaded via <script src="ttn.js" defer> in header.php
 * Works on every TTN page.
 *
 * Contains:
 *   - CW easter egg (TTN logo click)
 *   - Live node status polling via /api/status.php
 *   - Header connected count update
 */

'use strict';

// ── CW EASTER EGG ────────────────────────────────────────────────────────────
const CW_TABLE = {
  A:'.-', B:'-...', C:'-.-.', D:'-..', E:'.', F:'..-.', G:'--.', H:'....',
  I:'..', J:'.---', K:'-.-', L:'.-..', M:'--', N:'-.', O:'---', P:'.--.',
  Q:'--.-', R:'.-.', S:'...', T:'-', U:'..-', V:'...-', W:'.--', X:'-..-',
  Y:'-.--', Z:'--..', '7':'--...', '3':'...--', ' ':' '
};

function playCW(text) {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const WPM = 18;
    const dit = 1.2 / WPM;
    let t = ctx.currentTime + 0.05;

    const tone = (dur) => {
      const o = ctx.createOscillator();
      const g = ctx.createGain();
      o.connect(g);
      g.connect(ctx.destination);
      o.frequency.value = 700;
      o.type = 'sine';
      g.gain.setValueAtTime(0, t);
      g.gain.linearRampToValueAtTime(0.25, t + 0.006);
      g.gain.setValueAtTime(0.25, t + dur - 0.006);
      g.gain.linearRampToValueAtTime(0, t + dur);
      o.start(t);
      o.stop(t + dur);
      t += dur;
    };

    text.toUpperCase().split('').forEach(ch => {
      if (ch === ' ') { t += dit * 7; return; }
      const code = CW_TABLE[ch];
      if (!code) return;
      code.split('').forEach(s => {
        s === '.' ? tone(dit) : tone(dit * 3);
        t += dit;
      });
      t += dit * 2;
    });
  } catch (e) {
    // AudioContext blocked — silent fail is fine
  }
}

let cwCooldown = false;

document.addEventListener('DOMContentLoaded', () => {
  const logo  = document.getElementById('ttnLogo');
  const toast = document.getElementById('cwToast');

  if (logo && toast) {
    logo.addEventListener('click', (e) => {
      e.preventDefault();
      if (cwCooldown) return;
      cwCooldown = true;
      setTimeout(() => cwCooldown = false, 12000);

      toast.classList.add('show');
      setTimeout(() => toast.classList.remove('show'), 4500);
      playCW('W4BWW DE TTN 73');
    });
  }
});


// ── LIVE NODE STATUS POLLING ──────────────────────────────────────────────────
// Calls our own /api/status.php (server-side ASL proxy — no CORS issues)
// Updates header connected count and any .node-live-count elements on page

// TTN_STATUS_URL set by header.php from site_settings

async function pollNodeStatus(node) {
  node = node || '450330';
  try {
    const res = await fetch(TTN_STATUS_URL + '?node=' + node, {
      signal: AbortSignal.timeout(8000)
    });
    if (!res.ok) return;

    const data = await res.json();
    if (!data.ok) return;

    // Update header connected count
    const hdrConn = document.getElementById('hdr-conn');
    if (hdrConn) {
      const count = data.conn_count ?? 0;
      hdrConn.textContent = count + ' CONNECTED';
    }

    // Pages can define window.onNodeStatus(data) to handle additional updates
    if (typeof window.onNodeStatus === 'function') {
      window.onNodeStatus(data);
    }

  } catch (e) {
    // Silent fail — don't break the page if node server unreachable
  }
}

// Poll on load and every 60 seconds
document.addEventListener('DOMContentLoaded', () => {
  pollNodeStatus();
  setInterval(pollNodeStatus, 60000);
});
