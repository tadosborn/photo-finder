<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/version.php';
Db::get();
$settings = pf_load_settings();
$apiKeySet = $settings['api_key'] !== '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Photo Finder</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root { --bg:#0f1115; --panel:#181c24; --panel2:#21262f; --line:#2a3140; --text:#e6e8ee; --mute:#9aa3b2; --accent:#5b8def; --accent2:#7a5af8; --ok:#4ade80; --warn:#f59e0b; --err:#ef4444;
    /* WCAG AA: --err-text is red for text on the dark panels (5.5:1 on #21262f); --err-bg is red
       behind white text (5.6:1); --primary-bg is the primary button (white text 4.7:1, hover 5.9:1). */
    --err-text:#f87171; --err-bg:#c62828; --primary-bg:#3a6fd8; --primary-bg-hover:#2f5fc4; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background: var(--bg); color: var(--text); }
  header { background: var(--panel); border-bottom: 1px solid var(--line); padding: 12px 18px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; position:sticky; top:0; z-index:10; }
  header h1 { font-size: 18px; margin: 0 12px 0 0; font-weight: 600; }
  .search { flex: 1; min-width: 240px; display:flex; gap:8px; }
  .search input { flex:1; background: var(--panel2); border:1px solid var(--line); color: var(--text); padding: 10px 14px; border-radius: 8px; font-size: 15px; }
  .search input:focus { outline:none; border-color: var(--accent); }
  button { background: var(--panel2); border: 1px solid var(--line); color: var(--text); padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: 13px; }
  button:hover { background: #2a303d; }
  button.primary { background: var(--primary-bg); border-color: var(--primary-bg); color:#fff; }
  button.primary:hover { background: var(--primary-bg-hover); }
  button:disabled { opacity: 0.5; cursor: not-allowed; }
  .pill { background: var(--panel2); border: 1px solid var(--line); padding: 4px 10px; border-radius: 999px; font-size: 12px; color: var(--mute); }
  .pill .num { color: var(--text); font-weight: 600; }
  .pill.ok .num { color: var(--ok); }
  .pill.warn .num { color: var(--warn); }
  .pill.err .num { color: var(--err-text); }
  main { padding: 18px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
  .card { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; display:flex; flex-direction:column; transition: transform 0.1s; }
  .card:hover { transform: translateY(-2px); border-color: var(--accent); }
  .card .imgwrap { aspect-ratio: 1; background:#0a0c10; display:flex; align-items:center; justify-content:center; overflow:hidden; cursor: zoom-in; }
  .card img { width:100%; height:100%; object-fit: cover; }
  .card { position: relative; }
  .card.selected { border-color: var(--accent); outline: 2px solid var(--accent); }
  .card-tools { position: absolute; top: 8px; left: 8px; right: 8px; display: flex; justify-content: space-between; align-items: flex-start; opacity: 0; transition: opacity 0.12s; pointer-events: none; z-index: 2; }
  .card:hover .card-tools, .card.selected .card-tools { opacity: 1; pointer-events: auto; }
  .card-tools .sel { width: 20px; height: 20px; cursor: pointer; accent-color: var(--accent); }
  .card-tools .del { background: rgba(10,12,16,0.7); border: 1px solid var(--line); color: #fff; width: 28px; height: 28px; border-radius: 6px; padding: 0; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center; }
  .card-tools .del:hover { background: var(--err-bg); border-color: var(--err-bg); }
  .selbar { display: none; position: sticky; top: 57px; z-index: 9; background: var(--panel); border-bottom: 1px solid var(--line); padding: 10px 18px; align-items: center; gap: 12px; }
  .selbar.show { display: flex; }
  .selbar .sel-count { font-weight: 600; }
  .selbar .sel-spacer { flex: 1; }
  .selbar button.danger { background: var(--err-bg); border-color: var(--err-bg); color: #fff; }
  .selbar button.danger:hover { filter: brightness(1.1); }
  dialog.lightbox .lb-del-btn { position: absolute; top: 16px; right: 160px; background: rgba(0,0,0,0.5); color:#fff; border:none; border-radius: 20px; padding: 8px 14px; cursor: pointer; font-size: 13px; }
  dialog.lightbox .lb-del-btn:hover { background: var(--err-bg); }
  .card .meta { padding: 10px 12px; font-size: 12px; color: var(--mute); }
  .card .desc { color: var(--text); font-size: 13px; line-height: 1.4; max-height: 3.6em; overflow: hidden; }
  .card .path { font-size: 11px; color: var(--mute); margin-top: 6px; word-break: break-all; opacity: 0.7; }
  .card mark { background: rgba(91, 141, 239, 0.35); color: #fff; padding: 0 2px; border-radius: 3px; }
  .empty { text-align:center; color: var(--mute); padding: 60px 20px; }
  /* View bar (order + grouping) and the group headings inside the grid */
  .viewbar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin: 0 0 14px; color: var(--mute); font-size: 13px; }
  .viewbar label { display: inline; margin: 0; font-size: 13px; color: var(--mute); }
  .viewbar select { width: auto; background: var(--panel2); color: var(--text); border: 1px solid var(--line); border-radius: 6px; padding: 5px 8px; font-size: 13px; }
  .viewbar select:focus-visible, .more-btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  .viewbar .viewbar-gap { width: 10px; }
  .viewbar .viewbar-count { margin-left: auto; }
  .group-head { grid-column: 1 / -1; margin: 14px 0 0; font-size: 16px; font-weight: 600; color: var(--text); display:flex; align-items:baseline; gap:10px; border-bottom: 1px solid var(--line); padding-bottom: 6px; }
  .group-head:first-child { margin-top: 0; }
  .group-head .group-count { font-size: 12px; font-weight: 400; color: var(--mute); }
  .more-wrap { text-align: center; padding: 22px 0 8px; }
  dialog.settings { background: var(--panel); color: var(--text); border: 1px solid var(--line); border-radius: 12px; padding: 0; max-width: 600px; width: 92%; }
  dialog.settings::backdrop { background: rgba(0,0,0,0.6); }
  .dlg-head { padding: 16px 20px; border-bottom: 1px solid var(--line); display:flex; justify-content:space-between; align-items:center; }
  .dlg-head h2 { margin: 0; font-size: 16px; }
  .dlg-body { padding: 18px 20px; }
  .dlg-foot { padding: 12px 20px; border-top: 1px solid var(--line); display:flex; gap:10px; justify-content:flex-end; }
  label { display:block; font-size: 12px; color: var(--mute); margin: 14px 0 6px; }
  textarea, select, input[type=text], input[type=password], input[type=number] { width:100%; background: var(--panel2); border:1px solid var(--line); color: var(--text); padding: 8px 10px; border-radius: 6px; font: inherit; }
  textarea { min-height: 80px; resize: vertical; font-family: ui-monospace, Consolas, monospace; font-size: 12px; }
  .hint { font-size: 11px; color: var(--mute); margin-top: 4px; }
  .toast { position: fixed; bottom: 20px; right: 20px; background: var(--panel); border: 1px solid var(--line); padding: 12px 18px; border-radius: 8px; z-index: 100; max-width: 400px; opacity: 0; transition: opacity 0.2s; pointer-events:none; }
  .toast.show { opacity: 1; pointer-events: auto; }
  .toast.ok { border-color: var(--ok); }
  .toast.err { border-color: var(--err); }
  dialog.lightbox { background: transparent; border: none; padding: 0; width: 100vw; height: 100vh; max-width: none; max-height: none; margin: 0; top: 0; left: 0; }
  dialog.lightbox[open] { display: flex; align-items: center; justify-content: center; }
  dialog.lightbox::backdrop { background: rgba(0,0,0,0.95); }
  dialog.lightbox img { max-width: 100vw; max-height: 100vh; object-fit: contain; cursor: zoom-out; }
  dialog.lightbox .lb-meta { position: absolute; bottom: 0; left: 0; right: 0; color: #fff; padding: 14px 20px 18px; background: linear-gradient(transparent, rgba(0,0,0,0.85) 30%); transition: opacity 0.2s; }
  dialog.lightbox.no-meta .lb-meta { opacity: 0; pointer-events: none; }
  dialog.lightbox .lb-meta .desc { font-size: 15px; line-height: 1.4; max-width: 900px; }
  dialog.lightbox .lb-meta .tags { font-size: 12px; color: #bbb; margin-top: 6px; }
  dialog.lightbox .lb-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 44px; height: 80px; background: rgba(0,0,0,0.45); color: #fff; border: none; cursor: pointer; font-size: 28px; opacity: 0.6; transition: opacity 0.15s; user-select: none; }
  dialog.lightbox .lb-nav:hover { opacity: 1; background: rgba(0,0,0,0.7); }
  dialog.lightbox .lb-prev { left: 0; border-radius: 0 6px 6px 0; }
  dialog.lightbox .lb-next { right: 0; border-radius: 6px 0 0 6px; }
  dialog.lightbox .lb-counter { position: absolute; top: 16px; left: 20px; color: #fff; background: rgba(0,0,0,0.5); padding: 4px 10px; border-radius: 12px; font-size: 12px; }
  dialog.lightbox .lb-close { position: absolute; top: 16px; right: 20px; color: #fff; background: rgba(0,0,0,0.5); border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; font-size: 22px; line-height: 1; }
  dialog.lightbox .lb-close:hover { background: rgba(0,0,0,0.8); }
  dialog.lightbox .lb-edit-btn { position: absolute; top: 16px; right: 64px; background: rgba(0,0,0,0.5); color:#fff; border:none; border-radius: 20px; padding: 8px 14px; cursor: pointer; font-size: 13px; }
  dialog.lightbox .lb-edit-btn:hover { background: rgba(0,0,0,0.8); }
  dialog.lightbox .lb-edit-btn.dirty { background: var(--warn); color: #1a1107; }
  dialog.lightbox .lb-editor { position: absolute; top: 60px; right: 20px; left: 20px; bottom: 20px; background: rgba(20,24,32,0.97); border: 1px solid var(--line); border-radius: 12px; padding: 18px 22px; color: var(--text); overflow-y: auto; display: none; max-width: 720px; margin: 0 auto; }
  dialog.lightbox .lb-editor.open { display: block; }
  dialog.lightbox .lb-editor h3 { margin: 0 0 12px; font-size: 16px; }
  dialog.lightbox .lb-editor label { display:block; font-size: 12px; color: var(--mute); margin: 12px 0 4px; }
  dialog.lightbox .lb-editor textarea, dialog.lightbox .lb-editor input[type=text] { width: 100%; background: var(--panel2); border: 1px solid var(--line); color: var(--text); padding: 8px 10px; border-radius: 6px; font: inherit; }
  dialog.lightbox .lb-editor textarea { min-height: 60px; resize: vertical; }
  dialog.lightbox .lb-editor .edit-actions { margin-top: 16px; display: flex; gap: 10px; justify-content: flex-end; }
  dialog.lightbox .lb-editor .manual-badge { color: var(--warn); font-size: 11px; margin-left: 8px; }
  dialog.lightbox .lb-editor .reset-btn { background: transparent; color: var(--mute); border: 1px solid var(--line); padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; margin-right: auto; }
  dialog.lightbox .lb-editor .reset-btn:hover { color: var(--err-text); border-color: var(--err-text); }
  /* ==== Enhance (lightbox button + enhance view) ====
     Contrast (WCAG AA): text #e6e8ee on #181c24 13.9:1, #b3bbc8 on #181c24 9.0:1, on #21262f 8.1:1;
     control borders #7c8597 on #181c24 4.6:1 (disabled #6b7487 3.6:1; the red button has a #f87171 edge,
     6.0:1); white on the over-photo labels and the lightbox button (black 75%+) >= 10:1. */
  dialog.lightbox .lb-enh-btn { position: absolute; top: 16px; right: 268px; background: rgba(0,0,0,0.75); color: #fff; border: none; border-radius: 20px; padding: 8px 14px; cursor: pointer; font-size: 13px; }
  dialog.lightbox .lb-enh-btn:hover { background: rgba(0,0,0,0.92); }
  dialog.lightbox .lb-enh-btn:focus-visible { outline: 2px solid #9dbcff; outline-offset: 2px; }
  dialog.enhance { position: fixed; inset: 0; width: 100vw; height: 100vh; max-width: none; max-height: none; margin: 0; padding: 0; border: none; background: #0b0d11; color: var(--text); }
  dialog.enhance[open] { display: flex; }
  dialog.enhance::backdrop { background: rgba(0,0,0,0.9); }
  .enh-wrap { display: flex; width: 100%; height: 100%; min-height: 0; }
  .enh-stage { flex: 1; min-width: 0; min-height: 0; position: relative; display: flex; align-items: center; justify-content: center; padding: 16px; }
  .enh-frame { position: relative; --split: 50%; touch-action: none; cursor: ew-resize; user-select: none; }
  .enh-frame img, .enh-frame canvas { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }
  .enh-frame canvas { clip-path: inset(0 0 0 var(--split)); }
  .enh-frame.show-before canvas, .enh-frame.show-before .enh-divider, .enh-frame.show-before .enh-tag-after { visibility: hidden; }
  .enh-divider { position: absolute; top: 0; bottom: 0; left: var(--split); width: 2px; margin-left: -1px; background: #fff; box-shadow: 0 0 0 1px rgba(0,0,0,0.85); pointer-events: none; }
  .enh-tag { position: absolute; top: 8px; padding: 3px 8px; border-radius: 4px; background: rgba(0,0,0,0.78); color: #fff; font-size: 12px; pointer-events: none; }
  .enh-tag-before { left: 8px; }
  .enh-tag-after { right: 8px; }
  .enh-busy { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.85); color: #fff; padding: 10px 16px; border-radius: 8px; font-size: 14px; display: none; }
  .enh-busy.show { display: block; }
  .enh-panel { width: 340px; flex: none; background: var(--panel); border-left: 1px solid var(--line); overflow-y: auto; padding: 0 18px 18px; }
  .enh-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; position: sticky; top: 0; background: var(--panel); padding: 14px 0 10px; z-index: 1; }
  .enh-head h2 { margin: 0; font-size: 17px; }
  .enh-name { font-size: 12px; color: #b3bbc8; word-break: break-all; margin: 0 0 10px; }
  .enh-panel button { border-color: #7c8597; }
  .enh-panel button:focus-visible, .enh-panel input:focus-visible { outline: 2px solid #9dbcff; outline-offset: 2px; }
  .enh-panel button:disabled { opacity: 1; color: #b3bbc8; background: #21262f; border-color: #6b7487; }
  .enh-panel button.primary:disabled { background: #21262f; border-color: #6b7487; color: #b3bbc8; }
  .enh-panel button.danger { background: var(--err-bg); border-color: #f87171; color: #fff; }
  .enh-panel button.danger:hover { background: #a82020; }
  .enh-close { width: 34px; height: 34px; padding: 0; font-size: 20px; line-height: 1; border-radius: 50%; }
  .enh-section { border-top: 1px solid var(--line); padding: 12px 0 4px; }
  .enh-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
  .enh-row { display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 2px 8px; margin: 8px 0 12px; }
  .enh-row label { margin: 0; font-size: 13px; color: var(--text); }
  .enh-row output { font-variant-numeric: tabular-nums; font-size: 13px; color: var(--text); min-width: 3.2em; text-align: right; }
  .enh-row input[type=range] { grid-column: 1 / -1; width: 100%; margin: 4px 0 0; accent-color: var(--accent); }
  .enh-row .enh-ends { grid-column: 1 / -1; display: flex; justify-content: space-between; font-size: 11px; color: #b3bbc8; }
  .enh-note, .enh-hint { font-size: 12px; color: #b3bbc8; line-height: 1.45; margin: 4px 0 10px; }
  .enh-hint.warn { color: #fbbf72; }
  .enh-save fieldset { border: none; margin: 0; padding: 0; }
  .enh-save legend { font-size: 14px; font-weight: 600; padding: 0; margin-bottom: 6px; }
  .enh-save label.enh-radio { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; color: var(--text); margin: 6px 0; cursor: pointer; }
  .enh-save label.enh-radio input { margin-top: 3px; accent-color: var(--accent); }
  .enh-save label.enh-radio.disabled { color: #b3bbc8; cursor: not-allowed; }
  .enh-save #enhSave { width: 100%; margin-top: 6px; padding: 10px 14px; font-size: 14px; }
  .enh-status { font-size: 13px; margin: 10px 0 0; color: var(--text); min-height: 1.4em; }
  .enh-status.err { color: var(--err-text); }
  @media (max-width: 820px) {
    .enh-wrap { flex-direction: column; }
    .enh-stage { flex: 1 1 55%; }
    .enh-panel { width: auto; flex: 1 1 45%; border-left: none; border-top: 1px solid var(--line); }
  }
  .indexbar { font-size:12px; color: var(--mute); }
  .indexbar progress { width: 140px; height: 6px; }
  .mode-banner { background: linear-gradient(135deg, #2a3140, #1e2530); border-bottom: 1px solid var(--line); padding: 10px 18px; color: var(--mute); font-size: 13px; display:flex; align-items:center; gap:10px; }
  .mode-banner.browse { background: linear-gradient(135deg, #1e2a3a, #1a2230); border-left: 3px solid var(--accent); }
  .mode-banner.index { background: linear-gradient(135deg, #1f2e22, #1a2520); border-left: 3px solid var(--ok); }
  .mode-banner strong { color: var(--text); }
  .mode-banner code { background: var(--panel2); padding: 2px 6px; border-radius: 4px; font-size: 12px; color: var(--text); }
  .mode-banner a { color: var(--accent); text-decoration: none; }
  .mode-banner a:hover { text-decoration: underline; }
  .mode-banner .close { margin-left: auto; background: none; border: none; color: var(--mute); cursor: pointer; font-size: 18px; padding: 0 6px; }
  .auth-banner { background: linear-gradient(135deg, #3a2a1e, #2a1f18); border-bottom: 1px solid var(--warn); border-left: 3px solid var(--warn); padding: 10px 18px; color: #f5d29a; font-size: 13px; display:none; align-items:center; gap:10px; }
  .auth-banner.show { display: flex; }
  .auth-banner strong { color: #fff; }
  .auth-banner button.reauth { background: var(--warn); color: #1a1107; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-weight: 600; }
  .auth-banner button.reauth:hover { filter: brightness(1.1); }
  /* Provider settings. Text colours on these panels: --text on #21262f / #1b2230 > 11:1. */
  .provider-panel { display: none; border: 1px solid var(--line); border-radius: 8px; padding: 2px 12px 12px; margin-top: 10px; background: var(--panel2); }
  .provider-panel.show { display: block; }
  .local-check { margin: 10px 0; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel); }
  .local-check .lc-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin: 4px 0; color: var(--text); }
  .local-check .lc-label { color: var(--mute); min-width: 64px; }
  .local-check .lc-row button { padding: 4px 10px; }
  .local-check progress { width: 100%; margin: 6px 0; }
  .local-check .lc-verdict { margin: 8px 0; color: var(--text); }
  .local-check .lc-verdict.good { color: var(--ok); }
  .local-check .lc-verdict.slow { color: var(--warn); }
  .provider-note { font-size: 12px; color: var(--text); background: #1b2230; border-left: 3px solid #9dbcff; padding: 8px 10px; margin-top: 10px; border-radius: 4px; line-height: 1.45; }
  .provider-note.warn { border-left-color: var(--warn); }
  .provider-note ul { margin: 6px 0 6px 18px; padding: 0; }
  .provider-note li { margin: 3px 0; }
  .test-result { font-size: 12px; margin-top: 8px; color: var(--text); white-space: pre-wrap; }
  /* Update banner. #c7d8ff on #17233a is 11.6:1; #0b1220 on #9dbcff is 10.4:1 (WCAG AA). */
  .update-banner { background: #17233a; border-bottom: 1px solid var(--line); border-left: 3px solid #9dbcff; padding: 10px 18px; color: #c7d8ff; font-size: 13px; display:none; align-items:center; gap:10px; }
  .update-banner.show { display: flex; }
  .update-banner strong { color: #fff; }
  .update-banner button.upd { background: #9dbcff; color: #0b1220; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-weight: 600; }
  .update-banner button.upd:hover { background: #b8cfff; }
  .update-banner .close { margin-left: auto; background: none; border: none; color: #c7d8ff; cursor: pointer; font-size: 18px; padding: 0 6px; }
  .version-tag { color: var(--mute); font-size: 12px; font-weight: 400; margin-left: 6px; }
  .pill.clickable { cursor: pointer; }
  .pill.clickable:hover { background: #2a303d; color: var(--text); }
  dialog.errors { background: var(--panel); color: var(--text); border: 1px solid var(--line); border-radius: 12px; padding: 0; max-width: 900px; width: 94%; max-height: 85vh; }
  dialog.errors::backdrop { background: rgba(0,0,0,0.6); }
  dialog.errors .dlg-body { max-height: 65vh; overflow-y: auto; }
  .err-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin: -6px 0 14px; }
  .err-tabs button { background: transparent; border: none; border-bottom: 2px solid transparent; padding: 10px 14px; color: var(--mute); border-radius: 0; font-size: 13px; }
  .err-tabs button.tab-active { color: var(--text); border-bottom-color: var(--accent); }
  .err-tabs button:hover { color: var(--text); background: transparent; }
  .err-item { display: grid; grid-template-columns: 72px 1fr auto; gap: 12px; padding: 10px; border: 1px solid var(--line); border-radius: 8px; margin-bottom: 8px; background: var(--panel2); align-items: center; }
  .err-item.acknowledged { opacity: 0.55; }
  .err-item img { width: 72px; height: 72px; object-fit: cover; border-radius: 4px; background: #0a0c10; cursor: zoom-in; }
  .err-item .err-info { min-width: 0; }
  .err-item .err-path { font-family: ui-monospace, Consolas, monospace; font-size: 11px; color: var(--mute); word-break: break-all; margin-bottom: 4px; }
  .err-item .err-msg { font-size: 12px; color: var(--err-text); white-space: pre-wrap; word-break: break-word; line-height: 1.4; }
  .err-item.acknowledged .err-msg { color: var(--mute); }
  .err-item .err-actions { display: flex; flex-direction: column; gap: 6px; }
  .err-item .err-actions button { padding: 5px 10px; font-size: 11px; white-space: nowrap; }
  .err-item .err-actions button.danger { color: var(--err-text); border-color: rgba(239,68,68,0.4); }
  .err-item .err-actions button.danger:hover { background: rgba(239,68,68,0.15); }
  .err-item .err-ack-badge { font-size: 10px; color: var(--mute); margin-left: 6px; padding: 1px 6px; border: 1px solid var(--line); border-radius: 8px; }
  .err-empty { text-align: center; padding: 40px 20px; color: var(--mute); }
  .err-summary { font-size: 12px; color: var(--mute); margin-bottom: 10px; display: flex; gap: 14px; align-items: center; }
  .err-summary label { display: inline-flex; align-items: center; gap: 6px; margin: 0; cursor: pointer; }
  .log-panel { position: fixed; right: 18px; bottom: 18px; width: min(720px, 92vw); max-height: 50vh; background: var(--panel); border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); display: none; flex-direction: column; z-index: 50; }
  .log-panel.open { display: flex; }
  .log-panel .log-head { padding: 8px 12px; border-bottom: 1px solid var(--line); display: flex; align-items: center; gap: 10px; }
  .log-panel .log-head strong { font-size: 13px; }
  .log-panel .log-head .log-spacer { flex: 1; }
  .log-panel .log-head label { display: inline-flex; align-items: center; gap: 5px; margin: 0; font-size: 11px; color: var(--mute); cursor: pointer; }
  .log-panel .log-head button { padding: 4px 9px; font-size: 11px; }
  .log-panel pre { margin: 0; padding: 10px 14px; flex: 1; overflow: auto; font: 11px/1.5 ui-monospace, Consolas, monospace; color: var(--text); background: #0a0c10; border-radius: 0 0 10px 10px; }
  .log-panel pre .ok { color: var(--ok); }
  .log-panel pre .err { color: var(--err-text); }
  .log-panel pre .mute { color: var(--mute); }
</style>
</head>
<body>
<div class="auth-banner" id="setupBanner" role="status">
  <span>⚙ <strong>Choose how Photo Finder describes your photos.</strong> <span id="setupText">Pick Anthropic Claude, another AI service, or a local model on this PC.</span></span>
  <button class="reauth" id="setupBtn" type="button">Open Settings</button>
</div>
<div class="update-banner" id="updateBanner" role="status">
  <span id="updateText"></span>
  <button class="upd" id="updateBtn" type="button">Install update</button>
  <button class="close" id="updateDismiss" type="button" aria-label="Dismiss" title="Hide until next time">×</button>
</div>
<header>
  <h1>Photo Finder<span class="version-tag">v<?= htmlspecialchars(PF_VERSION) ?></span></h1>
  <div class="search">
    <input id="q" type="text" placeholder="Search your photos — e.g. dog at beach, blue car, christmas tree, document with signature">
    <button class="primary" id="searchBtn">Search</button>
  </div>
  <span class="pill" id="pill-total">Total <span class="num">–</span></span>
  <span class="pill ok" id="pill-indexed">Indexed <span class="num">–</span></span>
  <span class="pill warn clickable" id="pill-pending" title="Click to review photos waiting to be indexed">Pending <span class="num">–</span></span>
  <span class="pill err clickable" id="pill-errors" title="Click to review, retry or acknowledge errors">Errors <span class="num">–</span></span>
  <span class="pill clickable" id="pill-exported" title="Photos with description+tags embedded into the file metadata — click to review embed errors">Embedded <span class="num">–</span></span>
  <span class="pill" id="pill-geo" title="Photos with GPS coordinates extracted from EXIF">📍 <span class="num">–</span></span>
  <span class="indexbar" id="indexbar" style="display:none">
    <progress id="prog" value="0" max="100"></progress>
    <span id="progtxt"></span>
  </span>
  <span class="pill" id="pill-worker" title="Background worker process running in your user context">Worker <span class="num">–</span></span>
  <button id="scanBtn">Scan folders</button>
  <button id="indexBtn" class="primary">Start indexing</button>
  <button id="exportBtn" title="Write descriptions and tags into the photo files themselves (XMP/IPTC) so Windows, Bridge, Lightroom etc. can see them">Embed metadata</button>
  <button id="stopBtn" style="display:none">Stop</button>
  <button id="logBtn" title="Show live worker log">Log</button>
  <button id="settingsBtn">Settings</button>
</header>

<div id="selbar" class="selbar">
  <span class="sel-count" id="selCount">0 selected</span>
  <button type="button" id="selAllBtn">Select all</button>
  <button type="button" id="selClearBtn">Clear</button>
  <span class="sel-spacer"></span>
  <button type="button" id="selDeleteBtn" class="danger">Delete selected</button>
</div>

<main>
  <div class="viewbar" id="viewbar">
    <label for="sortSel">Order</label>
    <select id="sortSel">
      <option value="new">Newest first</option>
      <option value="old">Oldest first</option>
      <option value="relevance">Best match (when searching)</option>
    </select>
    <span class="viewbar-gap"></span>
    <label for="groupSel">Group by</label>
    <select id="groupSel">
      <option value="month">Month</option>
      <option value="day">Day</option>
      <option value="event">Event (photos taken close together)</option>
      <option value="year">Year</option>
      <option value="folder">Folder</option>
      <option value="none">No grouping</option>
    </select>
    <span class="viewbar-count" id="viewCount" aria-live="polite"></span>
  </div>
  <div id="results" class="grid"></div>
  <div id="empty" class="empty" style="display:none"></div>
  <div class="more-wrap" id="moreWrap" style="display:none"><button type="button" id="moreBtn" class="more-btn">Show more photos</button></div>
</main>

<dialog id="settingsDlg" class="settings">
  <form id="settingsForm" method="dialog">
    <div class="dlg-head">
      <h2>Settings</h2>
      <button type="button" id="closeSettings">×</button>
    </div>
    <div class="dlg-body">
      <label>Photo folders (one absolute path per line)</label>
      <textarea id="photoDirs" placeholder="C:\Users\YourName\Pictures&#10;D:\Backups\Photos"></textarea>
      <div class="hint">Subfolders are scanned recursively. Use Windows paths with backslashes or forward slashes.</div>

      <label>Excluded folder names (one per line)</label>
      <textarea id="excludedDirs" placeholder="Screenshots&#10;Thumbnails"></textarea>
      <div class="hint">Folder NAMES (not paths) to skip during scan and search. Match is case-insensitive and matches at any depth. Default: Screenshots.</div>
      <button type="button" id="purgeExcludedBtn" style="margin-top:6px;">Remove already-indexed photos from these folders</button>
      <button type="button" id="purgeMissingBtn" style="margin-top:6px;">Remove broken entries (files no longer on disk)</button>

      <div style="margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--line);">
        <h2 style="margin: 0 0 8px; font-size: 14px; color: var(--text);">How photos are described</h2>
        <div class="hint">An AI model looks at each photo you index and writes its description and search tags. The free option runs that model on this PC, so your photos never leave it; the cloud options send each photo to that service. Keys you enter are stored only on this PC (<?= htmlspecialchars(str_replace('/', '\\', PF_SETTINGS_FILE)) ?>).</div>
        <label for="backend">AI provider</label>
        <select id="backend">
          <option value="local">Free – on this PC (local model; your photos never leave this PC)</option>
          <option value="anthropic">Anthropic Claude (cloud, paid per photo, your API key)</option>
          <option value="openai">Another AI service (cloud, OpenAI-compatible, your API key)</option>
        </select>

        <div class="provider-panel" data-provider="anthropic">
          <label for="apiKey">Anthropic API key</label>
          <input id="apiKey" type="password" autocomplete="off" placeholder="sk-ant-...">
          <div class="hint">Create a key at console.anthropic.com. Leave blank to keep the saved key.</div>
          <label for="model">Model</label>
          <select id="model"></select>
          <div class="provider-note">
            <strong>Cost:</strong> billed by Anthropic to your own account. With Claude Haiku 5.5 at the default image size, roughly $3–5 per 10,000 photos (an estimate). Sonnet costs about 20 times as much, Opus about 40 times. Current prices: anthropic.com/pricing.<br>
            <strong>Privacy:</strong> each photo you index is sent to Anthropic, and Anthropic's privacy policy applies.
          </div>
        </div>

        <div class="provider-panel" data-provider="openai">
          <label for="openaiUrl">Service address (API base URL)</label>
          <input id="openaiUrl" type="text" list="openaiUrlPresets" placeholder="https://api.openai.com/v1">
          <datalist id="openaiUrlPresets">
            <option value="https://api.openai.com/v1">OpenAI</option>
            <option value="https://generativelanguage.googleapis.com/v1beta/openai">Google Gemini</option>
            <option value="https://openrouter.ai/api/v1">OpenRouter</option>
          </datalist>
          <label for="openaiKey">API key</label>
          <input id="openaiKey" type="password" autocomplete="off">
          <div class="hint">Leave blank to keep the saved key.</div>
          <label for="openaiModel">Model (must accept images)</label>
          <div style="display:flex; gap:8px;">
            <input id="openaiModel" type="text" list="openaiModels" style="flex:1">
            <button type="button" id="openaiListBtn">List models</button>
          </div>
          <datalist id="openaiModels"></datalist>
          <div class="provider-note">
            <strong>Cost:</strong> billed by that service to your own account; it depends on the service and model. A small, low-cost model that accepts images is usually enough for photo descriptions.<br>
            <strong>Privacy:</strong> each photo you index is sent to that service, and its privacy policy applies.
          </div>
        </div>

        <div class="provider-panel" data-provider="local">
          <div class="local-check" id="localCheck" aria-live="polite">
            <div class="lc-row"><span class="lc-label">This PC</span><span id="lcPc">Checking…</span></div>
            <div class="lc-row"><span class="lc-label">Ollama</span><span id="lcOllama">Checking…</span>
              <button type="button" id="lcGetOllama" style="display:none">Get Ollama (free)</button></div>
            <div class="lc-row"><span class="lc-label">Model</span><span id="lcModel">Checking…</span>
              <button type="button" id="lcPull" style="display:none"></button></div>
            <progress id="lcProg" max="100" value="0" style="display:none"></progress>
            <div class="lc-verdict" id="lcVerdict"></div>
            <button type="button" id="lcRecheck">Check again</button>
          </div>
          <div class="provider-note warn">
            <strong>Runs entirely on this PC: your photos never leave it.</strong> What to expect:
            <ul>
              <li><strong>Software:</strong> install Ollama (ollama.com) or LM Studio (lmstudio.ai), both free, and download a vision model in it (a model that accepts images).</li>
              <li><strong>Graphics card:</strong> a graphics card with 8 GB or more of video memory is recommended. Without one, the model runs on the processor and can take a minute or more per photo.</li>
              <li><strong>Time:</strong> roughly 3–10 seconds per photo on a good graphics card, so 10,000 photos take about 8–30 hours; without one, several days.</li>
              <li><strong>Disk and memory:</strong> a vision model is typically a 3–10 GB download and needs about that much free memory while it runs.</li>
              <li><strong>Quality:</strong> descriptions are usually less detailed than the cloud options, and reading text in photos is noticeably weaker.</li>
              <li><strong>Cost:</strong> nothing per photo, just your PC's electricity while it indexes.</li>
            </ul>
            These figures are rough; they depend on your hardware and the model you choose.
          </div>
          <label for="localUrl">Local server address</label>
          <input id="localUrl" type="text" list="localUrlPresets">
          <datalist id="localUrlPresets">
            <option value="http://localhost:11434/v1">Ollama</option>
            <option value="http://localhost:1234/v1">LM Studio</option>
          </datalist>
          <div class="hint">Only an address on this PC is accepted. Ollama: http://localhost:11434/v1 · LM Studio: http://localhost:1234/v1</div>
          <label for="localModel">Model</label>
          <div style="display:flex; gap:8px;">
            <input id="localModel" type="text" list="localModels" style="flex:1">
            <button type="button" id="localListBtn">Find models</button>
          </div>
          <datalist id="localModels"></datalist>
        </div>

        <div style="display:flex; gap:8px; align-items:center; margin-top:12px;">
          <button type="button" id="testProviderBtn">Test with a sample picture</button>
          <span class="hint" style="margin:0">Uses the settings above, saved or not. A cloud test costs a fraction of a cent.</span>
        </div>
        <div class="test-result" id="testResult" role="status"></div>

        <label for="maxDim">Max image dimension sent to the AI (px)</label>
        <input id="maxDim" type="number" min="256" max="2000" step="64">
        <div class="hint">Lower = cheaper and faster. 1024–1568 is plenty for content search.</div>

        <div id="reindexBox" style="display:none; margin-top:14px;">
          <div class="hint" id="reindexText" style="margin:0 0 6px;"></div>
          <button type="button" id="reindexBtn">Re-describe them with the current model</button>
        </div>
      </div>

      <label>Batch size per indexing tick</label>
      <input id="batchSize" type="number" min="1" max="25">
      <div class="hint">Photos the worker describes before it checks for new instructions (Stop, Settings changes). 5 is fine.</div>

      <div style="margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--line);">
        <h2 style="margin: 0 0 8px; font-size: 14px; color: var(--text);">Metadata export to image files</h2>
        <div class="hint">Writes descriptions and tags into the image files themselves (XMP/IPTC/EXIF) so Windows Explorer, Adobe Bridge, Lightroom, Apple Photos, etc. can see them. Pixels are not modified.</div>

        <label>ExifTool path</label>
        <div style="display:flex; gap:8px;">
          <input id="exiftoolPath" type="text" placeholder="auto-detect or paste path to exiftool.exe" style="flex:1">
          <button type="button" id="detectExiftoolBtn">Auto-detect</button>
        </div>
        <div class="hint" id="exiftoolPathHint">Bundled at photo-finder/bin/exiftool.exe.</div>

        <label style="display:flex; align-items:center; gap:8px; margin-top:14px;">
          <input id="expDescription" type="checkbox"> Embed description (caption)
        </label>
        <label style="display:flex; align-items:center; gap:8px;">
          <input id="expTags" type="checkbox"> Embed tags (keywords)
        </label>
        <label style="display:flex; align-items:center; gap:8px;">
          <input id="expOcrText" type="checkbox"> Also add OCR'd text-in-image as keywords
        </label>
        <label style="display:flex; align-items:center; gap:8px;">
          <input id="expReplaceDesc" type="checkbox"> Replace existing description (tags are always appended)
        </label>
        <label style="display:flex; align-items:center; gap:8px;">
          <input id="expOverwriteOrig" type="checkbox"> Modify files in place (uncheck to keep .jpg_original backups)
        </label>

        <label>Export batch size</label>
        <input id="expBatchSize" type="number" min="1" max="100">
        <div class="hint">How many photos per exiftool invocation. 25–50 is efficient. Higher = faster but bigger blast radius if a batch fails.</div>
      </div>

      <div style="margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--line);">
        <h2 style="margin: 0 0 8px; font-size: 14px; color: var(--text);">Updates</h2>
        <label style="display:flex; align-items:center; gap:8px; margin-top:6px;">
          <input id="updateAuto" type="checkbox"> Check for updates automatically when Photo Finder opens
        </label>
        <div class="hint">The check asks GitHub (github.com/tadosborn/photo-finder) whether a newer version has been released; nothing about you or your photos is sent. Updates are verified before they install, and your index, settings and photos are kept.</div>
        <div style="display:flex; gap:8px; align-items:center; margin-top:10px;">
          <span id="updateStatus" class="hint" style="flex:1; margin:0;">Version <?= htmlspecialchars(PF_VERSION) ?></span>
          <button type="button" id="checkUpdatesBtn">Check now</button>
        </div>
      </div>
    </div>
    <div class="dlg-foot">
      <button type="button" id="resetErrorsBtn">Reset errored items</button>
      <button type="button" id="clearAllBtn">Clear index</button>
      <button type="button" class="primary" id="saveSettingsBtn">Save</button>
    </div>
  </form>
</dialog>

<dialog id="errorsDlg" class="errors">
  <form method="dialog">
    <div class="dlg-head">
      <h2 id="errorsDlgTitle">Errors</h2>
      <button type="button" id="closeErrors">×</button>
    </div>
    <div class="dlg-body">
      <div class="err-tabs">
        <button type="button" data-err-tab="index" class="tab-active">Indexing errors <span id="errIndexCount">0</span></button>
        <button type="button" data-err-tab="pending">Pending <span id="errPendingCount">0</span></button>
        <button type="button" data-err-tab="export">Embed errors <span id="errExportCount">0</span></button>
      </div>
      <div class="err-summary">
        <span id="errSummaryText"></span>
        <label><input type="checkbox" id="errShowAck"> Show acknowledged</label>
      </div>
      <div id="errList"></div>
    </div>
    <div class="dlg-foot">
      <button type="button" id="retryAllErrorsBtn">Retry all visible</button>
      <button type="button" id="ackAllErrorsBtn">Acknowledge all visible</button>
      <button type="button" class="primary" id="closeErrors2">Done</button>
    </div>
  </form>
</dialog>

<dialog id="lightbox" class="lightbox">
  <button class="lb-nav lb-prev" id="lbPrev" title="Previous (←)">‹</button>
  <img id="lbImg" alt="">
  <button class="lb-nav lb-next" id="lbNext" title="Next (→)">›</button>
  <div class="lb-counter" id="lbCounter"></div>
  <button class="lb-edit-btn" id="lbEditBtn" title="Edit metadata (E)">✎ Edit</button>
  <button class="lb-del-btn" id="lbDelBtn" title="Delete photo — moves file to Recycle Bin (Del)">🗑 Delete</button>
  <button class="lb-enh-btn" id="lbEnhBtn" type="button" title="Fix colour, brightness and contrast; sharpen slightly soft photos">✨ Enhance</button>
  <button class="lb-close" id="lbClose" title="Close (Esc)">×</button>
  <div class="lb-meta">
    <div class="desc" id="lbDesc"></div>
    <div class="tags" id="lbFaces"></div>
    <div class="tags" id="lbTags"></div>
    <div class="tags" id="lbWhere"></div>
    <div class="tags" id="lbPath"></div>
    <div class="tags" style="margin-top:8px; opacity:0.6;">Arrow keys: navigate · I: toggle info · E: edit · Esc: close</div>
  </div>
  <div class="lb-editor" id="lbEditor">
    <h3>Edit metadata <span class="manual-badge" id="lbManualBadge" style="display:none">manually edited — worker won't overwrite</span></h3>
    <label>Description</label>
    <textarea id="editDesc"></textarea>
    <label>Tags (comma separated)</label>
    <input type="text" id="editTags">
    <label>People (comma separated)</label>
    <input type="text" id="editFaces">
    <label>Text in image (OCR)</label>
    <textarea id="editText"></textarea>
    <div class="edit-actions">
      <button type="button" class="reset-btn" id="lbResetManual" title="Discard your edits and have the AI describe this photo again">Reset to AI</button>
      <button type="button" id="lbCancel">Cancel</button>
      <button type="button" class="primary" id="lbSave">Save</button>
    </div>
  </div>
</dialog>

<!-- ==== Enhance view (opened from the lightbox; lib/Enhance.php saves the full-size photo) ==== -->
<dialog id="enhanceDlg" class="enhance" aria-labelledby="enhTitle">
  <div class="enh-wrap">
    <div class="enh-stage" id="enhStage">
      <div class="enh-frame" id="enhFrame">
        <img id="enhBefore" alt="The original photo">
        <canvas id="enhAfter" role="img" aria-label="Preview of the enhanced photo"></canvas>
        <div class="enh-divider" aria-hidden="true"></div>
        <span class="enh-tag enh-tag-before" aria-hidden="true">Original</span>
        <span class="enh-tag enh-tag-after" aria-hidden="true">Enhanced</span>
      </div>
      <div class="enh-busy" id="enhBusy" role="status" aria-live="polite"></div>
    </div>
    <div class="enh-panel">
      <div class="enh-head">
        <h2 id="enhTitle">Enhance</h2>
        <button type="button" class="enh-close" id="enhClose" aria-label="Close Enhance (Esc)" title="Close (Esc)">×</button>
      </div>
      <p class="enh-name" id="enhName"></p>
      <div class="enh-section">
        <div class="enh-row">
          <label for="enhSplit">Compare</label>
          <output for="enhSplit" id="enhSplitOut">50%</output>
          <input type="range" id="enhSplit" min="0" max="100" step="1" value="50" aria-describedby="enhSplitHint">
          <span class="enh-ends" id="enhSplitHint" aria-hidden="true"><span>← more enhanced</span><span>more original →</span></span>
        </div>
        <div class="enh-buttons">
          <button type="button" id="enhToggle" aria-pressed="false">Show original</button>
          <button type="button" id="enhAuto" title="Settings worked out from this photo">Auto</button>
          <button type="button" id="enhReset" title="No changes">Reset</button>
        </div>
      </div>
      <div class="enh-section" id="enhSliders">
        <div class="enh-row"><label for="enh_brightness">Brightness</label><output for="enh_brightness" id="enh_brightness_out">0</output>
          <input type="range" id="enh_brightness" data-enh="brightness" min="-100" max="100" step="1" value="0"></div>
        <div class="enh-row"><label for="enh_contrast">Contrast</label><output for="enh_contrast" id="enh_contrast_out">0</output>
          <input type="range" id="enh_contrast" data-enh="contrast" min="-100" max="100" step="1" value="0"></div>
        <div class="enh-row"><label for="enh_warmth">Warmth</label><output for="enh_warmth" id="enh_warmth_out">0</output>
          <input type="range" id="enh_warmth" data-enh="warmth" min="-100" max="100" step="1" value="0" aria-describedby="enh_warmth_ends">
          <span class="enh-ends" id="enh_warmth_ends"><span>cooler (bluer)</span><span>warmer (more orange)</span></span></div>
        <div class="enh-row"><label for="enh_tint">Tint</label><output for="enh_tint" id="enh_tint_out">0</output>
          <input type="range" id="enh_tint" data-enh="tint" min="-100" max="100" step="1" value="0" aria-describedby="enh_tint_ends">
          <span class="enh-ends" id="enh_tint_ends"><span>greener</span><span>more magenta</span></span></div>
        <div class="enh-row"><label for="enh_saturation">Saturation</label><output for="enh_saturation" id="enh_saturation_out">0</output>
          <input type="range" id="enh_saturation" data-enh="saturation" min="-100" max="100" step="1" value="0"></div>
        <div class="enh-row"><label for="enh_sharpen">Sharpen</label><output for="enh_sharpen" id="enh_sharpen_out">0</output>
          <input type="range" id="enh_sharpen" data-enh="sharpen" min="0" max="100" step="1" value="0" aria-describedby="enhSharpenNote"></div>
        <p class="enh-note" id="enhSharpenNote">Sharpen makes slightly soft photos look crisper. It cannot fix a photo that is badly out of focus or blurred by camera shake.</p>
      </div>
      <div class="enh-section enh-save">
        <fieldset>
          <legend>Save</legend>
          <label class="enh-radio"><input type="radio" name="enhMode" value="copy" checked> <span>Save as a copy</span></label>
          <label class="enh-radio" id="enhReplaceLabel"><input type="radio" name="enhMode" value="replace" id="enhReplaceRadio"> <span>Replace the original</span></label>
        </fieldset>
        <p class="enh-hint" id="enhSaveHint" aria-live="polite"></p>
        <button type="button" class="primary" id="enhSave">Save as a copy</button>
        <p class="enh-status" id="enhStatus" role="status" aria-live="polite"></p>
      </div>
    </div>
  </div>
</dialog>

<div id="logPanel" class="log-panel">
  <div class="log-head">
    <strong>Indexing log</strong>
    <span class="log-spacer"></span>
    <label><input type="checkbox" id="logAutoScroll" checked> Auto-scroll</label>
    <label><input type="checkbox" id="logLive" checked> Live</label>
    <button type="button" id="logRefreshBtn">Refresh</button>
    <button type="button" id="logCloseBtn">×</button>
  </div>
  <pre id="logOutput">(loading…)</pre>
</div>

<div id="toast" class="toast"></div>

<script>
const $ = s => document.querySelector(s);
const $$ = s => document.querySelectorAll(s);

let indexing = false;
let lastResults = [];
let selectedIds = new Set();

function toast(msg, kind='ok', ms=3000) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'toast show ' + kind;
  setTimeout(() => t.classList.remove('show'), ms);
}

async function api(action, opts = {}) {
  const url = 'api.php?action=' + encodeURIComponent(action) + (opts.qs ? '&' + opts.qs : '');
  const init = { method: opts.body ? 'POST' : 'GET' };
  if (opts.body) {
    init.headers = { 'Content-Type': 'application/json' };
    init.body = JSON.stringify(opts.body);
  }
  const r = await fetch(url, init);
  const data = await r.json();
  if (!r.ok) throw new Error(data.error || ('HTTP ' + r.status));
  return data;
}

function setStats(s) {
  if (!s) return;
  $('#pill-total .num').textContent = s.total;
  $('#pill-indexed .num').textContent = s.indexed;
  $('#pill-pending .num').textContent = s.pending;
  $('#pill-errors .num').textContent = s.errors;
  if ('exported' in s) {
    $('#pill-exported .num').textContent = s.exported;
    $('#pill-exported').title = `${s.exported} embedded, ${s.export_pending} pending, ${s.export_errors} errors`;
  }
  if ('with_location' in s) {
    $('#pill-geo .num').textContent = s.with_location;
    $('#pill-geo').title = `${s.with_location} have GPS, ${s.with_date} have capture date (of ${s.total} total)`;
  }
}

async function refreshStats() {
  try { setStats(await api('stats')); } catch (e) {}
}

function renderResults(rows) {
  const grid = $('#results');
  const empty = $('#empty');
  lastResults = rows;
  grid.innerHTML = '';
  if (!rows.length) {
    empty.style.display = 'block';
    empty.textContent = $('#q').value
      ? 'No matches. Try different words, or make sure indexing has run.'
      : 'No photos indexed yet. Open Settings → add a folder → click Scan → click Start indexing.';
    return;
  }
  empty.style.display = 'none';
  // Drop any selected ids that are no longer in the result set.
  const present = new Set(rows.map(r => r.id));
  selectedIds.forEach(id => { if (!present.has(id)) selectedIds.delete(id); });
  const frag = document.createDocumentFragment();
  const heads = groupHeadings(rows);
  rows.forEach((row, idx) => {
    if (heads.has(idx)) {
      const g = heads.get(idx);
      const h = document.createElement('h2');
      h.className = 'group-head';
      h.textContent = g.label;
      const c = document.createElement('span');
      c.className = 'group-count';
      c.textContent = g.count + (g.count === 1 ? ' photo' : ' photos') + (g.more ? '+' : '');
      h.appendChild(c);
      frag.appendChild(h);
    }
    const card = document.createElement('div');
    card.className = 'card' + (selectedIds.has(row.id) ? ' selected' : '');
    card.dataset.id = row.id;
    const snip = row.snip || row.description || '';
    card.innerHTML = `
      <div class="card-tools">
        <input type="checkbox" class="sel" title="Select"${selectedIds.has(row.id) ? ' checked' : ''}>
        <button type="button" class="del" title="Delete — moves file to Recycle Bin">🗑</button>
      </div>
      <div class="imgwrap" data-idx="${idx}">
        <img loading="lazy" src="thumb.php?id=${row.id}${row.v ? '&v=' + row.v : ''}" alt="">
      </div>
      <div class="meta">
        <div class="desc">${snip}</div>
        <div class="path">${escapeHtml(row.path)}</div>
      </div>`;
    card.querySelector('.imgwrap').addEventListener('click', () => openLightbox(idx));
    card.querySelector('.sel').addEventListener('change', e => {
      e.stopPropagation();
      toggleSelect(row.id, e.target.checked);
    });
    card.querySelector('.del').addEventListener('click', async e => {
      e.stopPropagation();
      if (!confirm('Move this photo to the Recycle Bin?\n\n' + row.path)) return;
      await deletePhotos([row.id]);
      renderResults(lastResults);
    });
    frag.appendChild(card);
  });
  grid.appendChild(frag);
  updateSelBar();
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ---- Order and grouping of the photo grid ----
// sort_date (from the server) is the camera date "YYYY-MM-DDTHH:MM:SS", or just "YYYY" when only
// the photo's folder gives a year, or null when nothing does.
const view = {
  sort: localStorage.getItem('pf.sort') || 'new',
  group: localStorage.getItem('pf.group') || 'month',
  more: false,
  loading: false,
};
const dateOf = d => d && d.length >= 10 ? new Date(+d.slice(0, 4), +d.slice(5, 7) - 1, +d.slice(8, 10), +(d.slice(11, 13) || 0), +(d.slice(14, 16) || 0)) : null;
const fmt = (d, opts) => d.toLocaleDateString(undefined, opts);
function groupOf(row) {
  const d = row.sort_date || '';
  const exact = d.length >= 10;
  switch (view.group) {
    case 'folder': {
      const dir = row.path.replace(/\\/g, '/').replace(/\/[^/]*$/, '');
      return { key: dir, label: dir.split('/').slice(-2).join(' › ') };
    }
    case 'year':
      return d ? { key: d.slice(0, 4), label: d.slice(0, 4) } : { key: 'none', label: 'No date' };
    case 'day':
      if (exact) return { key: d.slice(0, 10), label: fmt(dateOf(d), { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' }) };
      return d ? { key: d, label: d + ' (no exact date)' } : { key: 'none', label: 'No date' };
    default:   // month; also the fallback for photos without an exact date in 'event'
      if (exact) return { key: d.slice(0, 7), label: fmt(dateOf(d), { month: 'long', year: 'numeric' }) };
      return d ? { key: d, label: d + ' (no exact date)' } : { key: 'none', label: 'No date' };
  }
}
/** Map of row index => {label, count, more} for each row that starts a group. */
function groupHeadings(rows) {
  const out = new Map();
  if (view.group === 'none' || (view.sort === 'relevance' && $('#q').value.trim())) return out;
  let start = -1, key = null, label = '', prev = null, first = null;
  const close = end => {
    if (start < 0) return;
    let l = label;
    if (view.group === 'event' && first && prev) {   // event: show its date range
      const a = fmt(first, { day: 'numeric', month: 'short', year: 'numeric' }), b = fmt(prev, { day: 'numeric', month: 'short', year: 'numeric' });
      l = a === b ? fmt(first, { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' }) : (view.sort === 'old' ? a + ' – ' + b : b + ' – ' + a);
    }
    out.set(start, { label: l, count: end - start, more: false });
  };
  rows.forEach((row, i) => {
    let k, lab;
    const d = dateOf(row.sort_date);
    if (view.group === 'event' && d) {
      // A new event starts after a gap of more than 12 hours between photos.
      const gap = prev ? Math.abs(d - prev) / 36e5 : Infinity;
      k = gap > 12 || key === null || String(key).startsWith('x') ? 'e' + i : key;
      lab = '';
    } else {
      const g = groupOf(row); k = 'x' + g.key; lab = g.label;
    }
    if (k !== key) { close(i); start = i; key = k; label = lab; first = d; }
    prev = d || prev;
  });
  close(rows.length);
  // The last group may continue on the next page.
  if (view.more && out.size) { const last = [...out.keys()].pop(); out.get(last).more = true; }
  return out;
}

// ---- Selection + deletion ----
function updateSelBar() {
  const n = selectedIds.size;
  $('#selCount').textContent = n + ' selected';
  $('#selbar').classList.toggle('show', n > 0);
  $('#selDeleteBtn').textContent = n > 1 ? `Delete selected (${n})` : 'Delete selected';
}

function toggleSelect(id, on) {
  if (on) selectedIds.add(id); else selectedIds.delete(id);
  const card = document.querySelector(`.card[data-id="${id}"]`);
  if (card) {
    card.classList.toggle('selected', on);
    const cb = card.querySelector('.sel');
    if (cb) cb.checked = on;
  }
  updateSelBar();
}

/** Recycle the given photo ids (files → Recycle Bin) and drop them from the
 *  in-memory result set. Does NOT re-render — callers refresh as needed. */
async function deletePhotos(ids) {
  if (!ids.length) return null;
  try {
    const r = await api('delete_files', { body: { ids } });
    const removed = new Set(r.deleted_ids || []);
    if (removed.size) {
      lastResults = lastResults.filter(x => !removed.has(x.id));
      removed.forEach(id => selectedIds.delete(id));
    }
    if (r.stats) setStats(r.stats);
    const failN = (r.failed || []).length;
    if (r.deleted) {
      toast(`Moved ${r.deleted} photo${r.deleted === 1 ? '' : 's'} to Recycle Bin`
        + (failN ? ` — ${failN} failed` : ''), failN ? 'err' : 'ok');
    } else {
      toast(failN ? `Delete failed: ${r.failed[0].error}` : 'Nothing deleted', 'err');
    }
    return r;
  } catch (e) { toast(e.message, 'err'); return null; }
}

$('#selAllBtn').addEventListener('click', () => {
  lastResults.forEach(r => selectedIds.add(r.id));
  renderResults(lastResults);
});
$('#selClearBtn').addEventListener('click', () => {
  selectedIds.clear();
  renderResults(lastResults);
});
$('#selDeleteBtn').addEventListener('click', async () => {
  const ids = [...selectedIds];
  if (!ids.length) return;
  if (!confirm(`Move ${ids.length} selected photo${ids.length === 1 ? '' : 's'} to the Recycle Bin?`)) return;
  $('#selDeleteBtn').disabled = true;
  await deletePhotos(ids);
  renderResults(lastResults);
  $('#selDeleteBtn').disabled = false;
});

let lbIndex = -1;

function openLightbox(idx) {
  if (idx < 0 || idx >= lastResults.length) return;
  lbIndex = idx;
  const r = lastResults[idx];
  $('#lbImg').src = 'file.php?id=' + r.id + (r.v ? '&v=' + r.v : '');
  $('#lbDesc').textContent = r.description || '';
  $('#lbFaces').textContent = r.faces ? '👤 people: ' + r.faces : '';
  $('#lbTags').textContent = r.tags ? 'tags: ' + r.tags : '';
  // Date + GPS line. Coordinates link to OpenStreetMap.
  const where = [];
  if (r.taken_at) where.push('📅 ' + String(r.taken_at).replace('T', ' '));
  if (r.lat != null && r.lon != null) {
    const lat = (+r.lat).toFixed(6), lon = (+r.lon).toFixed(6);
    const url = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=15/${lat}/${lon}`;
    where.push(`📍 <a href="${url}" target="_blank" rel="noopener" style="color:var(--accent);text-decoration:none;">${lat}, ${lon}</a>`);
  }
  $('#lbWhere').innerHTML = where.join(' · ');
  $('#lbPath').textContent = r.path;
  $('#lbCounter').textContent = (idx + 1) + ' / ' + lastResults.length;
  $('#lbPrev').style.visibility = idx > 0 ? 'visible' : 'hidden';
  $('#lbNext').style.visibility = idx < lastResults.length - 1 ? 'visible' : 'hidden';
  closeEditor();
  if (!$('#lightbox').open) $('#lightbox').showModal();
}

async function openEditor() {
  if (lbIndex < 0) return;
  const r = lastResults[lbIndex];
  // Fetch fresh data so we have the latest (including text_in_image which isn't in search results)
  let full = r;
  try { full = await api('image_info', { qs: 'id=' + encodeURIComponent(r.id) }); } catch (e) {}
  $('#editDesc').value = full.description || '';
  $('#editTags').value = full.tags || '';
  $('#editFaces').value = full.faces || '';
  $('#editText').value = full.text_in_image || '';
  $('#lbManualBadge').style.display = full.description_manual ? '' : 'none';
  $('#lbResetManual').style.display = full.description_manual ? '' : 'none';
  $('#lbEditor').classList.add('open');
  setTimeout(() => $('#editDesc').focus(), 50);
}
function closeEditor() { $('#lbEditor').classList.remove('open'); }

async function saveEditor() {
  if (lbIndex < 0) return;
  const r = lastResults[lbIndex];
  const body = {
    id: r.id,
    description: $('#editDesc').value,
    tags: $('#editTags').value,
    faces: $('#editFaces').value,
    text_in_image: $('#editText').value,
  };
  try {
    const resp = await api('update_metadata', { body });
    if (resp.image) {
      // Update local cache so future navigation shows the new values
      Object.assign(r, {
        description: resp.image.description,
        tags: resp.image.tags,
        faces: resp.image.faces,
      });
      openLightbox(lbIndex);   // refresh display
    }
    toast('Saved — worker won\'t overwrite this one anymore');
    closeEditor();
  } catch (e) { toast(e.message, 'err'); }
}

async function resetManual() {
  if (lbIndex < 0) return;
  if (!confirm('Discard your manual edits and let the AI describe this photo again? It will be queued for re-indexing.')) return;
  const r = lastResults[lbIndex];
  try {
    await api('reset_manual', { qs: 'id=' + encodeURIComponent(r.id), body: {} });
    toast('Reset — photo queued for re-indexing');
    closeEditor();
  } catch (e) { toast(e.message, 'err'); }
}

async function deleteCurrentInLightbox() {
  if (lbIndex < 0) return;
  const r = lastResults[lbIndex];
  if (!r) return;
  if (!confirm('Move this photo to the Recycle Bin?\n\n' + (r.path || ''))) return;
  const idx = lbIndex;
  const res = await deletePhotos([r.id]);
  if (!res || !res.deleted) return;
  renderResults(lastResults);
  if (!lastResults.length) { $('#lightbox').close(); return; }
  openLightbox(Math.min(idx, lastResults.length - 1));
}

$('#lbEditBtn').addEventListener('click', openEditor);
$('#lbDelBtn').addEventListener('click', deleteCurrentInLightbox);
$('#lbCancel').addEventListener('click', closeEditor);
$('#lbSave').addEventListener('click', saveEditor);
$('#lbResetManual').addEventListener('click', resetManual);

function navLightbox(delta) {
  const next = lbIndex + delta;
  if (next >= 0 && next < lastResults.length) openLightbox(next);
}

$('#lightbox').addEventListener('click', e => {
  if (e.target.id === 'lightbox' || e.target.id === 'lbImg') $('#lightbox').close();
});
$('#lbClose').addEventListener('click', () => $('#lightbox').close());
$('#lbPrev').addEventListener('click', e => { e.stopPropagation(); navLightbox(-1); });
$('#lbNext').addEventListener('click', e => { e.stopPropagation(); navLightbox(1); });
document.addEventListener('keydown', e => {
  if (!$('#lightbox').open) return;
  // If the user is typing in an input/textarea (e.g. the editor), don't hijack keys.
  const t = e.target;
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) {
    // Allow Esc inside the editor to close it (not the whole lightbox)
    if (e.key === 'Escape' && $('#lbEditor').classList.contains('open')) {
      e.preventDefault();
      e.stopPropagation();
      closeEditor();
    }
    return;
  }
  if (e.key === 'ArrowLeft') { e.preventDefault(); navLightbox(-1); }
  else if (e.key === 'ArrowRight') { e.preventDefault(); navLightbox(1); }
  else if (e.key === 'i' || e.key === 'I') { e.preventDefault(); $('#lightbox').classList.toggle('no-meta'); }
  else if (e.key === 'e' || e.key === 'E') { e.preventDefault(); openEditor(); }
  else if (e.key === 'Delete') { e.preventDefault(); deleteCurrentInLightbox(); }
});

// ==== Enhance: the preview is drawn here, the full-size photo is saved by lib/Enhance.php ====
const ENH_KEYS = ['brightness', 'contrast', 'warmth', 'tint', 'saturation', 'sharpen'];
const ENH_W = [0.299, 0.587, 0.114];
const enh = { id: null, data: null, params: null, src: null, blur: null, out: null, ctx: null, w: 0, h: 0, raf: 0, armed: 0, saving: false };

/* enhKnobs, enhGains, enhCurves and enhTables mirror Enhance::knobs, ::gains, ::curves and
   ::tables in lib/Enhance.php, operation for operation, so the preview computes exactly what the
   full-size save computes. Change one, change the other. */
function enhKnobs(p) {
  const b = p.brightness / 100;
  return { k: Math.pow(2, 2.5 * b) - 1, c: p.contrast / 100 * 0.8, T: p.warmth / 100 * 0.4,
           N: p.tint / 100 * 0.3, s: 1 + p.saturation / 100, a: p.sharpen / 100 };
}
function enhGains(T, N) {
  const g = [Math.exp(T), Math.exp(-N), Math.exp(-T)];
  const n = ENH_W[0] * g[0] + ENH_W[1] * g[1] + ENH_W[2] * g[2];
  return [g[0] / n, g[1] / n, g[2] / n];
}
function enhCurves(p) {
  const q = enhKnobs(p), g = enhGains(q.T, q.N);
  const lo = p.levels ? p.levels.lo : 0, hi = p.levels ? p.levels.hi : 1, span = hi - lo;
  const L = [new Float64Array(256), new Float64Array(256), new Float64Array(256)];
  for (let ch = 0; ch < 3; ch++) {
    for (let v = 0; v < 256; v++) {
      let x = (g[ch] * v / 255 - lo) / span;
      x = x < 0 ? 0 : (x > 1 ? 1 : x);
      if (q.k !== 0) x = (1 + q.k) * x / (1 + q.k * x);
      if (q.c !== 0) x = x + q.c * (2 * x - 1) * x * (1 - x);
      L[ch][v] = 255 * x;
    }
  }
  return L;
}
function enhTables(p) {
  const L = enhCurves(p), q = enhKnobs(p), s = q.s, a = q.a;
  const T = [[], [], []], U = [];
  for (let c = 0; c < 3; c++) {
    for (let k = 0; k < 3; k++) {
      const m = (c === k ? s : 0) + (1 - s) * ENH_W[k] + a * ENH_W[k];
      const t = new Int32Array(256);
      for (let v = 0; v < 256; v++) t[v] = Math.floor(64 * m * L[k][v] + 0.5);
      T[c][k] = t;
    }
  }
  for (let k = 0; k < 3; k++) {
    const m = a * ENH_W[k], u = new Int32Array(256);
    for (let v = 0; v < 256; v++) u[v] = Math.floor(64 * m * L[k][v] + 0.5);
    U[k] = u;
  }
  return [T, U];
}
/* GD's IMG_FILTER_GAUSSIAN_BLUR: [1 2 1; 2 4 2; 1 2 1] / 16, edge pixels repeated, truncated. */
function enhGauss(src, w, h) {
  const out = new Uint8ClampedArray(src.length);
  for (let y = 0; y < h; y++) {
    const rowU = Math.max(y - 1, 0) * w, rowC = y * w, rowD = Math.min(y + 1, h - 1) * w;
    for (let x = 0; x < w; x++) {
      const xl = Math.max(x - 1, 0), xr = Math.min(x + 1, w - 1);
      const a = (rowU + xl) * 4, b = (rowU + x) * 4, c = (rowU + xr) * 4;
      const d = (rowC + xl) * 4, e = (rowC + x) * 4, f = (rowC + xr) * 4;
      const g = (rowD + xl) * 4, h2 = (rowD + x) * 4, i = (rowD + xr) * 4;
      for (let ch = 0; ch < 3; ch++) {
        out[e + ch] = (src[a + ch] + 2 * src[b + ch] + src[c + ch] + 2 * src[d + ch] + 4 * src[e + ch]
          + 2 * src[f + ch] + src[g + ch] + 2 * src[h2 + ch] + src[i + ch]) >> 4;
      }
      out[e + 3] = 255;
    }
  }
  return out;
}
function enhRender() {
  enh.raf = 0;
  if (!enh.src || !enh.params) return;
  const [T, U] = enhTables(enh.params);
  const src = enh.src, out = enh.out.data, n = src.length;
  const r0 = T[0][0], r1 = T[0][1], r2 = T[0][2], g0 = T[1][0], g1 = T[1][1], g2 = T[1][2], b0 = T[2][0], b1 = T[2][1], b2 = T[2][2];
  if (enh.params.sharpen > 0) {
    const bl = enh.blur, u0 = U[0], u1 = U[1], u2 = U[2];
    for (let i = 0; i < n; i += 4) {
      const r = src[i], g = src[i + 1], b = src[i + 2];
      const d = u0[bl[i]] + u1[bl[i + 1]] + u2[bl[i + 2]];
      out[i] = (r0[r] + r1[g] + r2[b] - d + 32) >> 6;
      out[i + 1] = (g0[r] + g1[g] + g2[b] - d + 32) >> 6;
      out[i + 2] = (b0[r] + b1[g] + b2[b] - d + 32) >> 6;
      out[i + 3] = 255;
    }
  } else {
    for (let i = 0; i < n; i += 4) {
      const r = src[i], g = src[i + 1], b = src[i + 2];
      out[i] = (r0[r] + r1[g] + r2[b] + 32) >> 6;
      out[i + 1] = (g0[r] + g1[g] + g2[b] + 32) >> 6;
      out[i + 2] = (b0[r] + b1[g] + b2[b] + 32) >> 6;
      out[i + 3] = 255;
    }
  }
  enh.ctx.putImageData(enh.out, 0, 0);
}
function enhSchedule() { if (!enh.raf) enh.raf = requestAnimationFrame(enhRender); }
function enhP3() {
  try {
    const c = document.createElement('canvas').getContext('2d', { colorSpace: 'display-p3' });
    return !!(c && c.getContextAttributes && c.getContextAttributes().colorSpace === 'display-p3');
  } catch (e) { return false; }
}
function enhIsNeutral(p) { return !p || (!p.levels && ENH_KEYS.every(k => p[k] === 0)); }
function enhFmt(key, v) { return key === 'sharpen' || v <= 0 ? String(v) : '+' + v; }
function enhBusy(text) { const b = $('#enhBusy'); b.textContent = text; b.classList.toggle('show', !!text); }
function enhStatus(text, isErr) { const s = $('#enhStatus'); s.textContent = text; s.classList.toggle('err', !!isErr); }
function enhSetControls(on) {
  $$('#enhanceDlg .enh-panel input, #enhanceDlg .enh-panel button').forEach(el => {
    if (el.id === 'enhClose') return;
    el.disabled = !on || (el.id === 'enhReplaceRadio' && !(enh.data && enh.data.can_replace));
  });
  if (on) enhSaveState();
}
function enhMode() { return document.querySelector('input[name=enhMode]:checked').value; }
function enhSaveState() {
  const btn = $('#enhSave'), hint = $('#enhSaveHint'), mode = enhMode(), d = enh.data;
  const none = enhIsNeutral(enh.params);
  btn.disabled = none || enh.saving || !d;
  btn.classList.toggle('danger', mode === 'replace' && !!enh.armed);
  btn.classList.toggle('primary', !(mode === 'replace' && enh.armed));
  hint.classList.toggle('warn', mode === 'replace' && !!enh.armed);
  $('#enhReplaceLabel').classList.toggle('disabled', !(d && d.can_replace));
  if (mode === 'copy') {
    btn.textContent = 'Save as a copy';
    hint.textContent = none ? 'No changes to save.' : 'Saves “' + (d ? d.copy_name : '') + '” next to the original, with the same date, place, description and people. The original is not changed.';
  } else if (!enh.armed) {
    btn.textContent = 'Replace the original…';
    hint.textContent = none ? 'No changes to save.' : 'The original goes to the Recycle Bin (you can restore it from there) and the enhanced photo takes its name, date, place, description and people.';
  } else {
    btn.textContent = 'Click again to replace the original';
    hint.textContent = 'Are you sure? Click the red button within 8 seconds to replace “' + (d ? d.name : '') + '”. The original goes to the Recycle Bin.';
  }
  if (d && !d.can_replace) $('#enhReplaceLabel').title = 'Replacing works for JPEG photos only. Save as a copy instead.';
}
function enhArm(on) {
  clearTimeout(enh.armTimer);
  enh.armed = on ? Date.now() : 0;
  if (on) enh.armTimer = setTimeout(() => { enh.armed = 0; enhSaveState(); }, 8000);
  enhSaveState();
}
function enhSetParams(p) {
  enh.params = JSON.parse(JSON.stringify(p));
  ENH_KEYS.forEach(k => {
    const el = $('#enh_' + k);
    el.value = enh.params[k];
    $('#enh_' + k + '_out').textContent = enhFmt(k, enh.params[k]);
  });
  enhArm(false);
  enhSchedule();
}
function enhSplit(v) {
  v = Math.max(0, Math.min(100, Math.round(v)));
  $('#enhFrame').style.setProperty('--split', v + '%');
  $('#enhSplit').value = v;
  $('#enhSplitOut').textContent = v + '%';
}
function enhLayout() {
  if (!enh.data) return;
  const stage = $('#enhStage'), cs = getComputedStyle(stage);
  const aw = stage.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
  const ah = stage.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
  const w = enh.data.preview.width, h = enh.data.preview.height;
  const scale = Math.max(0.05, Math.min(aw / w, ah / h, 2));
  const f = $('#enhFrame');
  f.style.width = Math.floor(w * scale) + 'px';
  f.style.height = Math.floor(h * scale) + 'px';
}

async function enhOpen() {
  if (lbIndex < 0 || !lastResults[lbIndex]) return;
  const r = lastResults[lbIndex];
  const dlg = $('#enhanceDlg');
  Object.assign(enh, { id: r.id, data: null, params: null, src: null, blur: null, out: null, armed: 0 });
  $('#enhName').textContent = String(r.path || '').split('/').pop();
  $('#enhBefore').removeAttribute('src');
  $('#enhFrame').style.width = $('#enhFrame').style.height = '0px';
  $('#enhToggle').setAttribute('aria-pressed', 'false');
  $('#enhToggle').textContent = 'Show original';
  $('#enhFrame').classList.remove('show-before');
  document.querySelector('input[name=enhMode][value=copy]').checked = true;
  enhSplit(50);
  enhStatus('');
  enhBusy('Preparing the preview…');
  enhSetControls(false);
  if (!dlg.open) dlg.showModal();
  let d;
  try {
    d = await api('enhance_open', { qs: 'id=' + encodeURIComponent(r.id) });
  } catch (e) {
    enhBusy('');
    enhStatus(e.message, true);
    return;
  }
  if (enh.id !== r.id || !dlg.open) return;
  const img = $('#enhBefore');
  img.src = d.preview.src;
  try { await img.decode(); } catch (e) { enhBusy(''); enhStatus('Could not show the preview.', true); return; }
  if (enh.id !== r.id || !dlg.open) return;
  // Draw in the photo's own colour space (wide-gamut phone photos are Display P3), so the
  // numbers the preview works on are the numbers the saved file has.
  const w = d.preview.width, h = d.preview.height;
  const space = d.preview.color !== 'srgb' && enhP3() ? 'display-p3' : 'srgb';
  const old = $('#enhAfter'), cv = document.createElement('canvas');
  cv.id = 'enhAfter';
  cv.setAttribute('role', 'img');
  cv.setAttribute('aria-label', old.getAttribute('aria-label'));
  cv.width = w;
  cv.height = h;
  old.replaceWith(cv);
  const ctx = cv.getContext('2d', { colorSpace: space, willReadFrequently: true });
  ctx.drawImage(img, 0, 0, w, h);
  enh.src = ctx.getImageData(0, 0, w, h, { colorSpace: space }).data;
  enh.blur = enhGauss(enh.src, w, h);
  enh.out = ctx.createImageData(w, h, { colorSpace: space });
  Object.assign(enh, { data: d, ctx, w, h });
  enhLayout();
  enhSetParams(d.auto);
  enhBusy('');
  enhSetControls(true);
  enhStatus(enhIsNeutral(d.auto) ? 'Auto found nothing to change; use the sliders.' : 'Auto settings applied. Adjust them or save.');
  $('#enhAuto').focus();
}

function enhClose() { const dlg = $('#enhanceDlg'); if (dlg.open) dlg.close(); }

async function enhSave() {
  if (!enh.params || enh.saving || !enh.data || enhIsNeutral(enh.params)) return;
  const mode = enhMode();
  if (mode === 'replace' && !enh.armed) { enhArm(true); return; }
  enh.saving = true;
  enhArm(false);
  enhSetControls(false);
  enhBusy('Saving the full-size photo…');
  enhStatus('Saving. Large photos take a few seconds.');
  const id = enh.id;
  try {
    const res = await api('enhance_save', { body: { id, mode, params: enh.params } });
    const row = res.image;
    // Re-draw the grid only if this photo is in it (the Errors dialog shows photos in the lightbox too).
    const inGrid = !!document.querySelector('.card[data-id="' + id + '"]');
    if (mode === 'copy') {
      lastResults = lastResults.filter(x => x.id !== row.id);
      const at = lastResults.findIndex(x => x.id === id);
      lastResults.splice(at + 1, 0, row);
      if (inGrid) renderResults(lastResults);
      enh.saving = false;
      enhClose();
      openLightbox(lastResults.indexOf(row));
      toast('Saved “' + res.name + '” next to the original.');
    } else {
      const at = lastResults.findIndex(x => x.id === id);
      if (at >= 0) Object.assign(lastResults[at], row);
      // The thumbnail and photo URLs are unchanged: fetch them again so the browser's cache has the new picture.
      fetch('thumb.php?id=' + encodeURIComponent(id), { cache: 'reload' }).catch(() => {});
      fetch('file.php?id=' + encodeURIComponent(id), { cache: 'reload' }).catch(() => {});
      if (inGrid) renderResults(lastResults);
      enh.saving = false;
      enhClose();
      if (at >= 0) openLightbox(at);
      toast('Replaced “' + res.name + '”. The original is in the Recycle Bin.');
    }
    if (res.stats) setStats(res.stats);
  } catch (e) {
    enhStatus(e.message, true);
  } finally {
    enh.saving = false;
    enhBusy('');
    if ($('#enhanceDlg').open) enhSetControls(true);
  }
}

$('#lbEnhBtn').addEventListener('click', e => { e.stopPropagation(); enhOpen(); });
$('#enhClose').addEventListener('click', enhClose);
$('#enhSave').addEventListener('click', enhSave);
$('#enhAuto').addEventListener('click', () => { if (enh.data) { enhSetParams(enh.data.auto); enhStatus('Auto settings applied.'); } });
$('#enhReset').addEventListener('click', () => { if (enh.data) { enhSetParams(enh.data.neutral); enhStatus('Reset: no changes.'); } });
$('#enhToggle').addEventListener('click', () => {
  const on = $('#enhToggle').getAttribute('aria-pressed') !== 'true';
  $('#enhToggle').setAttribute('aria-pressed', String(on));
  $('#enhToggle').textContent = on ? 'Show enhanced' : 'Show original';
  $('#enhFrame').classList.toggle('show-before', on);
});
$$('#enhSliders input[type=range]').forEach(el => el.addEventListener('input', () => {
  if (!enh.params) return;
  enh.params[el.dataset.enh] = +el.value;
  $('#' + el.id + '_out').textContent = enhFmt(el.dataset.enh, +el.value);
  enhArm(false);
  enhSchedule();
}));
$$('input[name=enhMode]').forEach(el => el.addEventListener('change', () => enhArm(false)));
$('#enhSplit').addEventListener('input', e => enhSplit(+e.target.value));
(() => {
  const f = $('#enhFrame');
  const at = e => { const r = f.getBoundingClientRect(); enhSplit((e.clientX - r.left) / r.width * 100); };
  f.addEventListener('pointerdown', e => { f.setPointerCapture(e.pointerId); at(e); });
  f.addEventListener('pointermove', e => { if (f.hasPointerCapture(e.pointerId)) at(e); });
})();
// Keys pressed in the enhance view stay there (the lightbox behind it reacts to arrows and Delete).
$('#enhanceDlg').addEventListener('keydown', e => e.stopPropagation());
$('#enhanceDlg').addEventListener('cancel', e => { if (enh.saving) e.preventDefault(); });
$('#enhanceDlg').addEventListener('close', () => {
  clearTimeout(enh.armTimer);
  Object.assign(enh, { id: null, src: null, blur: null, out: null, armed: 0 });
  if ($('#lightbox').open) $('#lbEnhBtn').focus();
});
window.addEventListener('resize', () => { if ($('#enhanceDlg').open) enhLayout(); });

const PAGE = 200;
/** Loads the first page (append = false) or the next one, in the chosen order. */
async function doSearch(append = false) {
  if (view.loading) return;
  view.loading = true;
  const q = $('#q').value.trim();
  const sort = view.group === 'folder' ? 'folder' : view.sort;
  const offset = append ? lastResults.length : 0;
  try {
    const data = await api('search', { qs: 'q=' + encodeURIComponent(q) + '&sort=' + sort + '&limit=' + PAGE + '&offset=' + offset });
    view.more = !!data.more;
    renderResults(append ? lastResults.concat(data.results) : data.results);
    if (!append) window.scrollTo(0, 0);
    $('#moreWrap').style.display = view.more ? '' : 'none';
    $('#viewCount').textContent = lastResults.length ? lastResults.length + (view.more ? '+' : '') + (q ? ' matches' : ' photos') : '';
  } catch (e) {
    toast(e.message, 'err');
  } finally {
    view.loading = false;
  }
}
$('#moreBtn').addEventListener('click', () => doSearch(true));
// Load the next page by itself when "Show more" scrolls into view.
new IntersectionObserver(es => { if (es.some(e => e.isIntersecting) && view.more) doSearch(true); }, { rootMargin: '600px' })
  .observe($('#moreWrap'));
$('#sortSel').value = view.sort;
$('#groupSel').value = view.group;
$('#sortSel').addEventListener('change', () => { view.sort = $('#sortSel').value; localStorage.setItem('pf.sort', view.sort); doSearch(); });
$('#groupSel').addEventListener('change', () => { view.group = $('#groupSel').value; localStorage.setItem('pf.group', view.group); doSearch(); });
$('#searchBtn').addEventListener('click', () => doSearch());
$('#q').addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });

$('#scanBtn').addEventListener('click', async () => {
  $('#scanBtn').disabled = true;
  try {
    const r = await api('scan');
    setStats(r.stats);
    const newCount = r.new_files ?? r.new_or_changed ?? 0;
    const parts = [`Found ${r.found} images, ${newCount} new`];
    const meta = [];
    if (r.meta_gps)   meta.push(`${r.meta_gps} GPS`);
    if (r.meta_date)  meta.push(`${r.meta_date} dates`);
    if (r.meta_faces) meta.push(`${r.meta_faces} face-tagged`);
    if (meta.length)  parts.push(`refreshed metadata on ${meta.join(' / ')}`);
    let msg = parts.join('. ') + '.';
    if (r.skipped_dirs.length) msg += ' Skipped (not found): ' + r.skipped_dirs.join(', ');
    toast(msg);
  } catch (e) { toast(e.message, 'err'); }
  $('#scanBtn').disabled = false;
});

let workerPollTimer = null;
let workerWasActive = false;

async function refreshWorkerStatus() {
  try {
    const w = await api('worker_status');
    const pill = $('#pill-worker');
    const setupNeeded = (w.last_log || '').toLowerCase().startsWith('setup-required');
    if (setupNeeded) showSetupBanner(w.last_log.replace(/^setup-required:\s*/i, ''));
    if (w.watchdog && w.watchdog.attempted) {
      const msg = w.watchdog.reason.startsWith('launched')
        ? `Worker auto-relaunched (attempt ${w.watchdog.attempt}/3)`
        : `Watchdog failed: ${w.watchdog.reason}`;
      toast(msg, w.watchdog.reason.startsWith('launched') ? 'ok' : 'err', 4000);
    }
    if (w.alive) {
      pill.classList.remove('err'); pill.classList.add('ok');
      pill.querySelector('.num').textContent = w.active ? 'indexing' : (setupNeeded ? 'setup needed' : 'idle');
      pill.title = `Worker is running and ${w.active ? 'actively indexing' : 'idle'}. Last heartbeat: ${w.seconds_since_heartbeat}s ago. ${w.last_log || ''}`;
    } else {
      pill.classList.remove('ok'); pill.classList.add('err');
      pill.querySelector('.num').textContent = 'offline';
      pill.title = 'Worker is not running. Run install.bat once, or click Index Now to launch it.';
    }
    if (w.active) {
      $('#indexBtn').style.display = 'none';
      $('#stopBtn').style.display = '';
      $('#indexbar').style.display = '';
      const s = await api('stats');
      setStats(s);
      const done = s.indexed + s.errors;
      $('#prog').max = s.total || 1;
      $('#prog').value = done;
      $('#progtxt').textContent = ` ${done}/${s.total} (+${w.last_processed} this session)`;
      workerWasActive = true;
    } else {
      $('#indexBtn').style.display = '';
      $('#stopBtn').style.display = 'none';
      if (workerWasActive) {
        workerWasActive = false;
        const s = await api('stats');
        setStats(s);
        $('#indexbar').style.display = 'none';
        if (w.last_log && w.last_log.indexOf('idle') === 0) {
          toast('Indexing complete');
        } else if (w.last_log) {
          toast(w.last_log);
        }
      }
    }
    return w;
  } catch (e) { /* swallow polling errors */ return null; }
}

async function waitForWorker(maxSeconds = 15) {
  for (let i = 0; i < maxSeconds; i++) {
    const w = await api('worker_status').catch(() => null);
    if (w && w.alive) return w;
    await new Promise(r => setTimeout(r, 1000));
  }
  return null;
}

async function startIndexing() {
  let w = await api('worker_status');
  if (!w.alive) {
    toast('Starting the worker...');
    try {
      const r = await api('worker_launch', { body: {} });
      if (!r.ok) { toast(r.error || 'Could not launch worker. Did you run install.bat?', 'err', 8000); return; }
    } catch (e) {
      toast('Worker not installed. Run install.bat in the photo-finder folder first.', 'err', 8000);
      return;
    }
    w = await waitForWorker(15);
    if (!w) { toast('Worker did not come online in 15s. Try again or run install.bat.', 'err', 6000); return; }
  }
  const ps = await api('provider_status');
  if (!ps.configured) {
    showSetupBanner(ps.message);
    toast(ps.message, 'err', 5000);
    return;
  }
  try {
    await api('worker_start');
    toast('Indexing started');
    await refreshWorkerStatus();
  } catch (e) { toast(e.message, 'err'); }
}

// ---- AI provider setup ----
function showSetupBanner(message) {
  if (message) $('#setupText').textContent = message;
  $('#setupBanner').classList.add('show');
}
async function checkProviderSetup() {
  try {
    const ps = await api('provider_status');
    if (ps.configured) $('#setupBanner').classList.remove('show');
    else showSetupBanner(ps.message);
  } catch (e) { /* page still works without it */ }
}
$('#setupBtn').addEventListener('click', () => $('#settingsBtn').click());

async function stopIndexing() {
  try {
    await api('worker_stop');
    toast('Stop signal sent. Worker will finish current batch.');
    await refreshWorkerStatus();
  } catch (e) { toast(e.message, 'err'); }
}

$('#indexBtn').addEventListener('click', startIndexing);
$('#stopBtn').addEventListener('click', stopIndexing);

workerPollTimer = setInterval(refreshWorkerStatus, 3000);
refreshWorkerStatus();

async function exportLoop() {
  indexing = true;
  $('#exportBtn').style.display = 'none';
  $('#indexBtn').style.display = 'none';
  $('#stopBtn').style.display = '';
  $('#indexbar').style.display = '';
  while (indexing) {
    try {
      const r = await api('export_tick');
      setStats(r.stats);
      const total = r.stats.indexed || 1;
      const done = r.stats.exported + r.stats.export_errors;
      $('#prog').max = total;
      $('#prog').value = done;
      $('#progtxt').textContent = ` embedding ${done}/${total}`;
      if (r.message) { toast(r.message, 'err'); indexing = false; break; }
      if (r.processed === 0 && r.errors === 0) { indexing = false; toast('Metadata embedding complete'); break; }
      if (r.errors > 0 && r.processed === 0) { indexing = false; toast(`${r.errors} write errors. Check Settings → Reset export errors.`, 'err'); break; }
    } catch (e) { toast(e.message, 'err'); indexing = false; break; }
  }
  $('#exportBtn').style.display = '';
  $('#indexBtn').style.display = '';
  $('#stopBtn').style.display = 'none';
}
$('#exportBtn').addEventListener('click', exportLoop);

$('#purgeMissingBtn').addEventListener('click', async () => {
  if (!confirm('Scan the index for rows whose file no longer exists on disk and remove them? (Files on disk are not touched. Just cleans up the search index so broken thumbnails stop appearing.)')) return;
  $('#purgeMissingBtn').disabled = true;
  try {
    const r = await api('purge_missing');
    toast(`Removed ${r.removed} broken entries from ${r.scanned} scanned`);
    if (r.stats) setStats(r.stats);
  } catch (e) { toast(e.message, 'err'); }
  $('#purgeMissingBtn').disabled = false;
});

$('#purgeExcludedBtn').addEventListener('click', async () => {
  if (!confirm('Remove already-indexed photos whose path contains any of the excluded folder names? Files stay on disk; only the search-index entries are removed.')) return;
  $('#purgeExcludedBtn').disabled = true;
  try {
    const r = await api('purge_excluded');
    toast(`Removed ${r.removed} indexed photos from excluded folders`);
    if (r.stats) setStats(r.stats);
  } catch (e) { toast(e.message, 'err'); }
  $('#purgeExcludedBtn').disabled = false;
});

$('#detectExiftoolBtn').addEventListener('click', async () => {
  $('#detectExiftoolBtn').disabled = true;
  try {
    const r = await api('detect_exiftool');
    if (r.found) {
      $('#exiftoolPath').value = r.exiftool_path;
      toast('Found: ' + r.exiftool_path);
    } else {
      toast('Could not auto-detect ExifTool. Paste the full path to exiftool.exe.', 'err', 5000);
    }
  } catch (e) { toast(e.message, 'err'); }
  $('#detectExiftoolBtn').disabled = false;
});

function updateBackendVisibility() {
  const b = $('#backend').value;
  $$('.provider-panel').forEach(p => p.classList.toggle('show', p.dataset.provider === b));
  $('#testResult').textContent = '';
  if (b === 'local') localCheck();
}
$('#backend').addEventListener('change', updateBackendVisibility);

function providerFields() {
  const body = {
    backend: $('#backend').value,
    model: $('#model').value,
    openai_base_url: $('#openaiUrl').value.trim(),
    openai_model: $('#openaiModel').value.trim(),
    local_base_url: $('#localUrl').value.trim(),
    local_model: $('#localModel').value.trim(),
  };
  if ($('#apiKey').value.trim()) body.api_key = $('#apiKey').value.trim();
  if ($('#openaiKey').value.trim()) body.openai_api_key = $('#openaiKey').value.trim();
  return body;
}

async function listModels(backend, urlSel, keySel, listSel, btnSel) {
  $(btnSel).disabled = true;
  try {
    const body = { backend, base_url: $(urlSel).value.trim() };
    if (keySel && $(keySel).value.trim()) body.api_key = $(keySel).value.trim();
    const r = await api('provider_models', { body });
    if (!r.ok) { toast(r.error, 'err', 6000); return; }
    const dl = $(listSel);
    dl.textContent = '';
    r.models.forEach(m => { const o = document.createElement('option'); o.value = m; dl.appendChild(o); });
    toast(r.models.length ? r.models.length + ' models found: pick one in the Model box.' : 'The server lists no models.', r.models.length ? 'ok' : 'err', 4000);
  } catch (e) { toast(e.message, 'err'); }
  finally { $(btnSel).disabled = false; }
}
$('#openaiListBtn').addEventListener('click', () => listModels('openai', '#openaiUrl', '#openaiKey', '#openaiModels', '#openaiListBtn'));
$('#localListBtn').addEventListener('click', () => listModels('local', '#localUrl', null, '#localModels', '#localListBtn'));

// ---- The free option: check this PC, get Ollama, download a model ----
const gb = b => (b / 1024 ** 3).toFixed(b >= 10 * 1024 ** 3 ? 0 : 1) + ' GB';
let pullTimer = null;
async function localCheck(refresh = false) {
  $('#lcRecheck').disabled = true;
  try {
    const r = await api('local_check', { qs: 'base_url=' + encodeURIComponent($('#localUrl').value.trim()) + (refresh ? '&refresh=1' : '') });
    if (!r.ok) { $('#lcVerdict').textContent = r.error; return; }
    const gpus = r.pc.gpus || [];
    const best = gpus.slice().sort((a, b) => b.vram - a.vram)[0];
    $('#lcPc').textContent = (best ? best.name + (best.vram ? ', ' + gb(best.vram) + ' video memory' : '') : 'No graphics card found')
      + (r.pc.ram ? ' · ' + gb(r.pc.ram) + ' memory' : '');
    const o = r.ollama;
    $('#lcGetOllama').style.display = o.running || o.installed ? 'none' : '';
    $('#lcOllama').textContent = o.running ? 'Running' + (o.version ? ' (version ' + o.version + ')' : '')
      : o.installed ? 'Installed but not running: start Ollama from the Start menu, then Check again.'
      : 'Not installed. Ollama is free; install it, then Check again.';
    const pull = $('#lcPull');
    pull.style.display = 'none';
    const vision = (o.models || []).filter(m => m.vision !== false);
    const current = $('#localModel').value.trim();
    if (!o.running) {
      $('#lcModel').textContent = 'Needs Ollama first.';
    } else if (current && vision.some(m => m.name === current)) {
      $('#lcModel').textContent = 'Using ' + current + '.';
    } else if (vision.length) {
      $('#localModel').value = vision[0].name;
      $('#lcModel').textContent = 'Using ' + vision[0].name + ' (found in Ollama). Save to keep it.';
    } else {
      $('#lcModel').textContent = 'No model that reads pictures yet.';
      pull.textContent = 'Download ' + r.recommended.model + ' (' + r.recommended.size + ')';
      pull.dataset.model = r.recommended.model;
      pull.style.display = '';
    }
    const v = $('#lcVerdict');
    v.className = 'lc-verdict ' + (r.tier === 'gpu' ? 'good' : 'slow');
    v.textContent = r.tier === 'gpu'
      ? 'This PC has a graphics card suited to local models: expect a few seconds per photo.'
      : 'No graphics card suited to local models: the model runs on the processor, so expect a minute or more per photo'
        + (r.photos ? ' (for your ' + r.photos.toLocaleString() + ' photos, days of indexing)' : '')
        + '. "Test with a sample picture" below measures this PC. A cloud option is much faster.';
    if (r.pull && !r.pull.done) watchPull();
  } catch (e) { $('#lcVerdict').textContent = e.message; }
  finally { $('#lcRecheck').disabled = false; }
}
function watchPull() {
  if (pullTimer) return;
  const prog = $('#lcProg');
  prog.style.display = '';
  $('#lcPull').disabled = true;
  pullTimer = setInterval(async () => {
    try {
      const p = (await api('local_pull_status')).pull;
      if (!p) return;
      const pct = p.total ? Math.floor(100 * p.completed / p.total) : 0;
      prog.value = pct;
      $('#lcModel').textContent = p.done
        ? (p.error ? 'Download failed: ' + p.error : 'Downloaded ' + p.model + '.')
        : 'Downloading ' + p.model + ': ' + (p.total ? pct + '% (' + gb(p.completed) + ' of ' + gb(p.total) + ')' : p.status || 'starting') + '. You can keep using Photo Finder.';
      if (p.done) {
        clearInterval(pullTimer); pullTimer = null;
        prog.style.display = 'none';
        $('#lcPull').disabled = false;
        if (!p.error) { $('#localModel').value = p.model; localCheck(); }
      }
    } catch (e) { /* keep polling */ }
  }, 1000);
}
$('#lcRecheck').addEventListener('click', () => localCheck(true));
$('#lcGetOllama').addEventListener('click', () => window.open('https://ollama.com/download/windows', '_blank', 'noopener'));
$('#lcPull').addEventListener('click', async () => {
  const r = await api('local_pull', { body: { model: $('#lcPull').dataset.model, base_url: $('#localUrl').value.trim() } });
  if (!r.ok) { toast(r.error, 'err', 6000); return; }
  watchPull();
});

$('#testProviderBtn').addEventListener('click', async () => {
  const btn = $('#testProviderBtn');
  btn.disabled = true;
  $('#testResult').textContent = 'Testing… (a local model can take a minute the first time)';
  try {
    const r = await api('provider_test', { body: providerFields() });
    let est = '';
    const total = parseInt(($('#pill-total .num').textContent || '').replace(/\D/g, ''), 10);
    if (r.ok && total > 0) {   // a real photo is bigger than the test picture: a lower bound
      const h = r.seconds * total / 3600;
      est = ' At this speed your ' + total.toLocaleString() + ' photos take at least '
        + (h < 1 ? Math.max(1, Math.round(h * 60)) + ' minutes.' : h < 48 ? Math.round(h) + ' hours.' : Math.round(h / 24) + ' days.');
    }
    $('#testResult').textContent = r.ok
      ? '✓ ' + r.label + ' answered in ' + r.seconds + ' s: "' + r.description + '".' + est
      : '✗ ' + r.error;
  } catch (e) { $('#testResult').textContent = '✗ ' + e.message; }
  finally { btn.disabled = false; }
});

async function refreshReindexBox() {
  try {
    const r = await api('model_stats');
    const box = $('#reindexBox');
    if (!r.current || !r.other) { box.style.display = 'none'; return; }
    $('#reindexText').textContent = r.other + ' indexed photo(s) were described by a different model, or before Photo Finder recorded the model. '
      + 'Re-describing them sends them to ' + r.current + ' (cloud costs apply). Until each one is redone, its current description stays searchable.';
    $('#reindexBtn').dataset.armed = '';
    $('#reindexBtn').textContent = 'Re-describe them with the current model';
    box.style.display = '';
  } catch (e) { /* optional */ }
}
$('#reindexBtn').addEventListener('click', async () => {
  const btn = $('#reindexBtn');
  if (!btn.dataset.armed) {   // two clicks instead of a confirm() dialog
    btn.dataset.armed = '1';
    btn.textContent = 'Click again to queue them';
    return;
  }
  btn.disabled = true;
  try {
    const r = await api('reindex_other', { body: {} });
    if (!r.ok) { toast(r.error, 'err', 6000); return; }
    toast(r.queued + ' photo(s) queued. Start indexing to re-describe them.', 'ok', 6000);
    if (r.stats) setStats(r.stats);
    await refreshReindexBox();
  } catch (e) { toast(e.message, 'err'); }
  finally { btn.disabled = false; }
});

$('#settingsBtn').addEventListener('click', async () => {
  const s = await api('settings_get');
  $('#photoDirs').value = (s.photo_dirs || []).join('\n');
  $('#excludedDirs').value = (s.excluded_dirs || []).join('\n');
  $('#backend').value = s.backend || 'local';
  const modelSel = $('#model');
  modelSel.textContent = '';
  Object.entries(s.anthropic_models || {}).forEach(([id, label]) => {
    const o = document.createElement('option'); o.value = id; o.textContent = label; modelSel.appendChild(o);
  });
  modelSel.value = s.model;
  $('#apiKey').value = '';
  $('#apiKey').placeholder = s.api_key_set ? '(saved; leave blank to keep)' : 'sk-ant-...';
  $('#openaiUrl').value = s.openai_base_url || '';
  $('#openaiKey').value = '';
  $('#openaiKey').placeholder = s.openai_api_key_set ? '(saved; leave blank to keep)' : '';
  $('#openaiModel').value = s.openai_model || '';
  $('#localUrl').value = s.local_base_url || 'http://localhost:11434/v1';
  $('#localModel').value = s.local_model || '';
  refreshReindexBox();
  $('#maxDim').value = s.max_image_dim;
  $('#batchSize').value = s.batch_size;
  $('#exiftoolPath').value = s.exiftool_path || s.exiftool_path_suggested || '';
  $('#exiftoolPathHint').textContent = s.exiftool_path_suggested && !s.exiftool_path
    ? '✓ Auto-detected at ' + s.exiftool_path_suggested + ' — click Save to confirm.'
    : 'Bundled at photo-finder/bin/exiftool.exe.';
  $('#expDescription').checked = s.export_description;
  $('#expTags').checked = s.export_tags;
  $('#expOcrText').checked = s.export_ocr_text;
  $('#expReplaceDesc').checked = s.export_replace_description;
  $('#expOverwriteOrig').checked = s.export_overwrite_original;
  $('#updateAuto').checked = s.update_check !== false;
  $('#expBatchSize').value = s.export_batch_size;
  updateBackendVisibility();
  $('#settingsDlg').showModal();
});
$('#closeSettings').addEventListener('click', () => $('#settingsDlg').close());

$('#saveSettingsBtn').addEventListener('click', async () => {
  const dirs = $('#photoDirs').value.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
  const excluded = $('#excludedDirs').value.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
  const body = {
    photo_dirs: dirs,
    excluded_dirs: excluded,
    ...providerFields(),
    max_image_dim: parseInt($('#maxDim').value, 10),
    batch_size: parseInt($('#batchSize').value, 10),
  };
  body.exiftool_path = $('#exiftoolPath').value.trim();
  body.export_description = $('#expDescription').checked;
  body.export_tags = $('#expTags').checked;
  body.export_ocr_text = $('#expOcrText').checked;
  body.export_replace_description = $('#expReplaceDesc').checked;
  body.export_overwrite_original = $('#expOverwriteOrig').checked;
  body.update_check = $('#updateAuto').checked;
  body.export_batch_size = parseInt($('#expBatchSize').value, 10);
  try {
    await api('settings_save', { body });
    toast('Settings saved');
    $('#settingsDlg').close();
    checkProviderSetup();
  } catch (e) { toast(e.message, 'err'); }
});

$('#resetErrorsBtn').addEventListener('click', async () => {
  if (!confirm('Re-queue all errored images for indexing AND export?')) return;
  const r1 = await api('reset_errors');
  const r2 = await api('export_reset_errors');
  setStats(r2.stats);
  toast(`Reset ${r1.reset} index errors and ${r2.reset} export errors`);
});

$('#clearAllBtn').addEventListener('click', async () => {
  if (!confirm('Delete entire index? This does NOT delete photos, only the cached descriptions.')) return;
  const r = await api('clear_all');
  setStats(r.stats);
  renderResults([]);
  toast('Index cleared');
});

// ---- Items dialog (errors + pending) ----
let errState = { tab: 'index', showAck: false, index: [], export: [], pending: [] };

async function openErrorsDlg(tab) {
  if (tab) errState.tab = tab;
  await loadErrors();
  $('#errorsDlg').showModal();
}

async function loadErrors() {
  try {
    const r = await api('errors_list');
    errState.index = r.index_errors || [];
    errState.export = r.export_errors || [];
    errState.pending = r.pending || [];
    setStats(r.stats);
    renderErrors();
  } catch (e) { toast(e.message, 'err'); }
}

function renderErrors() {
  $$('#errorsDlg [data-err-tab]').forEach(b => {
    b.classList.toggle('tab-active', b.dataset.errTab === errState.tab);
  });
  const idxOpen = errState.index.filter(e => !e.acknowledged).length;
  const expOpen = errState.export.filter(e => !e.acknowledged).length;
  $('#errIndexCount').textContent = idxOpen;
  $('#errExportCount').textContent = expOpen;
  $('#errPendingCount').textContent = errState.pending.length;

  const isPending = errState.tab === 'pending';
  const isExport  = errState.tab === 'export';
  const title = isPending ? 'Pending' : (isExport ? 'Embed errors' : 'Indexing errors');
  $('#errorsDlgTitle').textContent = title;

  // Show/hide the "show acknowledged" toggle (not meaningful for pending)
  const ackLabel = $('#errShowAck').parentElement;
  ackLabel.style.display = isPending ? 'none' : '';

  const list = isPending ? errState.pending
             : isExport  ? errState.export
             :             errState.index;
  const visible = (isPending || errState.showAck) ? list : list.filter(e => !e.acknowledged);

  if (isPending) {
    $('#errSummaryText').textContent =
      `${visible.length} photo${visible.length === 1 ? '' : 's'} waiting to be indexed (oldest first)`;
  } else {
    const open = list.filter(e => !e.acknowledged).length;
    const ack  = list.length - open;
    const label = isExport ? 'embed errors' : 'indexing errors';
    $('#errSummaryText').textContent =
      `${visible.length} shown · ${open} open · ${ack} acknowledged ${label}`;
  }

  // Bulk action buttons — relabel/disable based on tab
  $('#retryAllErrorsBtn').style.display = isPending ? 'none' : '';
  $('#ackAllErrorsBtn').style.display   = isPending ? 'none' : '';

  const container = $('#errList');
  container.innerHTML = '';
  if (!visible.length) {
    const empty = isPending
      ? 'No photos waiting. Run Scan or Start indexing to queue more.'
      : `No ${errState.showAck ? '' : 'open '}${isExport ? 'embed errors' : 'indexing errors'}. 🎉`;
    container.innerHTML = `<div class="err-empty">${empty}</div>`;
    return;
  }
  const frag = document.createDocumentFragment();
  visible.forEach(e => {
    const div = document.createElement('div');
    div.className = 'err-item' + (e.acknowledged ? ' acknowledged' : '');
    let infoLine;
    if (isPending) {
      const sizeKb = e.size ? Math.round(e.size / 1024) + ' KB' : '';
      const manual = +e.description_manual ? '<span class="err-ack-badge">manual — worker skips</span>' : '';
      infoLine = `<div class="err-msg" style="color:var(--mute)">${sizeKb}${manual}</div>`;
    } else {
      const errText = isExport ? (e.export_error || '') : (e.error || '');
      infoLine = `<div class="err-msg">${escapeHtml(errText) || '(no error message)'}</div>`;
    }
    const ackBadge = (!isPending && e.acknowledged) ? '<span class="err-ack-badge">acknowledged</span>' : '';
    const actions = isPending
      ? `<button type="button" data-act="edit">Edit</button>
         <button type="button" data-act="delete" class="danger">Delete</button>`
      : `<button type="button" data-act="retry">Retry</button>
         <button type="button" data-act="ack">${e.acknowledged ? 'Un-ack' : 'Acknowledge'}</button>
         <button type="button" data-act="edit">Edit</button>
         <button type="button" data-act="delete" class="danger">Delete</button>`;
    div.innerHTML = `
      <img loading="lazy" src="thumb.php?id=${e.id}" alt="" title="Open">
      <div class="err-info">
        <div class="err-path">${escapeHtml(e.path)}${ackBadge}</div>
        ${infoLine}
      </div>
      <div class="err-actions">${actions}</div>`;
    div.querySelector('img').addEventListener('click', () => openErrorInLightbox(e));
    div.querySelectorAll('button[data-act]').forEach(b => {
      b.addEventListener('click', () => handleErrorAction(e, b.dataset.act));
    });
    frag.appendChild(div);
  });
  container.appendChild(frag);
}

function openErrorInLightbox(e) {
  // Stage this one row so lightbox + edit work normally.
  lastResults = [{
    id: e.id, path: e.path,
    description: '', tags: '', faces: '',
  }];
  openLightbox(0);
}

async function handleErrorAction(e, act) {
  const type = errState.tab === 'export' ? 'export' : 'index';
  try {
    if (act === 'retry') {
      await api('retry_error', { body: { id: e.id, type } });
      toast(type === 'export' ? 'Queued for re-embed' : 'Queued for re-index');
    } else if (act === 'ack') {
      if (e.acknowledged) {
        // Un-acknowledge: re-set status by calling retry semantics minus the queue-back
        // Simplest: just flip back via a direct flag — we don't have a dedicated API, so
        // reuse retry for index errors (it clears + requeues). For export, retry also clears.
        // To purely un-ack without retry, hit acknowledge with type but the API only sets to 1.
        // Cheapest path: call retry_error which clears the ack flag too.
        await api('retry_error', { body: { id: e.id, type } });
        toast('Un-acknowledged and queued for retry');
      } else {
        await api('acknowledge_error', { body: { id: e.id, type } });
        toast('Acknowledged');
      }
    } else if (act === 'edit') {
      openErrorInLightbox(e);
      setTimeout(openEditor, 100);
      return;
    } else if (act === 'delete') {
      if (!confirm('Remove this entry from the index? The photo file on disk is NOT deleted.')) return;
      await api('delete_image', { body: { id: e.id } });
      toast('Removed from index');
    }
    await loadErrors();
  } catch (err) { toast(err.message, 'err'); }
}

$('#pill-errors').addEventListener('click', () => openErrorsDlg('index'));
$('#pill-pending').addEventListener('click', () => openErrorsDlg('pending'));
$('#pill-exported').addEventListener('click', () => openErrorsDlg('export'));
$('#closeErrors').addEventListener('click', () => $('#errorsDlg').close());
$('#closeErrors2').addEventListener('click', () => $('#errorsDlg').close());
$$('#errorsDlg [data-err-tab]').forEach(b => {
  b.addEventListener('click', () => { errState.tab = b.dataset.errTab; renderErrors(); });
});
$('#errShowAck').addEventListener('change', e => { errState.showAck = e.target.checked; renderErrors(); });

$('#retryAllErrorsBtn').addEventListener('click', async () => {
  const list = errState.tab === 'export' ? errState.export : errState.index;
  const visible = errState.showAck ? list : list.filter(e => !e.acknowledged);
  if (!visible.length) return;
  if (!confirm(`Retry all ${visible.length} visible ${errState.tab === 'export' ? 'embed' : 'index'} errors?`)) return;
  const type = errState.tab === 'export' ? 'export' : 'index';
  $('#retryAllErrorsBtn').disabled = true;
  try {
    for (const e of visible) {
      await api('retry_error', { body: { id: e.id, type } });
    }
    toast(`Queued ${visible.length} for retry`);
    await loadErrors();
  } catch (err) { toast(err.message, 'err'); }
  $('#retryAllErrorsBtn').disabled = false;
});

$('#ackAllErrorsBtn').addEventListener('click', async () => {
  const list = errState.tab === 'export' ? errState.export : errState.index;
  const visible = list.filter(e => !e.acknowledged);
  if (!visible.length) { toast('Nothing to acknowledge'); return; }
  if (!confirm(`Acknowledge all ${visible.length} open ${errState.tab === 'export' ? 'embed' : 'index'} errors?`)) return;
  const type = errState.tab === 'export' ? 'export' : 'index';
  try {
    const r = await api('acknowledge_all_errors', { body: { type } });
    toast(`Acknowledged ${r.acknowledged}`);
    await loadErrors();
  } catch (err) { toast(err.message, 'err'); }
});

// ---- Live worker log panel ----
let logTimer = null;
let logLastSig = '';
function logColorize(line) {
  const esc = escapeHtml(line);
  if (/✓/.test(line))               return `<span class="ok">${esc}</span>`;
  if (/✗|FATAL|FAILED|error/i.test(line)) return `<span class="err">${esc}</span>`;
  if (/^\[[^\]]+\] (→|←|starting|idle|Worker|batch|No pending)/.test(line))
                                    return `<span class="mute">${esc}</span>`;
  return esc;
}
async function refreshLog() {
  try {
    const r = await api('worker_log', { qs: 'lines=300' });
    const lines = r.lines || [];
    const sig = lines.length + ':' + (lines[lines.length - 1] || '');
    if (sig === logLastSig) return;
    logLastSig = sig;
    const out = $('#logOutput');
    out.innerHTML = lines.map(logColorize).join('\n') || '(no log entries yet)';
    if ($('#logAutoScroll').checked) out.scrollTop = out.scrollHeight;
  } catch (e) { /* ignore polling errors */ }
}
function openLog() {
  $('#logPanel').classList.add('open');
  refreshLog();
  if ($('#logLive').checked && !logTimer) logTimer = setInterval(refreshLog, 1500);
}
function closeLog() {
  $('#logPanel').classList.remove('open');
  if (logTimer) { clearInterval(logTimer); logTimer = null; }
}
$('#logBtn').addEventListener('click', () => {
  $('#logPanel').classList.contains('open') ? closeLog() : openLog();
});
$('#logCloseBtn').addEventListener('click', closeLog);
$('#logRefreshBtn').addEventListener('click', refreshLog);
$('#logLive').addEventListener('change', e => {
  if (e.target.checked) {
    if (!logTimer) logTimer = setInterval(refreshLog, 1500);
  } else if (logTimer) {
    clearInterval(logTimer); logTimer = null;
  }
});

// ---- Updates: checked each time the page opens (the server caches it for an hour) ----
function setUpdateStatus(u) {
  const el = $('#updateStatus');
  if (!el || !u) return;
  let s = 'Version ' + u.current + '. ';
  if (u.disabled) s += 'Automatic update checks are off.';
  else if (u.error) s += 'Could not check for updates: ' + u.error;
  else if (u.available) s += 'Version ' + u.latest + ' is available.';
  else s += 'Up to date.';
  if (u.checked_at) s += ' (checked ' + new Date(u.checked_at * 1000).toLocaleString() + ')';
  el.textContent = s;
}
function showUpdateBanner(u) {
  const b = $('#updateBanner');
  if (!u || !u.available || sessionStorage.getItem('pf-update-dismissed') === u.latest) {
    b.classList.remove('show');
    return;
  }
  const text = $('#updateText');
  text.textContent = '';
  const strong = document.createElement('strong');
  strong.textContent = 'Photo Finder ' + u.latest + ' is available.';
  text.append(strong, ' You have ' + u.current + '.');
  if (u.notes) text.append(' ' + u.notes);
  if (!u.installed) text.append(' This copy runs from source: update it from the repository.');
  $('#updateBtn').style.display = u.installed ? '' : 'none';
  $('#updateBtn').disabled = false;
  $('#updateBtn').dataset.version = u.latest;
  b.classList.add('show');
}
async function checkUpdates(force) {
  try {
    const u = await api('update_check', force ? { qs: 'force=1' } : {});
    showUpdateBanner(u);
    setUpdateStatus(u);
    return u;
  } catch (e) {
    setUpdateStatus({ current: <?= json_encode(PF_VERSION) ?>, error: e.message });
  }
}
async function waitForVersion(target) {
  const until = Date.now() + 5 * 60 * 1000;
  while (Date.now() < until) {
    await new Promise(r => setTimeout(r, 2500));
    try {
      const r = await fetch('api.php?action=version', { cache: 'no-store' });
      if (r.ok && (await r.json()).version === target) { location.reload(); return; }
    } catch (e) { /* server is restarting */ }
  }
  $('#updateText').textContent = 'The update is taking longer than expected. Close this tab and open Photo Finder from the Start menu.';
}
$('#updateBtn').addEventListener('click', async () => {
  const btn = $('#updateBtn');
  const target = btn.dataset.version;
  btn.disabled = true;
  $('#updateText').textContent = 'Downloading and verifying Photo Finder ' + target + '… Windows will ask for permission to install it.';
  try {
    await api('update_install', { body: {} });
  } catch (e) {
    $('#updateText').textContent = 'Update failed: ' + e.message;
    btn.disabled = false;
    return;
  }
  btn.style.display = 'none';
  $('#updateText').textContent = 'Installing Photo Finder ' + target + '. It restarts by itself and this page reloads when it is back.';
  waitForVersion(target);
});
$('#updateDismiss').addEventListener('click', () => {
  sessionStorage.setItem('pf-update-dismissed', $('#updateBtn').dataset.version || '');
  $('#updateBanner').classList.remove('show');
});
$('#checkUpdatesBtn').addEventListener('click', async () => {
  $('#updateStatus').textContent = 'Checking…';
  sessionStorage.removeItem('pf-update-dismissed');
  await checkUpdates(true);
});

refreshStats().then(() => doSearch());
checkUpdates(false);
checkProviderSetup();
</script>
</body>
</html>
