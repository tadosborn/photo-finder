<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/version.php';
require_once __DIR__ . '/lib/Deblur.php';   // Deblur::ENLARGE_MAX_SIDE for the photo view's Enlarge button
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
  dialog.lightbox .lb-del-btn { position: absolute; top: 16px; right: 160px; background: rgba(0,0,0,0.75); color:#fff; border:none; border-radius: 20px; padding: 8px 14px; cursor: pointer; font-size: 13px; }
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
  dialog.settings { background: var(--panel); color: var(--text); border: 1px solid var(--line); border-radius: 12px; padding: 0; max-width: 780px; width: 94%; }
  /* Settings: tabs, each sized to fit without scrolling; the body keeps one height so the
     dialog does not jump between tabs (it only scrolls in a window too small for it). */
  dialog.settings[open] { display: flex; flex-direction: column; max-height: calc(100vh - 24px); overflow: hidden; }
  dialog.settings form { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; }
  dialog.settings .dlg-head { padding: 10px 20px; }
  dialog.settings .dlg-foot { padding: 10px 20px; }
  dialog.settings .dlg-body { flex: 0 1 auto; height: 520px; min-height: 0; overflow-y: auto; padding: 12px 20px; }
  .set-tabs { display: flex; gap: 2px; padding: 0 14px; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
  .set-tabs button { background: transparent; border: none; border-bottom: 3px solid transparent; border-radius: 0; padding: 10px 12px; color: var(--mute); font-size: 13px; }
  .set-tabs button:hover { color: var(--text); background: var(--panel2); }
  .set-tabs button[aria-selected="true"] { color: #c7d8ff; background: #1b2a45; border-bottom-color: var(--accent); }
  .set-tabs button:focus-visible, .set-more summary:focus-visible { outline: 2px solid #9dbcff; outline-offset: 2px; }
  .set-pane .first { margin-top: 0; }
  .set-pane textarea { min-height: 70px; }
  .set-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
  .set-row.nowrap { flex-wrap: nowrap; margin-top: 0; }
  .set-inline-btn { margin-top: 8px; }
  .set-checks { display: flex; flex-direction: column; gap: 6px; margin-top: 14px; }
  .set-checks label, label.set-check { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 13px; color: var(--text); }
  input.narrow { width: 140px; }
  .set-more { margin-top: 10px; }
  .set-more summary { cursor: pointer; color: #9dbcff; font-size: 12px; padding: 2px 0; }
  .set-more[open] summary { margin-bottom: 6px; }
  .set-danger { margin-top: 22px; padding-top: 14px; border-top: 1px solid var(--line); }
  dialog.settings .local-check { margin-top: 8px; padding: 8px 12px; }
  dialog.settings .local-check .lc-row { margin: 2px 0; }
  dialog.settings .local-check .lc-last { margin-top: 6px; }
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
  /* Shown as a popover so it sits above any open dialog (Fix blur, Enhance, Clean up). */
  .toast[popover] { inset: auto 20px 20px auto; margin: 0; color: var(--text); overflow: visible; }
  .toast.ok { border-color: var(--ok); }
  .toast.err { border-color: var(--err); }
  dialog.lightbox { background: transparent; border: none; padding: 0; width: 100vw; height: 100vh; max-width: none; max-height: none; margin: 0; top: 0; left: 0; }
  dialog.lightbox[open] { display: flex; align-items: center; justify-content: center; }
  dialog.lightbox::backdrop { background: rgba(0,0,0,0.95); }
  dialog.lightbox img { max-width: 100vw; max-height: 100vh; object-fit: contain; cursor: zoom-out; }
  dialog.lightbox img.lb-pending { visibility: hidden; }
  dialog.lightbox .lb-status { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #fff; background: rgba(0,0,0,0.75); padding: 10px 16px; border-radius: 8px; font-size: 14px; display: none; pointer-events: none; }
  dialog.lightbox .lb-status.show { display: block; }
  dialog.lightbox .lb-meta { position: absolute; bottom: 0; left: 0; right: 0; color: #fff; padding: 14px 20px 18px; background: linear-gradient(transparent, rgba(0,0,0,0.85) 30%); transition: opacity 0.2s; }
  dialog.lightbox.no-meta .lb-meta { opacity: 0; pointer-events: none; }
  dialog.lightbox .lb-meta .desc { font-size: 15px; line-height: 1.4; max-width: 900px; }
  dialog.lightbox .lb-meta .tags { font-size: 12px; color: #bbb; margin-top: 6px; }
  dialog.lightbox .lb-meta .lb-details { font-size: 12px; color: #ddd; margin-top: 6px; line-height: 1.5; }
  dialog.lightbox .lb-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 44px; height: 80px; background: rgba(0,0,0,0.45); color: #fff; border: none; cursor: pointer; font-size: 28px; opacity: 0.6; transition: opacity 0.15s; user-select: none; }
  dialog.lightbox .lb-nav:hover { opacity: 1; background: rgba(0,0,0,0.7); }
  dialog.lightbox .lb-prev { left: 0; border-radius: 0 6px 6px 0; }
  dialog.lightbox .lb-next { right: 0; border-radius: 6px 0 0 6px; }
  dialog.lightbox .lb-counter { position: absolute; top: 16px; left: 20px; color: #fff; background: rgba(0,0,0,0.75); padding: 4px 10px; border-radius: 12px; font-size: 12px; }
  dialog.lightbox .lb-close { position: absolute; top: 16px; right: 20px; color: #fff; background: rgba(0,0,0,0.75); border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; font-size: 22px; line-height: 1; }
  dialog.lightbox .lb-close:hover { background: rgba(0,0,0,0.8); }
  dialog.lightbox .lb-edit-btn { position: absolute; top: 16px; right: 64px; background: rgba(0,0,0,0.75); color:#fff; border:none; border-radius: 20px; padding: 8px 14px; cursor: pointer; font-size: 13px; }
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
  dialog.lightbox .lb-fix-btn { position: absolute; top: 16px; right: 394px; background: rgba(0,0,0,0.75); color: #fff; border: none; border-radius: 20px; padding: 8px 14px; cursor: pointer; font-size: 13px; }
  dialog.lightbox .lb-fix-btn:hover { background: rgba(0,0,0,0.92); }
  dialog.lightbox .lb-fix-btn:focus-visible { outline: 2px solid #9dbcff; outline-offset: 2px; }
  dialog.lightbox .lb-rot-btn { position: absolute; top: 16px; width: 36px; height: 36px; padding: 0; border-radius: 50%; background: rgba(0,0,0,0.75); color: #fff; border: none; cursor: pointer; font-size: 20px; line-height: 1; }
  dialog.lightbox .lb-rot-btn:hover { background: rgba(0,0,0,0.92); }
  dialog.lightbox .lb-rot-btn:focus-visible { outline: 2px solid #9dbcff; outline-offset: 2px; }
  dialog.lightbox .lb-rot-btn:disabled { opacity: 0.6; cursor: wait; }
  dialog.lightbox .lb-rot-r { right: 478px; }
  dialog.lightbox .lb-rot-l { right: 522px; }
  /* Left of the rotate buttons, so hiding it (photos too large to enlarge) leaves no gap. */
  dialog.lightbox .lb-enl-btn { position: absolute; top: 16px; right: 566px; background: rgba(0,0,0,0.75); color: #fff; border: none; border-radius: 20px; padding: 8px 14px; cursor: pointer; font-size: 13px; }
  dialog.lightbox .lb-enl-btn:hover { background: rgba(0,0,0,0.92); }
  dialog.lightbox .lb-enl-btn:focus-visible { outline: 2px solid #9dbcff; outline-offset: 2px; }
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
  .enh-panel { width: 440px; flex: none; background: var(--panel); border-left: 1px solid var(--line); overflow-y: auto; padding: 0 18px 14px; }
  .enh-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; position: sticky; top: 0; background: var(--panel); padding: 12px 0 6px; z-index: 1; }
  .enh-head h2 { margin: 0; font-size: 17px; }
  .enh-name { font-size: 12px; color: #b3bbc8; word-break: break-all; margin: 0 0 10px; }
  .enh-panel button { border-color: #7c8597; }
  .enh-panel button:focus-visible, .enh-panel input:focus-visible { outline: 2px solid #9dbcff; outline-offset: 2px; }
  .enh-panel button:disabled { opacity: 1; color: #b3bbc8; background: #21262f; border-color: #6b7487; }
  .enh-panel button.primary:disabled { background: #21262f; border-color: #6b7487; color: #b3bbc8; }
  .enh-panel button.danger { background: var(--err-bg); border-color: #f87171; color: #fff; }
  .enh-panel button.danger:hover { background: #a82020; }
  .enh-close { width: 34px; height: 34px; padding: 0; font-size: 20px; line-height: 1; border-radius: 50%; }
  .enh-section { border-top: 1px solid var(--line); padding: 8px 0 2px; }
  .enh-buttons { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
  .enh-row { display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 0 8px; margin: 4px 0 8px; }
  /* The six sliders in two columns, so the whole panel fits a normal window without scrolling. */
  #enhSliders { display: grid; grid-template-columns: 1fr 1fr; column-gap: 20px; }
  #enhSliders .enh-note { grid-column: 1 / -1; margin-top: 0; }
  .enh-row label { margin: 0; font-size: 13px; color: var(--text); }
  .enh-row output { font-variant-numeric: tabular-nums; font-size: 13px; color: var(--text); min-width: 3.2em; text-align: right; }
  .enh-row input[type=range] { grid-column: 1 / -1; width: 100%; margin: 4px 0 0; accent-color: var(--accent); }
  .enh-row .enh-ends { grid-column: 1 / -1; display: flex; justify-content: space-between; font-size: 11px; color: #b3bbc8; }
  .enh-note, .enh-hint { font-size: 12px; color: #b3bbc8; line-height: 1.45; margin: 4px 0 10px; }
  .enh-hint.warn { color: #fbbf72; }
  .enh-save-row { display: flex; gap: 8px; margin-top: 6px; }
  .enh-save-row button { flex: 1; padding: 10px 14px; font-size: 14px; }
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
  .local-check .lc-verdict { color: var(--text); flex: 1; min-width: 240px; }
  .local-check .lc-last { margin-top: 8px; align-items: flex-start; flex-wrap: nowrap; }
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

  /* ---- Clean up (review screen) ----
     WCAG AA on the panels: --text on --panel/--panel2 > 11:1; --mute (#9aa3b2) on them > 5.9:1;
     --ok (#4ade80) on --panel 10:1; --err-text on --panel 6:1; white on --err-bg 5.6:1;
     #c7d8ff on #1b2a45 (selected card, active tab) 10:1. Nothing is drawn over the photos. */
  dialog.cleanup { background: var(--bg); color: var(--text); border: 1px solid var(--line); border-radius: 12px; padding: 0; width: 96vw; max-width: 1500px; height: 92vh; max-height: none; overflow: hidden; }
  dialog.cleanup[open] { display: flex; flex-direction: column; }
  dialog.cleanup::backdrop { background: rgba(0,0,0,0.7); }
  .cleanup button:disabled { opacity: 1; color: var(--mute); background: var(--panel2); border-color: var(--line); }
  .cleanup button:focus-visible, .cleanup input:focus-visible, .cleanup select:focus-visible { outline: 2px solid #9dbcff; outline-offset: 2px; }
  .cu-head { background: var(--panel); border-bottom: 1px solid var(--line); padding: 12px 18px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  .cu-head h2 { margin: 0; font-size: 17px; }
  .cu-status { color: var(--mute); font-size: 13px; flex: 1; min-width: 200px; }
  .cu-status strong { color: var(--text); }
  .cu-close { font-size: 20px; line-height: 1; padding: 4px 12px; }
  .cu-progress { display: flex; align-items: center; gap: 10px; padding: 8px 18px; background: var(--panel); border-bottom: 1px solid var(--line); font-size: 13px; color: var(--text); }
  .cu-progress[hidden] { display: none; }
  .cu-progress progress { width: 240px; height: 8px; }
  .cu-tabs { display: flex; gap: 4px; padding: 0 18px; background: var(--panel); border-bottom: 1px solid var(--line); flex-wrap: wrap; }
  .cu-tabs button { background: transparent; border: none; border-bottom: 3px solid transparent; border-radius: 0; padding: 10px 14px; color: var(--mute); font-size: 14px; }
  .cu-tabs button:hover { color: var(--text); background: var(--panel2); }
  .cu-tabs button[aria-selected="true"] { color: #c7d8ff; background: #1b2a45; border-bottom-color: var(--accent); }
  .cu-count { display: inline-block; min-width: 22px; margin-left: 6px; padding: 0 7px; border-radius: 10px; background: var(--panel2); border: 1px solid var(--line); color: var(--text); font-size: 12px; text-align: center; }
  .cu-controls { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; padding: 10px 18px; background: var(--panel); border-bottom: 1px solid var(--line); font-size: 13px; color: var(--text); }
  .cu-controls[hidden] { display: none; }
  .cu-controls label { display: inline-flex; align-items: center; gap: 8px; margin: 0; font-size: 13px; color: var(--text); }
  .cu-controls input[type=range] { width: 200px; accent-color: var(--accent); }
  .cu-controls select { width: auto; }
  .cu-controls .hint { margin: 0; font-size: 12px; color: var(--mute); }
  .cu-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 10px 18px; border-bottom: 1px solid var(--line); background: var(--bg); }
  .cu-actions .spacer { flex: 1; }
  .cu-selcount { font-weight: 600; margin: 0 6px; }
  .cu-actions button.danger { background: var(--err-bg); border-color: var(--err-bg); color: #fff; }
  .cu-actions button.danger:hover { background: #a91f1f; border-color: #a91f1f; }
  .cu-actions button.danger.armed { background: #8e1717; border-color: #fff; }
  .cu-actions button.danger:disabled { background: var(--panel2); border-color: var(--line); color: var(--mute); }
  .cu-result { margin: 10px 18px 0; padding: 10px 14px; border: 1px solid var(--line); border-left: 3px solid var(--ok); border-radius: 8px; background: var(--panel); font-size: 13px; }
  .cu-result.has-fail { border-left-color: var(--err-text); }
  .cu-result[hidden] { display: none; }
  .cu-result ul { margin: 6px 0 0 18px; padding: 0; max-height: 120px; overflow: auto; }
  .cu-result li { color: var(--err-text); margin: 2px 0; word-break: break-all; }
  .cu-result .close { float: right; padding: 2px 8px; }
  .cu-body { flex: 1; overflow-y: auto; padding: 14px 18px; }
  .cu-explain { color: var(--mute); font-size: 12px; margin: 0 0 12px; }
  .cu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; }
  .cu-group { border: 1px solid var(--line); border-radius: 10px; padding: 10px; margin-bottom: 14px; background: var(--panel); }
  .cu-group-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px; color: var(--text); margin-bottom: 8px; }
  .cu-group-head .muted { color: var(--mute); }
  .cu-group .cu-grid { grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); }
  .cu-card { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; display: flex; flex-direction: column; }
  .cu-group .cu-card { background: var(--panel2); }
  .cu-card.selected { border-color: var(--accent); outline: 2px solid var(--accent); background: #1b2a45; }
  .cu-card.best { border-color: var(--ok); }
  .cu-thumb { display: block; padding: 0; border: none; border-radius: 0; background: #0a0c10; aspect-ratio: 4 / 3; overflow: hidden; cursor: zoom-in; width: 100%; }
  .cu-thumb:hover { background: #0a0c10; }
  .cu-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .cu-meta { padding: 8px 10px 10px; font-size: 12px; color: var(--mute); display: flex; flex-direction: column; gap: 5px; }
  .cu-pick { display: flex; align-items: center; gap: 8px; color: var(--text); font-size: 13px; margin: 0; cursor: pointer; }
  .cu-pick input { width: 18px; height: 18px; accent-color: var(--accent); margin: 0; }
  .cu-chips { display: flex; flex-wrap: wrap; gap: 4px; }
  .cu-chip { display: inline-block; padding: 1px 7px; border-radius: 9px; border: 1px solid var(--line); background: var(--bg); color: var(--text); font-size: 11px; }
  .cu-chip.ai { border-color: #6b5bd6; color: #d4ccff; }
  .cu-chip.best { border-color: var(--ok); color: var(--ok); font-weight: 600; }
  .cu-chip.kept { border-color: var(--warn); color: #fcd34d; }
  .cu-fix { align-self: flex-start; font-size: 12px; padding: 4px 10px; }
  /* Fix blur */
  dialog.deblur { width: min(1100px, 96vw); height: min(840px, 94vh); max-width: none; max-height: none; padding: 0; border: 1px solid var(--line); border-radius: 12px; background: var(--panel); color: var(--text); overflow: hidden; }
  dialog.deblur[open] { display: flex; flex-direction: column; }
  dialog.deblur::backdrop { background: rgba(0,0,0,0.75); }
  .db-head { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-bottom: 1px solid var(--line); }
  .db-head h2 { margin: 0; font-size: 16px; }
  .db-name { flex: 1; min-width: 0; color: var(--mute); font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .db-close { background: transparent; border: none; color: var(--text); font-size: 22px; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; padding: 0; }
  .db-close:hover { background: var(--panel2); }
  .db-viewbar { display: inline-flex; border: 1px solid var(--line); border-radius: 8px; overflow: hidden; flex: none; }
  .db-viewbar button { border: none; border-radius: 0; background: transparent; padding: 5px 10px; font-size: 12px; }
  .db-viewbar button[aria-pressed="true"] { background: var(--primary-bg); color: #fff; }
  .db-viewbar[hidden] { display: none; }
  .db-grid { flex: 1; min-height: 0; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; padding: 6px; background: #0a0c10; }
  .db-grid[hidden] { display: none; }
  .db-pane { position: relative; margin: 0; min-width: 0; min-height: 0; overflow: hidden; background: #000; border: 2px solid transparent; border-radius: 6px; }
  .db-pane[hidden] { display: none; }
  .db-pane.picked { border-color: var(--accent); }
  .db-pane img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; transform-origin: 50% 50%; }
  .db-pane figcaption { position: absolute; top: 8px; left: 8px; right: 8px; display: flex; align-items: center; gap: 8px; pointer-events: none; }
  .db-pane figcaption span { background: rgba(0,0,0,0.75); color: #fff; font-size: 12px; padding: 3px 9px; border-radius: 10px; }
  .db-pick { pointer-events: auto; margin-left: auto; font-size: 12px; padding: 4px 10px; background: rgba(0,0,0,0.75); color: #fff; border: 1px solid #fff; border-radius: 12px; }
  .db-pick[aria-pressed="true"] { background: var(--primary-bg); border-color: var(--primary-bg); }
  .db-pick:disabled { opacity: 0.5; }
  .db-pane-note { position: absolute; left: 10px; right: 10px; bottom: 10px; background: rgba(0,0,0,0.8); color: #fff; font-size: 13px; padding: 8px 10px; border-radius: 6px; text-align: center; }
  .db-pane-note:empty { display: none; }
  .db-compare { position: relative; flex: 1; min-height: 0; background: #0a0c10; overflow: hidden; user-select: none; }
  .db-compare[hidden] { display: none; }
  .db-compare img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; }
  .db-after { clip-path: inset(0 0 0 50%); }
  .db-line { position: absolute; top: 0; bottom: 0; left: 50%; width: 2px; margin-left: -1px; background: #fff; box-shadow: 0 0 4px rgba(0,0,0,0.9); pointer-events: none; }
  .db-tag { position: absolute; top: 10px; background: rgba(0,0,0,0.75); color: #fff; font-size: 12px; padding: 3px 9px; border-radius: 10px; }
  .db-tag.l { left: 10px; }
  .db-tag.r { right: 10px; }
  .db-slider { position: absolute; left: 20px; right: 20px; bottom: 12px; width: calc(100% - 40px); margin: 0; accent-color: #fff; }
  .db-bar { padding: 10px 14px 12px; border-top: 1px solid var(--line); display: flex; flex-direction: column; gap: 8px; }
  .db-status { font-size: 13px; min-height: 18px; }
  .db-status.err { color: var(--err-text); }
  .db-status.done { color: var(--ok); font-weight: 600; font-size: 14px; }
  .db-bar progress { width: 100%; height: 8px; }
  .db-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .db-row .spacer { flex: 1; }
  .db-which { display: inline-flex; border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
  .db-which[hidden] { display: none; }
  .db-which button { border: none; border-radius: 0; background: transparent; }
  .db-which button[aria-pressed="true"] { background: var(--primary-bg); color: #fff; }
  .db-row button[hidden] { display: none; }
  /* "Replace the original", everywhere: the first click turns the button red ("Confirm: replace
     the original"), the second, within 8 seconds, replaces. (White on --err-bg is 5.6:1.) */
  button.confirm-armed, .enh-panel button.confirm-armed { background: var(--err-bg); border-color: #f87171; color: #fff; }
  button.confirm-armed:hover, .enh-panel button.confirm-armed:hover { background: #a82020; }
  /* Queue, and Clean up's batch window */
  dialog.queue, dialog.batch { padding: 0; border: 1px solid var(--line); border-radius: 12px; background: var(--panel); color: var(--text); }
  dialog.queue { width: min(900px, 96vw); max-height: 90vh; }
  dialog.queue[open] { display: flex; flex-direction: column; }
  dialog.queue::backdrop, dialog.batch::backdrop { background: rgba(0,0,0,0.6); }
  .q-head { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-bottom: 1px solid var(--line); }
  .q-head h2 { margin: 0; font-size: 16px; }
  .q-sub { flex: 1; color: var(--mute); font-size: 13px; }
  .q-body { overflow-y: auto; padding: 4px 16px 16px; }
  .q-sec h3 { font-size: 14px; margin: 14px 0 6px; }
  .q-sec[hidden] { display: none; }
  .q-line { font-size: 13px; margin: 4px 0; }
  .q-mute { color: var(--mute); }
  .q-sec progress { width: 100%; height: 8px; }
  .q-sec progress[hidden] { display: none; }
  .q-actions { margin: 6px 0 8px; }
  .q-actions button[hidden] { display: none; }
  .q-list { display: flex; flex-direction: column; gap: 3px; }
  .q-row { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 3fr) auto; gap: 10px; align-items: center; padding: 5px 8px; border-radius: 6px; background: var(--panel2); font-size: 13px; }
  .q-row .q-name, .q-row .q-what { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .q-row .q-what { color: var(--mute); }
  .q-row.err .q-what { color: var(--err-text); }
  .q-row button { font-size: 12px; padding: 3px 10px; }
  .q-badge { display: inline-block; min-width: 18px; margin-left: 6px; padding: 0 5px; border-radius: 9px; background: var(--primary-bg); color: #fff; font-size: 11px; line-height: 18px; text-align: center; }
  .q-badge[hidden] { display: none; }
  dialog.batch { width: min(560px, 94vw); padding: 16px 18px; }
  dialog.batch h2 { margin: 0 0 8px; font-size: 16px; }
  dialog.batch p { font-size: 13px; margin: 8px 0; }
  .b-opt { display: flex; gap: 8px; align-items: baseline; margin: 8px 0; font-size: 14px; }
  .b-all { padding-bottom: 8px; border-bottom: 1px solid var(--line); }
  /* Batch review: every photo of a batch with its versions */
  dialog.review { width: min(1240px, 97vw); height: min(880px, 95vh); max-height: none; }
  dialog.review .q-body { flex: 1; min-height: 0; }
  .rv-row { padding: 10px 0; border-bottom: 1px solid var(--line); }
  .rv-head { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; font-size: 13px; }
  .rv-head .rv-name { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .rv-head button { font-size: 12px; padding: 3px 10px; }
  .rv-done { font-size: 13px; color: var(--ok); font-weight: 600; }
  .rv-thumbs { display: flex; gap: 8px; overflow-x: auto; }
  .rv-pick { flex: none; display: flex; flex-direction: column; align-items: center; gap: 4px; width: 190px; padding: 6px; border: 2px solid var(--line); border-radius: 8px; background: var(--panel2); color: var(--text); cursor: pointer; }
  .rv-pick img { width: 176px; height: 132px; object-fit: contain; background: #000; border-radius: 4px; }
  .rv-pick span { font-size: 12px; line-height: 1.3; text-align: center; }
  .rv-pick[aria-pressed="true"] { border-color: var(--accent); background: #24324d; }
  .rv-pick:disabled { cursor: default; }
  .rv-foot { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 14px; border-top: 1px solid var(--line); font-size: 13px; }
  .rv-foot .spacer { flex: 1; }
  .b-status { min-height: 18px; font-size: 13px; color: var(--err-text); }
  .b-row { display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px; }
  .db-hint { font-size: 12px; color: var(--mute); }
  .cu-name { color: var(--text); word-break: break-all; }
  .cu-path { word-break: break-all; }
  .cu-empty { text-align: center; color: var(--mute); padding: 50px 20px; font-size: 14px; }
  .cu-pager { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 8px 18px; border-top: 1px solid var(--line); background: var(--panel); font-size: 13px; }
  .cu-pager[hidden] { display: none; }
  .cu-foot { display: flex; align-items: center; gap: 10px; padding: 8px 18px; border-top: 1px solid var(--line); background: var(--panel); font-size: 12px; color: var(--mute); }
  .cu-foot .spacer { flex: 1; }
  dialog.lightbox.from-cleanup .lb-del-btn { display: none; }
  /* ---- end Clean up ---- */
  /* ---- Find photos on this PC (consolidate). WCAG AA: --text on --panel/--panel2 > 11:1,
     --mute > 6:1, --cs-amber #f5b54a on #21262f 8.5:1 and on #181c24 9.3:1, --ok on #181c24 9.8:1.
     Disabled buttons in this dialog keep full opacity (--mute on --panel2, 6:1). ---- */
  :root { --cs-amber: #f5b54a; }
  dialog.consol { background: var(--panel); color: var(--text); border: 1px solid var(--line); border-radius: 12px; padding: 0; width: 96%; max-width: 1100px; max-height: 92vh; }
  dialog.consol[open] { display: flex; flex-direction: column; }
  dialog.consol [hidden] { display: none !important; }   /* .dlg-foot's display:flex would beat [hidden] */
  dialog.consol::backdrop { background: rgba(0,0,0,0.6); }
  dialog.consol .dlg-body { overflow-y: auto; flex: 1 1 auto; min-height: 0; }
  dialog.consol button:disabled { opacity: 1; color: var(--mute); background: var(--panel2); border-color: var(--line); }
  dialog.consol h3 { font-size: 14px; margin: 0 0 8px; }
  .cs-lead { margin: 0 0 10px; }
  .cs-facts { margin: 0 0 14px 18px; padding: 0; color: var(--mute); font-size: 13px; }
  .cs-facts li { margin: 3px 0; }
  .cs-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text); margin: 12px 0; }
  .cs-check input { width: 16px; height: 16px; accent-color: var(--accent); }
  .cs-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
  .cs-info { color: var(--mute); font-size: 13px; margin-top: 10px; }
  .cs-runs { margin-top: 22px; }
  .cs-runs:not(:empty) { border-top: 1px solid var(--line); padding-top: 14px; }
  .cs-run { display: flex; gap: 8px; align-items: center; padding: 6px 0; font-size: 13px; border-bottom: 1px solid var(--line); }
  .cs-run-text { flex: 1; min-width: 0; word-break: break-word; }
  .cs-run button { padding: 4px 10px; font-size: 12px; }
  .cs-bar { height: 8px; background: var(--panel2); border-radius: 4px; overflow: hidden; margin: 10px 0; }
  .cs-bar span { display: block; height: 100%; width: 0; background: var(--accent); transition: width 0.2s; }
  .cs-sub { color: var(--mute); font-size: 12px; word-break: break-all; margin-bottom: 12px; }
  .cs-toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 4px 0 12px; font-size: 13px; }
  .cs-toolbar label { margin: 0; font-size: 13px; color: var(--text); }
  .cs-toolbar select { width: auto; }
  .cg { border: 1px solid var(--line); border-radius: 8px; background: var(--panel2); padding: 8px 10px; margin-bottom: 8px; }
  .cg-row { display: flex; gap: 10px; align-items: center; }
  .cg-row input { width: 18px; height: 18px; accent-color: var(--accent); flex: none; margin: 0; }
  .cg-name { flex: 1; min-width: 0; margin: 0; font-size: 13px; color: var(--text); word-break: break-word; cursor: pointer; }
  .cg-root { font-weight: 600; }
  .cg-count { color: var(--mute); font-size: 12px; white-space: nowrap; }
  .cg-row button { padding: 4px 10px; font-size: 12px; flex: none; }
  .cg-meta { display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center; margin: 6px 0 0 28px; font-size: 12px; }
  .chip { border: 1px solid var(--line); border-radius: 999px; padding: 1px 8px; color: var(--text); background: var(--panel); }
  .cg-reason { color: var(--cs-amber); }
  .cg-note { color: var(--mute); }
  .cg-thumbs { display: flex; gap: 6px; margin: 8px 0 0 28px; }
  .cg-thumbs img { width: 56px; height: 56px; object-fit: cover; border-radius: 4px; background: #0a0c10; }
  .cg-files { margin: 8px 0 0 28px; border-top: 1px solid var(--line); padding-top: 6px; max-height: 320px; overflow-y: auto; }
  .cf { display: flex; flex-wrap: wrap; gap: 4px 8px; align-items: center; margin: 0; padding: 3px 0; font-size: 12px; color: var(--text); cursor: pointer; }
  .cf input { width: 16px; height: 16px; accent-color: var(--accent); flex: none; margin: 0; }
  .cf-name { min-width: 0; word-break: break-all; }
  .cf-info { color: var(--mute); }
  .badge { border: 1px solid var(--line); border-radius: 4px; padding: 0 5px; font-size: 11px; color: var(--mute); white-space: nowrap; }
  .badge.warn { color: var(--cs-amber); border-color: #6b5320; }
  .cs-skipped { margin-top: 14px; font-size: 13px; }
  .cs-skipped summary { cursor: pointer; color: var(--text); padding: 4px 0; }
  .cs-skip-reason { margin: 10px 0 2px; font-weight: 600; }
  .cs-skip-path { color: var(--mute); font-size: 12px; word-break: break-all; margin-left: 12px; }
  .cs-foot { flex-wrap: wrap; align-items: center; }
  .cs-foot label { margin: 0; font-size: 13px; color: var(--text); }
  .cs-foot input[type=text] { flex: 1 1 320px; width: auto; }
  .cs-foot .hint { flex-basis: 100%; margin: 0; }
  .cs-list { margin: 6px 0 12px 18px; padding: 0; font-size: 12px; }
  .cs-list li { margin: 3px 0; word-break: break-all; }
  .cs-ok { color: var(--ok); }
  .cs-err { color: var(--err-text); }
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
  <button id="consolidateBtn" type="button" title="Search this PC for photos that are not in your library yet, and gather them into it">Find photos on this PC</button>
  <button id="stopBtn" style="display:none">Stop</button>
  <button id="cleanupBtn" title="Find blurry, badly exposed and duplicate photos and move them to the Recycle Bin">Clean up</button>
  <button id="queueBtn" type="button" title="What Fix blur, Enlarge and batches are doing">Queue<span class="q-badge" id="queueBadge" hidden></span></button>
  <button id="logBtn" title="Show live worker log">Log</button>
  <button id="settingsBtn">Settings</button>
</header>

<div id="selbar" class="selbar">
  <span class="sel-count" id="selCount">0 selected</span>
  <button type="button" id="selAllBtn">Select all</button>
  <button type="button" id="selClearBtn">Clear</button>
  <button type="button" id="selFixBtn" title="Fix the selected photos one after another on this PC; each gets a copy next to it">Fix selected…</button>
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

<dialog id="settingsDlg" class="settings" aria-labelledby="settingsTitle">
  <form id="settingsForm" method="dialog">
    <div class="dlg-head">
      <h2 id="settingsTitle">Settings</h2>
      <button type="button" id="closeSettings" aria-label="Close without saving">×</button>
    </div>
    <div class="set-tabs" role="tablist" aria-label="Settings sections">
      <button type="button" role="tab" id="setTab-folders" aria-controls="setPane-folders" aria-selected="true">Photo folders</button>
      <button type="button" role="tab" id="setTab-describe" aria-controls="setPane-describe" aria-selected="false" tabindex="-1">How photos are described</button>
      <button type="button" role="tab" id="setTab-embed" aria-controls="setPane-embed" aria-selected="false" tabindex="-1">Embed metadata</button>
      <button type="button" role="tab" id="setTab-updates" aria-controls="setPane-updates" aria-selected="false" tabindex="-1">Updates</button>
      <button type="button" role="tab" id="setTab-fixblur" aria-controls="setPane-fixblur" aria-selected="false" tabindex="-1">Fix blur</button>
      <button type="button" role="tab" id="setTab-advanced" aria-controls="setPane-advanced" aria-selected="false" tabindex="-1">Advanced</button>
    </div>
    <div class="dlg-body">

      <div class="set-pane" role="tabpanel" id="setPane-folders" aria-labelledby="setTab-folders">
        <label for="photoDirs" class="first">Photo folders (one per line)</label>
        <textarea id="photoDirs" placeholder="C:\Users\YourName\Pictures&#10;D:\Backups\Photos"></textarea>
        <div class="hint">Subfolders are included. Backslashes or forward slashes both work.</div>
        <button type="button" id="consolFromSettings" class="set-inline-btn">Find photos elsewhere on this PC…</button>

        <label for="excludedDirs">Folder names to skip (one per line)</label>
        <textarea id="excludedDirs" placeholder="Screenshots&#10;Thumbnails"></textarea>
        <div class="hint">Folder names, not paths, skipped at any depth when scanning and searching. Default: Screenshots.</div>
        <div class="set-row">
          <button type="button" id="purgeExcludedBtn">Remove indexed photos from these folders</button>
          <button type="button" id="purgeMissingBtn">Remove entries for files no longer on disk</button>
        </div>
      </div>

      <div class="set-pane" role="tabpanel" id="setPane-describe" aria-labelledby="setTab-describe" hidden>
        <div class="hint first">An AI model writes each photo's description and search tags. The free option runs it on this PC, so your photos never leave it; the cloud options send each photo to that service.</div>
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
          <label for="openaiModel">Model (must accept images)</label>
          <div class="set-row nowrap">
            <input id="openaiModel" type="text" list="openaiModels" style="flex:1">
            <button type="button" id="openaiListBtn">List models</button>
          </div>
          <datalist id="openaiModels"></datalist>
          <div class="provider-note">
            <strong>Cost:</strong> billed by that service to your own account; it depends on the service and model.<br>
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
            <div class="lc-row lc-last"><span class="lc-verdict" id="lcVerdict"></span>
              <button type="button" id="lcRecheck">Check again</button></div>
          </div>
          <label for="localModel">Model</label>
          <div class="set-row nowrap">
            <input id="localModel" type="text" list="localModels" style="flex:1">
            <button type="button" id="localListBtn">Find models</button>
          </div>
          <datalist id="localModels"></datalist>
          <details class="set-more">
            <summary>What to expect from a model on this PC</summary>
            <div class="provider-note warn">
              <ul>
                <li><strong>Software:</strong> Ollama (ollama.com) or LM Studio (lmstudio.ai), both free, with a vision model (one that reads pictures).</li>
                <li><strong>Graphics card:</strong> 8 GB or more of video memory is recommended. Without one, the model runs on the processor and can take a minute or more per photo.</li>
                <li><strong>Time:</strong> roughly 3–10 seconds per photo on a good graphics card, so 10,000 photos take about 8–30 hours; without one, several days.</li>
                <li><strong>Disk and memory:</strong> a vision model is typically a 2–10 GB download and needs about that much free memory while it runs.</li>
                <li><strong>Quality:</strong> descriptions are usually less detailed than the cloud options, and reading text in photos is weaker.</li>
                <li><strong>Cost:</strong> nothing per photo, just your PC's electricity while it indexes.</li>
              </ul>
              These figures are rough; they depend on your hardware and the model.
            </div>
          </details>
          <details class="set-more">
            <summary>Local server address</summary>
            <input id="localUrl" type="text" list="localUrlPresets" aria-label="Local server address">
            <datalist id="localUrlPresets">
              <option value="http://localhost:11434/v1">Ollama</option>
              <option value="http://localhost:1234/v1">LM Studio</option>
            </datalist>
            <div class="hint">Only an address on this PC is accepted. Ollama: http://localhost:11434/v1 · LM Studio: http://localhost:1234/v1</div>
          </details>
        </div>

        <div class="set-row">
          <button type="button" id="testProviderBtn">Test with a sample picture</button>
          <span class="hint" style="margin:0">Uses the settings above, saved or not. A cloud test costs a fraction of a cent.</span>
        </div>
        <div class="test-result" id="testResult" role="status"></div>

        <div id="reindexBox" style="display:none; margin-top:12px;">
          <div class="hint" id="reindexText" style="margin:0 0 6px;"></div>
          <button type="button" id="reindexBtn">Re-describe them with the current model</button>
        </div>
      </div>

      <div class="set-pane" role="tabpanel" id="setPane-embed" aria-labelledby="setTab-embed" hidden>
        <div class="hint first">Writes descriptions and tags into the photo files themselves (XMP/IPTC/EXIF), so Windows Explorer, Adobe Bridge, Lightroom, Apple Photos and others can search them. The pictures themselves are not changed.</div>
        <label for="exiftoolPath">ExifTool path</label>
        <div class="set-row nowrap">
          <input id="exiftoolPath" type="text" placeholder="auto-detect or paste path to exiftool.exe" style="flex:1">
          <button type="button" id="detectExiftoolBtn">Auto-detect</button>
        </div>
        <div class="hint" id="exiftoolPathHint">Bundled with Photo Finder.</div>
        <div class="set-checks">
          <label><input id="expDescription" type="checkbox"> Embed description (caption)</label>
          <label><input id="expTags" type="checkbox"> Embed tags (keywords)</label>
          <label><input id="expOcrText" type="checkbox"> Also add text found in the photo as keywords</label>
          <label><input id="expReplaceDesc" type="checkbox"> Replace an existing description (tags are always added)</label>
          <label><input id="expOverwriteOrig" type="checkbox"> Change files in place (untick to keep .jpg_original backups)</label>
        </div>
        <label for="expBatchSize">Photos per ExifTool run</label>
        <input id="expBatchSize" type="number" min="1" max="100" class="narrow">
        <div class="hint">25–50 is efficient. Higher is faster, but a failed run affects more photos.</div>
      </div>

      <div class="set-pane" role="tabpanel" id="setPane-updates" aria-labelledby="setTab-updates" hidden>
        <label class="set-check first"><input id="updateAuto" type="checkbox"> Check for updates automatically when Photo Finder opens</label>
        <div class="hint">The check asks GitHub (github.com/tadosborn/photo-finder) whether a newer version has been released; nothing about you or your photos is sent. Updates are verified before they install, and your index, settings and photos are kept.</div>
        <div class="set-row">
          <span id="updateStatus" class="hint" style="flex:1; margin:0;">Version <?= htmlspecialchars(PF_VERSION) ?></span>
          <button type="button" id="checkUpdatesBtn">Check now</button>
        </div>
      </div>

      <div class="set-pane" role="tabpanel" id="setPane-fixblur" aria-labelledby="setTab-fixblur" hidden>
        <div class="hint first">Fix blur (in Clean up's Blurry list and in the photo view) makes a sharper version of a blurred photo; Enlarge (in Clean up's Small list and in the photo view) makes a larger one. Every version is saved as a copy next to the original and shown beside it; you can then put the best one in the original's place (the original goes to the Recycle Bin).</div>
        <label>On this PC (free)</label>
        <div class="set-row">
          <span id="fbLocalState" class="hint" style="flex:1; margin:0;">Checking…</span>
          <button type="button" id="fbSetupBtn" style="display:none">Set up now</button>
        </div>
        <progress id="fbSetupProg" max="100" value="0" style="display:none; width:100%"></progress>
        <div class="hint">An AI model (NAFNet) runs on this PC, so photos never leave it. Best on camera shake and motion blur; it cannot rescue a photo that is out of focus. About 2 minutes per photo.</div>
        <label for="geminiKey">Cloud AI (optional): Google Gemini API key</label>
        <input id="geminiKey" type="password" autocomplete="off">
        <div class="hint">Create a key in Google AI Studio (aistudio.google.com). Leave blank to keep the saved key.</div>
        <label for="geminiModel">Gemini model</label>
        <select id="geminiModel"></select>
        <div class="provider-note">
          <strong>Cost:</strong> <span id="geminiCost">billed by Google to your account</span>, only when you click "Try cloud AI".<br>
          <strong>Privacy and results:</strong> the photo is sent to Google. The AI redraws the photo, so faces and text can come out different; Google marks its results with an invisible SynthID watermark.
        </div>
      </div>

      <div class="set-pane" role="tabpanel" id="setPane-advanced" aria-labelledby="setTab-advanced" hidden>
        <label for="maxDim" class="first">Largest image size sent to the AI (pixels)</label>
        <input id="maxDim" type="number" min="256" max="2000" step="64" class="narrow">
        <div class="hint">Smaller is cheaper and faster. 1024–1568 is plenty for search.</div>
        <label for="batchSize">Photos per indexing step</label>
        <input id="batchSize" type="number" min="1" max="25" class="narrow">
        <div class="hint">How many photos the indexer describes before it checks for Stop or changed settings. 5 is fine.</div>
        <div class="set-danger">
          <div class="hint" style="margin:0 0 8px;">Index tools. These act at once, without Save.</div>
          <div class="set-row">
            <button type="button" id="resetErrorsBtn">Reset errored items</button>
            <button type="button" id="clearAllBtn">Clear index</button>
          </div>
        </div>
      </div>
    </div>
    <div class="dlg-foot">
      <button type="button" id="cancelSettingsBtn">Cancel</button>
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
  <div class="lb-status" id="lbStatus" role="status" aria-live="polite"></div>
  <button class="lb-nav lb-next" id="lbNext" title="Next (→)">›</button>
  <div class="lb-counter" id="lbCounter"></div>
  <button class="lb-edit-btn" id="lbEditBtn" title="Edit metadata (E)">✎ Edit</button>
  <button class="lb-del-btn" id="lbDelBtn" title="Delete photo — moves file to Recycle Bin (Del)">🗑 Delete</button>
  <button class="lb-enh-btn" id="lbEnhBtn" type="button" title="Fix colour, brightness and contrast; sharpen slightly soft photos">✨ Enhance</button>
  <button class="lb-fix-btn" id="lbFixBtn" type="button" title="Make a sharper copy of a blurred photo with AI">Fix blur</button>
  <button class="lb-rot-btn lb-rot-r" id="lbRotR" type="button" title="Rotate right (R)" aria-label="Rotate right">⟳</button>
  <button class="lb-rot-btn lb-rot-l" id="lbRotL" type="button" title="Rotate left (Shift+R)" aria-label="Rotate left">⟲</button>
  <button class="lb-enl-btn" id="lbEnlBtn" type="button" title="Make a larger, sharper copy of a small photo with AI">Enlarge</button>
  <button class="lb-close" id="lbClose" title="Close (Esc)">×</button>
  <div class="lb-meta">
    <div class="desc" id="lbDesc"></div>
    <div class="tags" id="lbFaces"></div>
    <div class="tags" id="lbTags"></div>
    <div class="tags" id="lbWhere"></div>
    <div class="tags" id="lbPath"></div>
    <div class="lb-details" id="lbDetails"></div>
    <div class="tags" style="margin-top:8px; opacity:0.6;">Arrow keys: navigate · R / Shift+R: rotate · I: toggle info · E: edit · Esc: close</div>
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

<!-- ---- Clean up: review screen (lib/Cleanup.php, api.php cleanup_* actions) ---- -->
<dialog id="cleanupDlg" class="cleanup" aria-labelledby="cuTitle">
  <div class="cu-head">
    <h2 id="cuTitle">Clean up</h2>
    <span class="cu-status" id="cuStatus" role="status"></span>
    <button type="button" id="cuAnalyzeBtn" title="Measure sharpness, brightness and duplicates from the thumbnails. Runs on this PC; no AI, no cost.">Analyse library</button>
    <button type="button" class="cu-close" id="cuClose" aria-label="Close clean up" title="Close (Esc)">×</button>
  </div>
  <div class="cu-progress" id="cuProgress" hidden>
    <progress id="cuProg" value="0" max="100"></progress>
    <span id="cuProgTxt" role="status"></span>
    <button type="button" id="cuStopBtn">Stop</button>
  </div>
  <div class="cu-tabs" role="tablist" aria-label="Kinds of problem photos">
    <button type="button" role="tab" id="cuTabBlurry" data-cu-tab="blurry" aria-selected="true" aria-controls="cuBody">Blurry<span class="cu-count" id="cuCountBlurry">–</span></button>
    <button type="button" role="tab" id="cuTabExposure" data-cu-tab="exposure" aria-selected="false" aria-controls="cuBody">Too dark / too bright / blank<span class="cu-count" id="cuCountExposure">–</span></button>
    <button type="button" role="tab" id="cuTabDuplicates" data-cu-tab="duplicates" aria-selected="false" aria-controls="cuBody">Duplicates<span class="cu-count" id="cuCountDuplicates">–</span></button>
    <button type="button" role="tab" id="cuTabSmall" data-cu-tab="small" aria-selected="false" aria-controls="cuBody">Small<span class="cu-count" id="cuCountSmall">–</span></button>
  </div>
  <div class="cu-controls" id="cuCtlBlurry">
    <label for="cuBlurRange">Sensitivity</label>
    <span class="hint" aria-hidden="true">only the worst</span>
    <input type="range" id="cuBlurRange" min="10" max="150" step="5" value="40" aria-describedby="cuBlurVal">
    <span class="hint" aria-hidden="true">more</span>
    <span id="cuBlurVal"></span>
    <label><input type="checkbox" id="cuBlurAi" checked> Include photos the AI described as blurry</label>
  </div>
  <div class="cu-controls" id="cuCtlExposure" hidden>
    <span class="hint">Nearly black photos with no highlights, washed-out white ones, and frames with hardly any detail.</span>
  </div>
  <div class="cu-controls" id="cuCtlDuplicates" hidden>
    <label for="cuDupMode">Find</label>
    <select id="cuDupMode">
      <option value="0">Exact copies only</option>
      <option value="4" selected>Copies, including resized ones</option>
      <option value="6">Copies and similar shots (bursts)</option>
    </select>
    <span class="hint">The best copy (most pixels, then largest file, then earliest date) is never selected for you.</span>
  </div>
  <div class="cu-controls" id="cuCtlSmall" hidden>
    <label for="cuSmallSide">Smaller than</label>
    <select id="cuSmallSide">
      <option value="640">640 px (very small)</option>
      <option value="1024">1024 px</option>
      <option value="1600" selected>1600 px</option>
    </select>
    <span class="hint">on the long side, smallest first. "Enlarge…" makes larger, sharper versions, saved next to the original, to compare side by side.</span>
  </div>
  <div class="cu-actions">
    <button type="button" id="cuSelAll">Select all</button>
    <button type="button" id="cuSelNone">Select none</button>
    <button type="button" id="cuSelExtra" hidden>Select all extra copies</button>
    <span class="cu-selcount" id="cuSelCount" role="status">0 selected</span>
    <span class="spacer"></span>
    <button type="button" id="cuFixSelBtn" title="Fix the selected photos one after another on this PC; each gets a copy next to it">Fix selected…</button>
    <button type="button" id="cuKeepBtn" title="Hide the selected photos from Clean up for good. Nothing is deleted.">Keep (don't show again)</button>
    <button type="button" id="cuDeleteBtn" class="danger">Move selected to Recycle Bin</button>
    <button type="button" id="cuDeleteCancel" hidden>Cancel</button>
  </div>
  <div class="cu-result" id="cuResult" role="status" hidden></div>
  <div class="cu-body" id="cuBody" role="tabpanel" aria-labelledby="cuTabBlurry"></div>
  <div class="cu-pager" id="cuPager" hidden>
    <button type="button" id="cuPrev">‹ Previous</button>
    <span id="cuPageTxt"></span>
    <button type="button" id="cuNext">Next ›</button>
  </div>
  <div class="cu-foot">
    <span id="cuKeptTxt"></span>
    <span class="spacer"></span>
    <button type="button" id="cuUnkeepBtn" hidden>Show kept photos again</button>
  </div>
</dialog>
<!-- ---- end Clean up ---- -->

<!-- ---- Fix blur and Enlarge (lib/Deblur.php): the original and every result side by side, or a slider ---- -->
<dialog id="deblurDlg" class="deblur" aria-labelledby="dbTitle">
  <div class="db-head">
    <h2 id="dbTitle">Fix blur</h2>
    <span class="db-name" id="dbName"></span>
    <span class="db-viewbar" role="group" aria-label="View">
      <button type="button" id="dbViewGrid" aria-pressed="true" title="Original and every result next to each other">Side by side</button>
      <button type="button" id="dbViewSlider" aria-pressed="false" title="Original and one result on top of each other, with a slider">Slider</button>
    </span>
    <span class="db-viewbar" role="group" aria-label="Zoom (side by side)" id="dbZoomBar">
      <button type="button" data-zoom="1" aria-pressed="true" title="Whole photos">Fit</button>
      <button type="button" data-zoom="2" aria-pressed="false" title="Zoom in; move the mouse over a photo to look around">2×</button>
      <button type="button" data-zoom="4" aria-pressed="false" title="Zoom in further; move the mouse over a photo to look around">4×</button>
    </span>
    <button type="button" class="db-close" id="dbClose" aria-label="Close Fix blur" title="Close (Esc). Work in progress carries on.">×</button>
  </div>
  <div class="db-grid" id="dbGrid">
    <figure class="db-pane" id="dbPaneOrig">
      <img alt="Original photo">
      <figcaption><span class="db-cap">Original</span></figcaption>
    </figure>
    <figure class="db-pane" data-kind="local" hidden>
      <img alt="Fixed on this PC" hidden>
      <figcaption><span class="db-cap">On this PC</span><button type="button" class="db-pick" data-kind="local" aria-pressed="false">Use this</button></figcaption>
      <div class="db-pane-note"></div>
    </figure>
    <figure class="db-pane" data-kind="cloud" hidden>
      <img alt="Redrawn by cloud AI" hidden>
      <figcaption><span class="db-cap">Cloud AI (redrawn)</span><button type="button" class="db-pick" data-kind="cloud" aria-pressed="false">Use this</button></figcaption>
      <div class="db-pane-note"></div>
    </figure>
    <figure class="db-pane" data-kind="chain" hidden>
      <img alt="Fixed on this PC, then redrawn by cloud AI" hidden>
      <figcaption><span class="db-cap">On this PC, then cloud AI</span><button type="button" class="db-pick" data-kind="chain" aria-pressed="false">Use this</button></figcaption>
      <div class="db-pane-note"></div>
    </figure>
    <figure class="db-pane" data-kind="up_local" hidden>
      <img alt="Enlarged on this PC" hidden>
      <figcaption><span class="db-cap">Enlarged on this PC</span><button type="button" class="db-pick" data-kind="up_local" aria-pressed="false">Use this</button></figcaption>
      <div class="db-pane-note"></div>
    </figure>
    <figure class="db-pane" data-kind="up_cloud" hidden>
      <img alt="Enlarged by cloud AI" hidden>
      <figcaption><span class="db-cap">Enlarged by cloud AI (redrawn)</span><button type="button" class="db-pick" data-kind="up_cloud" aria-pressed="false">Use this</button></figcaption>
      <div class="db-pane-note"></div>
    </figure>
    <figure class="db-pane" data-kind="color" hidden>
      <img alt="Colour corrected in a batch" hidden>
      <figcaption><span class="db-cap">Colour corrected</span><button type="button" class="db-pick" data-kind="color" aria-pressed="false">Use this</button></figcaption>
      <div class="db-pane-note"></div>
    </figure>
    <figure class="db-pane" data-kind="up_local_2x" hidden>
      <img alt="Enlarged 2×, from a batch" hidden>
      <figcaption><span class="db-cap">Enlarged 2×</span><button type="button" class="db-pick" data-kind="up_local_2x" aria-pressed="false">Use this</button></figcaption>
      <div class="db-pane-note"></div>
    </figure>
    <figure class="db-pane" data-kind="fixed" hidden>
      <img alt="All fixes, from a batch" hidden>
      <figcaption><span class="db-cap">All fixes</span><button type="button" class="db-pick" data-kind="fixed" aria-pressed="false">Use this</button></figcaption>
      <div class="db-pane-note"></div>
    </figure>
    <figure class="db-pane" data-kind="fixed_2x" hidden>
      <img alt="All fixes at 2×, from a batch" hidden>
      <figcaption><span class="db-cap">All fixes, 2×</span><button type="button" class="db-pick" data-kind="fixed_2x" aria-pressed="false">Use this</button></figcaption>
      <div class="db-pane-note"></div>
    </figure>
  </div>
  <div class="db-compare" id="dbCompare" hidden>
    <img id="dbBefore" alt="Original photo">
    <img id="dbAfter" class="db-after" alt="Result" hidden>
    <div class="db-line" id="dbLine" hidden></div>
    <span class="db-tag l">Original</span>
    <span class="db-tag r" id="dbTagR" hidden>On this PC</span>
    <input type="range" class="db-slider" id="dbSlider" min="0" max="100" value="50" aria-label="Compare: drag to show more of the original or of the result" hidden>
  </div>
  <div class="db-bar">
    <div class="db-status" id="dbStatus" role="status" aria-live="polite"></div>
    <progress id="dbProg" max="100" value="0" hidden></progress>
    <div class="db-row">
      <span class="db-which" id="dbWhich" role="group" aria-label="Result shown in the slider" hidden></span>
      <button type="button" id="dbLocalBtn">Fix on this PC</button>
      <button type="button" id="dbCloudBtn">Try cloud AI</button>
      <button type="button" id="dbChainBtn">Cloud AI on the on-PC result</button>
      <span class="spacer"></span>
      <button type="button" id="dbReplaceBtn" disabled>Replace the original</button>
      <button type="button" class="primary" id="dbSaveBtn" disabled>Save as a copy</button>
    </div>
    <div class="db-hint" id="dbHint"></div>
  </div>
</dialog>
<!-- ---- end Fix blur and Enlarge ---- -->

<!-- ---- Queue (lib/Deblur.php queue()): the batch, Fix blur / Enlarge jobs, describing ---- -->
<dialog id="queueDlg" class="queue" aria-labelledby="qTitle">
  <div class="q-head">
    <h2 id="qTitle">Queue</h2>
    <span class="q-sub" id="qSub" role="status"></span>
    <button type="button" class="db-close" id="qClose" aria-label="Close Queue" title="Close (Esc). The work carries on.">×</button>
  </div>
  <div class="q-body">
    <section class="q-sec" id="qBatch" hidden>
      <h3>Batch</h3>
      <div class="q-line" id="qBatchLine"></div>
      <progress id="qBatchProg" max="100" value="0" aria-label="Batch progress"></progress>
      <div class="q-line q-mute" id="qBatchCounts"></div>
      <div class="q-actions"><button type="button" id="qReviewBtn">Review all results</button> <button type="button" id="qStopBtn">Stop the batch</button></div>
      <div class="q-list" id="qBatchList"></div>
    </section>
    <section class="q-sec">
      <h3>Single photos (Fix blur, Enlarge)</h3>
      <div class="q-list" id="qJobs"></div>
    </section>
    <section class="q-sec">
      <h3>Describing photos</h3>
      <div class="q-line" id="qIndex"></div>
    </section>
  </div>
</dialog>

<!-- ---- Batch review (Deblur::batchReview / batchReplace): every photo with its versions ---- -->
<dialog id="reviewDlg" class="queue review" aria-labelledby="rvTitle">
  <div class="q-head">
    <h2 id="rvTitle">Review batch results</h2>
    <span class="q-sub" id="rvSub" role="status"></span>
    <button type="button" class="db-close" id="rvClose" aria-label="Close review" title="Close (Esc)">×</button>
  </div>
  <div class="q-body" id="rvBody"></div>
  <div class="rv-foot">
    <button type="button" id="rvPrev">‹ Previous</button>
    <span class="q-mute" id="rvPage"></span>
    <button type="button" id="rvNext">Next ›</button>
    <span class="spacer"></span>
    <span id="rvChosen" role="status"></span>
    <button type="button" id="rvClear">Clear choices</button>
    <button type="button" id="rvReplace" disabled>Replace the originals</button>
  </div>
</dialog>

<!-- ---- Clean up: fix the selected photos in a batch (Deblur::batchStart) ---- -->
<dialog id="batchDlg" class="batch" aria-labelledby="bTitle">
  <h2 id="bTitle">Fix selected photos</h2>
  <p id="bCount"></p>
  <label class="b-opt b-all"><input type="checkbox" id="bAll"> <span><strong>All fixes</strong> <span class="q-mute">(remove blur, then correct colour, then enlarge the small ones)</span></span></label>
  <label class="b-opt"><input type="checkbox" id="bDeblur"> <span>Remove blur <span class="q-mute">(best on camera shake; one to several minutes per photo)</span></span></label>
  <label class="b-opt"><input type="checkbox" id="bColor"> <span>Correct colour and brightness <span class="q-mute">(Enhance's Auto; seconds per photo)</span></span></label>
  <label class="b-opt"><input type="checkbox" id="bEnlarge"> <span>Enlarge small photos <span class="q-mute">(up to 2,400 px; photos up to 1,200 px get both 4× and 2×; seconds per photo)</span></span></label>
  <label class="b-opt b-all" style="border-bottom: none; border-top: 1px solid var(--line); padding: 8px 0 0;"><input type="checkbox" id="bAi"> <span><strong>Let AI pick the best version and size</strong> <span class="q-mute" id="bAiNote">(Claude compares every version, checking close-ups of faces for distortion; uses the Claude key that describes your photos)</span></span></label>
  <p class="q-mute" id="bEstimate"></p>
  <p class="q-mute">Everything runs on this PC (free; nothing is sent anywhere), one photo at a time at low priority, so you can keep using Photo Finder or close it. Each fix is saved as its own copy next to the photo and, with more than one ticked, so is all of them together ("(fixed)"); the originals are not changed. Queue shows the progress; its "Review all results" shows every photo with its versions side by side, to choose which originals to replace.</p>
  <div class="b-status" id="bStatus" role="status"></div>
  <div class="b-row"><button type="button" id="bCancel">Cancel</button><button type="button" class="primary" id="bStart">Start</button></div>
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
          <span class="enh-ends" id="enh_warmth_ends"><span>cooler</span><span>warmer</span></span></div>
        <div class="enh-row"><label for="enh_tint">Tint</label><output for="enh_tint" id="enh_tint_out">0</output>
          <input type="range" id="enh_tint" data-enh="tint" min="-100" max="100" step="1" value="0" aria-describedby="enh_tint_ends">
          <span class="enh-ends" id="enh_tint_ends"><span>greener</span><span>pinker</span></span></div>
        <div class="enh-row"><label for="enh_saturation">Saturation</label><output for="enh_saturation" id="enh_saturation_out">0</output>
          <input type="range" id="enh_saturation" data-enh="saturation" min="-100" max="100" step="1" value="0"></div>
        <div class="enh-row"><label for="enh_sharpen">Sharpen</label><output for="enh_sharpen" id="enh_sharpen_out">0</output>
          <input type="range" id="enh_sharpen" data-enh="sharpen" min="0" max="100" step="1" value="0" aria-describedby="enhSharpenNote"></div>
        <p class="enh-note" id="enhSharpenNote">Sharpen makes slightly soft photos look crisper. It cannot fix a photo that is badly out of focus or blurred by camera shake.</p>
      </div>
      <div class="enh-section enh-save">
        <p class="enh-hint" id="enhSaveHint" aria-live="polite"></p>
        <div class="enh-save-row">
          <button type="button" id="enhReplaceBtn">Replace the original</button>
          <button type="button" class="primary" id="enhSave">Save as a copy</button>
        </div>
        <p class="enh-status" id="enhStatus" role="status" aria-live="polite"></p>
      </div>
    </div>
  </div>
</dialog>
<!-- ==== end Enhance view ==== -->

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

<!-- ---- Find photos on this PC and consolidate (lib/Consolidate.php) ---- -->
<dialog id="consolDlg" class="consol" aria-labelledby="consolTitle">
  <div class="dlg-head">
    <h2 id="consolTitle">Find photos on this PC</h2>
    <button type="button" id="consolClose" aria-label="Close">×</button>
  </div>
  <div class="dlg-body">
    <section data-step="start">
      <p class="cs-lead">Photo Finder looks for pictures that are not in your photo library yet: on your Desktop and in Downloads, Documents, Pictures, Videos, the rest of your user folder and OneDrive. You choose what to gather into the library; nothing moves until you confirm.</p>
      <ul class="cs-facts">
        <li>Program, game, code-project and website folders, app data, other cloud services' folders and folders other photo apps manage are left alone.</li>
        <li>OneDrive files that are only in the cloud are not downloaded while searching.</li>
        <li>Each file keeps its folder names, nothing is overwritten, copies already in the library are skipped, and every move can be undone.</li>
      </ul>
      <label class="cs-check"><input type="checkbox" id="consolDrives"> Also search this PC's other drives <span id="consolDrivesList"></span></label>
      <div class="cs-actions">
        <button type="button" class="primary" id="consolSearchBtn">Search</button>
        <button type="button" id="consolResumeBtn" hidden>Review the last search</button>
      </div>
      <div class="cs-info" id="consolStartInfo" role="status"></div>
      <div class="cs-runs" id="consolRuns"></div>
    </section>
    <section data-step="searching" hidden>
      <h3>Searching…</h3>
      <div class="cs-bar" role="progressbar" aria-label="Search progress" aria-valuemin="0" aria-valuemax="100" id="consolSearchBarWrap"><span id="consolSearchBar"></span></div>
      <div id="consolSearchText" role="status" aria-live="polite"></div>
      <div class="cs-sub" id="consolSearchSub"></div>
      <button type="button" id="consolSearchCancel">Stop searching</button>
    </section>
    <section data-step="review" hidden>
      <div id="consolSummary"></div>
      <div class="cs-toolbar">
        <label for="consolFilter">Show</label>
        <select id="consolFilter">
          <option value="all">All folders</option>
          <option value="sel">Ticked</option>
          <option value="unsel">Not ticked</option>
        </select>
        <button type="button" id="consolAll" title="Ticks every picture in the folders shown, except small program or web images and folders marked with a caution">Tick all</button>
        <button type="button" id="consolNone">Untick all</button>
      </div>
      <div id="consolGroups"></div>
      <details class="cs-skipped" id="consolSkipped">
        <summary>Folders that were not searched (<span id="consolSkippedN">0</span>)</summary>
        <div id="consolSkippedList"></div>
      </details>
    </section>
    <section data-step="moving" hidden>
      <h3 id="consolMoveTitle">Moving…</h3>
      <div class="cs-bar" role="progressbar" aria-label="Progress" aria-valuemin="0" aria-valuemax="100" id="consolMoveBarWrap"><span id="consolMoveBar"></span></div>
      <div id="consolMoveText" role="status" aria-live="polite"></div>
      <div class="cs-sub" id="consolMoveSub"></div>
      <button type="button" id="consolMoveCancel">Stop after this file</button>
    </section>
    <section data-step="done" hidden>
      <div id="consolDone"></div>
    </section>
  </div>
  <div class="dlg-foot cs-foot" id="consolFoot" hidden>
    <label for="consolTarget">Move into</label>
    <input id="consolTarget" type="text" spellcheck="false">
    <button type="button" id="consolNewSearch">New search</button>
    <button type="button" class="primary" id="consolMoveBtn">Move selected</button>
    <div class="hint" id="consolTargetHint">Each picture goes into a "From Desktop", "From Downloads", … folder there that keeps its original folder names.</div>
  </div>
</dialog>
<!-- ---- end of Find photos on this PC ---- -->

<div id="toast" class="toast" popover="manual" role="status" aria-live="polite"></div>

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
  // Re-showing the popover puts it on top of the top layer, above any modal dialog that is open
  // (a plain fixed element stayed hidden behind Fix blur's window).
  if (t.showPopover) {
    try { t.hidePopover(); } catch (e) {}
    try { t.showPopover(); } catch (e) {}
  }
  clearTimeout(t._timer);
  t._timer = setTimeout(() => {
    t.classList.remove('show');
    if (t.hidePopover) setTimeout(() => { if (!t.classList.contains('show')) try { t.hidePopover(); } catch (e) {} }, 250);
  }, ms);
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
  $('#selFixBtn').textContent = n > 1 ? `Fix selected (${n})…` : 'Fix selected…';
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
let lbSeq = 0;

function lbStatus(text) {
  const s = $('#lbStatus');
  s.textContent = text;
  s.classList.toggle('show', !!text);
}

// Shows photo r in the viewer. The caption changes at once, so the picture must never lag behind
// it: a browser keeps painting the previous image until a new one has loaded, which for a OneDrive
// file that is only in the cloud can take seconds (the viewer then showed one photo under another
// photo's description). So the previous picture is hidden straight away, the photo's own thumbnail
// (usually cached) stands in, and the full-size file replaces it when it arrives. Answers for a
// photo the viewer has already moved past are ignored.
function lbShowImage(r) {
  const img = $('#lbImg');
  const seq = ++lbSeq;
  const v = r.v ? '&v=' + r.v : '';
  const mine = () => seq === lbSeq && img.src.includes('id=' + r.id);
  let stage = 'thumb';
  img.classList.add('lb-pending');
  lbStatus('');
  const slow = setTimeout(() => { if (seq === lbSeq && stage !== 'done') lbStatus('Loading photo…'); }, 600);
  const toFull = () => { stage = 'full'; img.src = 'file.php?id=' + r.id + v; };
  img.onload = () => {
    if (!mine()) return;
    img.classList.remove('lb-pending');
    if (stage === 'thumb') { toFull(); return; }
    stage = 'done';
    clearTimeout(slow);
    lbStatus('');
  };
  img.onerror = () => {
    if (!mine()) return;
    if (stage === 'thumb') { toFull(); return; }   // no preview: wait for the full-size file instead
    stage = 'done';
    clearTimeout(slow);
    lbStatus(img.classList.contains('lb-pending')
      ? 'This photo could not be loaded. It may have been moved, or OneDrive may be offline.'
      : 'The full-size photo could not be loaded; this is the preview.');
  };
  img.src = 'thumb.php?id=' + r.id + v;
}

function openLightbox(idx) {
  if (idx < 0 || idx >= lastResults.length) return;
  lbIndex = idx;
  const r = lastResults[idx];
  lbShowImage(r);
  // Enlarge is for small photos; when the size is not known the server decides.
  $('#lbEnlBtn').hidden = !!(r.width && r.height && Math.max(r.width, r.height) > DB_ENLARGE_MAX);
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
  lbLoadDetails(r);
  $('#lbCounter').textContent = (idx + 1) + ' / ' + lastResults.length;
  $('#lbPrev').style.visibility = idx > 0 ? 'visible' : 'hidden';
  $('#lbNext').style.visibility = idx < lastResults.length - 1 ? 'visible' : 'hidden';
  closeEditor();
  if (!$('#lightbox').open) $('#lightbox').showModal();
}

// The photo's own details, read from the file (api photo_details): size, DPI, print size, file
// size and type, colour space; camera, lens and exposure; dates and rotation; GPS altitude.
let lbDetSeq = 0;
async function lbLoadDetails(r) {
  const seq = ++lbDetSeq, el = $('#lbDetails');
  el.textContent = '';
  let d;
  try { d = await api('photo_details', { qs: 'id=' + encodeURIComponent(r.id) }); } catch (e) { return; }
  if (seq !== lbDetSeq) return;
  if (!d.exists) { el.textContent = 'The file is not on disk (it was moved, or OneDrive is offline).'; return; }
  const img = [], cam = [], file = [];
  if (d.width && d.height) {
    img.push(`${d.width.toLocaleString()} × ${d.height.toLocaleString()} px (${(d.width * d.height / 1e6).toFixed(1)} MP)`);
    if (d.dpi) img.push(d.dpi + ' dpi');
    img.push(`prints ${(d.width / 300).toFixed(1)} × ${(d.height / 300).toFixed(1)} in at 300 dpi`);
  }
  if (d.bytes) img.push(cuFmtSize(d.bytes));
  if (d.type) img.push(d.type);
  if (d.color) img.push(d.color === 'uncalibrated' ? 'colour space not set' : d.color);
  if (d.camera) cam.push(d.camera);
  if (d.lens) cam.push(d.lens);
  if (d.aperture) cam.push(d.aperture);
  if (d.shutter) cam.push(d.shutter);
  if (d.iso) cam.push('ISO ' + d.iso);
  if (d.focal) cam.push(d.focal + (d.focal35 ? ` (${d.focal35} in 35 mm terms)` : ''));
  if (d.flash) cam.push('flash ' + d.flash);
  if (d.taken) file.push('taken ' + d.taken.replace(/^(\d{4}):(\d{2}):(\d{2})/, '$1-$2-$3'));
  if (d.modified) file.push('file modified ' + new Date(d.modified * 1000).toLocaleString());
  if (d.rotation) file.push('shown ' + d.rotation + ' (orientation tag)');
  if (d.software) file.push('software: ' + d.software);
  [['🖼 ', img], ['📷 ', cam], ['🗂 ', file]].forEach(([icon, parts]) => {
    if (!parts.length) return;
    const line = document.createElement('div');
    line.textContent = icon + parts.join(' · ');
    el.appendChild(line);
  });
  if (d.alt != null && r.lat != null) $('#lbWhere').append(` · ⛰ ${Math.round(d.alt).toLocaleString()} m altitude`);
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

$('#lightbox').addEventListener('close', () => { lbSeq++; lbStatus(''); });   // drop late image answers
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
  else if (e.key === 'r') { e.preventDefault(); lbRotate('cw'); }
  else if (e.key === 'R') { e.preventDefault(); lbRotate('ccw'); }
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
    el.disabled = !on;
  });
  if (on) enhSaveState();
}
// Two buttons, as in Fix blur / Enlarge: "Save as a copy", and "Replace the original", whose
// first click turns it red ("Confirm: replace the original") for 8 seconds.
function enhSaveState() {
  const btn = $('#enhSave'), rb = $('#enhReplaceBtn'), hint = $('#enhSaveHint'), d = enh.data;
  const none = enhIsNeutral(enh.params);
  btn.disabled = none || enh.saving || !d;
  rb.disabled = none || enh.saving || !d || !d.can_replace;
  rb.title = d && !d.can_replace ? 'Replacing works for JPEG photos only. Save as a copy instead.' : '';
  rb.classList.toggle('confirm-armed', !!enh.armed);
  rb.textContent = enh.armed ? 'Confirm: replace the original' : 'Replace the original';
  hint.classList.toggle('warn', !!enh.armed);
  hint.textContent = none ? 'No changes to save.' : enh.armed
    ? 'Click the red button within 8 seconds to replace “' + (d ? d.name : '') + '”: the original goes to the Recycle Bin (you can restore it from there) and the enhanced photo takes its name, date, place, description and people.'
    : '“Save as a copy” saves “' + (d ? d.copy_name : '') + '” next to the original, with the same date, place, description and people; the original is not changed.';
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

async function enhSave(mode) {
  if (!enh.params || enh.saving || !enh.data || enhIsNeutral(enh.params)) return;
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
$('#enhSave').addEventListener('click', () => enhSave('copy'));
$('#enhReplaceBtn').addEventListener('click', () => enhSave('replace'));
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
$('#setupBtn').addEventListener('click', () => { settingsTab = 'describe'; $('#settingsBtn').click(); });

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
      : 'No graphics card suited to local models, so expect a minute or more per photo'
        + (r.photos ? ' (days for your ' + r.photos.toLocaleString() + ' photos)' : '')
        + '. The Test button measures it; a cloud option is much faster.';
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
    : 'Bundled with Photo Finder.';
  $('#expDescription').checked = s.export_description;
  $('#expTags').checked = s.export_tags;
  $('#expOcrText').checked = s.export_ocr_text;
  $('#expReplaceDesc').checked = s.export_replace_description;
  $('#expOverwriteOrig').checked = s.export_overwrite_original;
  $('#updateAuto').checked = s.update_check !== false;
  $('#expBatchSize').value = s.export_batch_size;
  // Fix blur
  const gm = $('#geminiModel');
  gm.textContent = '';
  Object.entries(s.gemini_models || {}).forEach(([id, m]) => {
    const o = document.createElement('option'); o.value = id; o.textContent = m.label; o.dataset.cost = m.cost; gm.appendChild(o);
  });
  gm.value = s.gemini_model;
  fbShowCost();
  $('#geminiKey').value = '';
  $('#geminiKey').placeholder = s.gemini_api_key_set ? '(saved; leave blank to keep)' : '';
  fbSetupShow({ ready: s.deblur_ready, setup: null, download_mb: s.deblur_download_mb });
  updateBackendVisibility();
  showSettingsTab(settingsTab);
  settingsTab = 'folders';
  $('#settingsDlg').showModal();
});
// Cancel, × and Esc close without saving; the next open reloads the saved settings.
$('#closeSettings').addEventListener('click', () => $('#settingsDlg').close());
$('#cancelSettingsBtn').addEventListener('click', () => $('#settingsDlg').close());

// Settings tabs (arrow keys move between them, as for any tab list).
let settingsTab = 'folders';
function showSettingsTab(name) {
  $$('#settingsDlg [role="tab"]').forEach(t => {
    const on = t.id === 'setTab-' + name;
    t.setAttribute('aria-selected', on ? 'true' : 'false');
    t.tabIndex = on ? 0 : -1;
    $('#' + t.getAttribute('aria-controls')).hidden = !on;
  });
  $('#settingsDlg .dlg-body').scrollTop = 0;
}
$$('#settingsDlg [role="tab"]').forEach(t => {
  t.addEventListener('click', () => showSettingsTab(t.id.replace('setTab-', '')));
  t.addEventListener('keydown', e => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
    const tabs = [...$$('#settingsDlg [role="tab"]')];
    let i = tabs.indexOf(t);
    i = e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    showSettingsTab(tabs[i].id.replace('setTab-', ''));
    tabs[i].focus();
    e.preventDefault();
  });
});

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
  if ($('#geminiKey').value.trim()) body.gemini_api_key = $('#geminiKey').value.trim();
  if ($('#geminiModel').value) body.gemini_model = $('#geminiModel').value;
  try {
    await api('settings_save', { body });
    toast('Settings saved');
    $('#settingsDlg').close();
    checkProviderSetup();
  } catch (e) { toast(e.message, 'err'); }
});

// ---- Settings > Fix blur: the on-PC runtime's state and its one-time download ----
function fbShowCost() {
  const o = $('#geminiModel').selectedOptions[0];
  $('#geminiCost').textContent = o ? o.dataset.cost + ', billed by Google to your account' : 'billed by Google to your account';
}
$('#geminiModel').addEventListener('change', fbShowCost);
let fbTimer = null;
function fbSetupShow(s) {
  const st = s.setup, btn = $('#fbSetupBtn'), prog = $('#fbSetupProg');
  clearTimeout(fbTimer);
  if (s.ready) {
    $('#fbLocalState').textContent = '✓ Set up and ready.';
    btn.style.display = 'none'; prog.style.display = 'none';
    return;
  }
  if (st && !st.done && !st.error) {
    const pct = st.total ? Math.floor(100 * st.got / st.total) : 0;
    $('#fbLocalState').textContent = st.status === 'installing' ? 'Installing…' : `Downloading ${st.file || ''}… ${pct}%`;
    prog.style.display = ''; prog.value = pct;
    btn.style.display = 'none';
    fbTimer = setTimeout(async () => { try { fbSetupShow(await api('deblur_setup_status')); } catch (e) {} }, 1500);
    return;
  }
  prog.style.display = 'none';
  $('#fbLocalState').textContent = (st && st.error ? 'Setup failed: ' + st.error + ' ' : '')
    + `Not set up yet: a one-time download of about ${s.download_mb} MB (the model and the program that runs it), when you first use Fix blur or now.`;
  btn.style.display = ''; btn.textContent = st && st.error ? 'Try again' : 'Set up now';
}
$('#fbSetupBtn').addEventListener('click', async () => {
  try { fbSetupShow(await api('deblur_setup', { body: {} })); } catch (e) { toast(e.message, 'err', 6000); }
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

// ---- Find photos on this PC and consolidate (lib/Consolidate.php) ----
// The search and the moves run as short batches this page drives, so a big profile never
// hangs a request and Stop works between batches (search) or between files (moves).
const cs = { status: null, search: null, results: null, groups: [], files: new Map(), sel: new Set(), stop: false, busy: false };
const CS_KINDS = { photo: ['camera photo', 'camera photos'], image: ['other picture', 'other pictures'],
                   screenshot: ['screenshot', 'screenshots'], asset: ['program or web image', 'program or web images'] };

function csBytes(n) {
  if (n >= 1073741824) return (n / 1073741824).toFixed(1) + ' GB';
  if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
  if (n >= 1024) return Math.round(n / 1024) + ' KB';
  return n + ' bytes';
}
function csN(n, one, many) { return n.toLocaleString() + ' ' + (n === 1 ? one : (many || one + 's')); }
function csEl(tag, cls, text) {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text != null) e.textContent = text;
  return e;
}
function csWhen(ts) { return ts ? new Date(ts * 1000).toLocaleString() : ''; }
function csButton(text, onClick, cls) {
  const b = csEl('button', cls || null, text);
  b.type = 'button';
  b.addEventListener('click', onClick);
  return b;
}
function csStep(step) {
  $$('#consolDlg section[data-step]').forEach(s => { s.hidden = s.dataset.step !== step; });
  $('#consolFoot').hidden = step !== 'review';
  $('#consolDlg').dataset.step = step;
}
function csBar(wrap, bar, pct) {
  pct = Math.max(0, Math.min(100, Math.round(pct)));
  $(bar).style.width = pct + '%';
  $(wrap).setAttribute('aria-valuenow', String(pct));
}

async function openConsol() {
  if (!$('#consolDlg').open) $('#consolDlg').showModal();
  if (cs.busy) return;              // a search or a move is still running: just show it
  csStep('start');
  $('#consolStartInfo').textContent = 'Checking where your folders are…';
  $('#consolSearchBtn').disabled = true;
  try {
    const s = await api('consolidate_status');
    cs.status = s;
    cs.search = s.search;
    if (s.target) $('#consolTarget').value = s.target;
    $('#consolDrivesList').textContent = s.drives.length ? '(' + s.drives.join(', ') + ', outside the folders above)' : '(outside the folders above)';
    $('#consolStartInfo').textContent = 'Photos you choose are gathered into ' + (s.target || 'your first photo folder')
      + '. You can pick another folder before anything moves.' + (s.test_mode ? ' (Test mode: searching a test profile.)' : '');
    const last = s.search;
    $('#consolResumeBtn').hidden = !last;
    if (last) $('#consolResumeBtn').textContent = last.status === 'running' ? 'Continue the last search' : 'Review the last search';
    csRenderRuns(s.runs || []);
  } catch (e) {
    $('#consolStartInfo').textContent = e.message;
  } finally {
    $('#consolSearchBtn').disabled = false;
  }
}

function csRenderRuns(runs) {
  const box = $('#consolRuns');
  box.textContent = '';
  if (!runs.length) return;
  box.appendChild(csEl('h3', null, 'Earlier consolidations'));
  runs.forEach(r => {
    const c = r.counts;
    const row = csEl('div', 'cs-run');
    let text = csWhen(r.started_at) + ': ' + csN(c.moved + c.undone + c.undo_failed, 'picture') + ' moved into ' + r.target;
    if (r.state === 'undone') text += '. Undone' + (c.undo_failed ? ` (${c.undo_failed} could not be put back)` : '') + '.';
    else if (r.state === 'running') text += ' (not finished)';
    else if (r.state === 'undoing') text += ' (undo not finished)';
    row.appendChild(csEl('span', 'cs-run-text', text));
    row.appendChild(csButton('Details', () => csShowRun(r.run_id)));
    if (r.state === 'running') row.appendChild(csButton('Continue', () => csRunMoves(r.run_id)));
    if ((r.state === 'done' && c.moved > 0) || r.state === 'undoing') row.appendChild(csButton(r.state === 'undoing' ? 'Continue undo' : 'Undo', () => csUndo(r.run_id, r.state === 'undoing')));
    box.appendChild(row);
  });
}

async function csShowRun(runId) {
  try { csRenderDone(await api('consolidate_run', { qs: 'run_id=' + encodeURIComponent(runId) })); }
  catch (e) { toast(e.message, 'err'); }
}

async function csSearch(resumeId) {
  cs.stop = false;
  cs.busy = true;
  csStep('searching');
  $('#consolSearchCancel').disabled = false;
  $('#consolSearchText').textContent = 'Starting…';
  $('#consolSearchSub').textContent = '';
  csBar('#consolSearchBarWrap', '#consolSearchBar', 0);
  try {
    let p = resumeId
      ? await api('consolidate_search_tick', { body: { id: resumeId } })
      : await api('consolidate_search_start', { body: { drives: $('#consolDrives').checked } });
    while (p.status === 'running') {
      csShowSearch(p);
      if (cs.stop) { p = await api('consolidate_search_cancel', { body: { id: p.id } }); break; }
      p = await api('consolidate_search_tick', { body: { id: p.id } });
    }
    cs.search = p;
    await csLoadResults(p.id);
  } catch (e) {
    toast(e.message, 'err', 6000);
    csStep('start');
  } finally {
    cs.busy = false;
  }
}

function csShowSearch(p) {
  const total = p.dirs_done + p.dirs_queued;
  csBar('#consolSearchBarWrap', '#consolSearchBar', total ? 100 * p.dirs_done / total : 0);
  $('#consolSearchText').textContent = cs.stop ? 'Stopping…'
    : `Searched ${csN(p.dirs_done, 'folder')}, ${p.dirs_queued.toLocaleString()} to go. Found ${csN(p.images, 'picture')} so far (${p.elapsed} s).`;
  $('#consolSearchSub').textContent = p.current ? 'Now in ' + p.current : '';
}

async function csLoadResults(id) {
  const r = await api('consolidate_results', { qs: 'id=' + encodeURIComponent(id) });
  cs.results = r;
  cs.groups = r.groups;
  cs.files = new Map();
  cs.sel = new Set();
  r.groups.forEach((g, gi) => g.files.forEach(f => {
    f.g = gi;
    cs.files.set(f.i, f);
    if (f.d) cs.sel.add(f.i);
  }));
  if (!$('#consolTarget').value && r.target) $('#consolTarget').value = r.target;
  $('#consolFilter').value = 'all';
  csStep('review');
  csRenderReview();
}

function csRenderReview() {
  const r = cs.results, t = r.totals, s = r.search;
  const sum = $('#consolSummary');
  sum.textContent = '';
  if (!t.files) {
    sum.appendChild(csEl('p', 'cs-lead', `No pictures were found outside your photo library. Searched ${csN(s.dirs_done, 'folder')} in ${s.elapsed} s.`));
  } else {
    const kinds = Object.entries(CS_KINDS).filter(([k]) => t.mix[k]).map(([k, [one, many]]) => csN(t.mix[k], one, many));
    sum.appendChild(csEl('p', 'cs-lead', `Found ${csN(t.files, 'picture')} (${csBytes(t.size)}) in ${csN(t.groups, 'group')}: ${kinds.join(', ')}. Searched ${csN(s.dirs_done, 'folder')} in ${s.elapsed} s.`));
    sum.appendChild(csEl('p', 'cs-lead', t.preselected_files
      ? `Ticked to start with: ${csN(t.preselected_files, 'picture')} (${csBytes(t.preselected_size)}) in ${csN(t.preselected_groups, 'folder')}. Only folders on your Desktop, in Downloads, Documents, Pictures or OneDrive that hold mostly camera photos start ticked; the others say why not.`
      : 'Nothing is ticked to start with: no folder in your usual photo places holds mostly camera photos. Each folder says why; tick what you want to move.'));
  }
  if (s.status === 'cancelled') sum.appendChild(csEl('p', 'cg-reason', `The search was stopped before it finished: ${csN(s.unsearched, 'folder')} not searched.`));
  csRenderGroups();
  $('#consolSkippedN').textContent = r.skipped_count.toLocaleString();
  const list = $('#consolSkippedList');
  list.textContent = '';
  r.skipped.forEach(k => {
    list.appendChild(csEl('div', 'cs-skip-reason', `${k.reason} (${k.count})`));
    k.paths.forEach(p => list.appendChild(csEl('div', 'cs-skip-path', p)));
    if (k.count > k.paths.length) list.appendChild(csEl('div', 'cs-skip-path', `…and ${k.count - k.paths.length} more`));
  });
  if (r.hidden) list.appendChild(csEl('div', 'cs-skip-reason', `${csN(r.hidden, 'hidden or system picture')} left alone`));
  csUpdateMoveBtn();
}

/** What a group's checkbox ticks: everything movable except small program or web images. */
function csGroupPick(f) { return !f.x && f.k !== 'asset'; }

function csGroupState(g) {
  const pick = g.files.filter(csGroupPick);
  const picked = pick.filter(f => cs.sel.has(f.i)).length;
  const ticked = g.files.filter(f => cs.sel.has(f.i)).length;
  return { all: pick.length > 0 && picked === pick.length, some: ticked > 0, ticked, pick: pick.length };
}

function csRenderGroups() {
  const box = $('#consolGroups');
  box.textContent = '';
  const filter = $('#consolFilter').value;
  const frag = document.createDocumentFragment();
  let shown = 0;
  cs.groups.forEach((g, gi) => {
    const st = csGroupState(g);
    if ((filter === 'sel' && !st.some) || (filter === 'unsel' && st.some)) return;
    shown++;
    frag.appendChild(csGroupEl(g, gi, st));
  });
  if (!shown && cs.groups.length) frag.appendChild(csEl('div', 'err-empty', 'No folders match this filter.'));
  box.appendChild(frag);
}

function csCountText(g, st) {
  return `${csN(g.count, 'file')} · ${csBytes(g.size)}` + (st.ticked ? ` · ${st.ticked.toLocaleString()} ticked` : '');
}

function csGroupEl(g, gi, st) {
  const el = csEl('div', 'cg');
  el.dataset.g = gi;
  const row = csEl('div', 'cg-row');
  const cb = document.createElement('input');
  cb.type = 'checkbox';
  cb.id = 'cg' + gi;
  cb.checked = st.all;
  cb.indeterminate = !st.all && st.some;
  cb.disabled = st.pick === 0;
  cb.setAttribute('aria-describedby', 'cgm' + gi);
  cb.addEventListener('change', () => csToggleGroup(gi, cb.checked));
  const lab = csEl('label', 'cg-name');
  lab.htmlFor = cb.id;
  lab.title = g.dir;
  lab.appendChild(csEl('span', 'cg-root', g.root));
  if (g.rel) lab.append(' › ' + g.rel.split('/').join(' › '));
  if (g.bucket === 'screenshots') lab.append(' (screenshots)');
  const exp = csButton('Show files', () => csToggleFiles(gi, exp));
  exp.setAttribute('aria-expanded', 'false');
  exp.setAttribute('aria-controls', 'cgf' + gi);
  row.append(cb, lab, csEl('span', 'cg-count', csCountText(g, st)), exp);
  el.appendChild(row);
  const meta = csEl('div', 'cg-meta');
  meta.id = 'cgm' + gi;
  Object.entries(CS_KINDS).forEach(([k, [one, many]]) => { if (g.mix[k]) meta.appendChild(csEl('span', 'chip', csN(g.mix[k], one, many))); });
  if (g.excluded) meta.appendChild(csEl('span', 'chip', g.excluded + ' cannot be moved'));
  if (g.reason) meta.appendChild(csEl('span', 'cg-reason', (g.caution ? 'Caution: ' : 'Not ticked: ') + g.reason));
  g.notes.forEach(n => meta.appendChild(csEl('span', 'cg-note', n)));
  el.appendChild(meta);
  if (g.samples.length) {
    const th = csEl('div', 'cg-thumbs');
    g.samples.forEach(id => {
      const img = document.createElement('img');
      img.loading = 'lazy';
      img.alt = '';
      img.src = 'api.php?action=consolidate_thumb&id=' + id;
      img.addEventListener('error', () => img.remove());
      th.appendChild(img);
    });
    el.appendChild(th);
  }
  const files = csEl('div', 'cg-files');
  files.id = 'cgf' + gi;
  files.hidden = true;
  el.appendChild(files);
  return el;
}

function csSyncGroup(gi) {
  const el = document.querySelector(`#consolGroups .cg[data-g="${gi}"]`);
  if (!el) return;
  const g = cs.groups[gi], st = csGroupState(g);
  const cb = el.querySelector('.cg-row input');
  cb.checked = st.all;
  cb.indeterminate = !st.all && st.some;
  el.querySelector('.cg-count').textContent = csCountText(g, st);
  el.querySelectorAll('.cf input').forEach(i => { i.checked = cs.sel.has(+i.dataset.f); });
}

function csToggleGroup(gi, on) {
  cs.groups[gi].files.forEach(f => {
    if (!on) cs.sel.delete(f.i);
    else if (csGroupPick(f)) cs.sel.add(f.i);
  });
  csSyncGroup(gi);
  csUpdateMoveBtn();
}

function csToggleFiles(gi, btn) {
  const box = $('#cgf' + gi);
  const open = box.hidden;
  if (open && !box.childElementCount) csRenderFiles(gi, box);
  box.hidden = !open;
  btn.setAttribute('aria-expanded', String(open));
  btn.textContent = open ? 'Hide files' : 'Show files';
}

function csRenderFiles(gi, box) {
  const frag = document.createDocumentFragment();
  cs.groups[gi].files.forEach(f => {
    const lab = csEl('label', 'cf');
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.dataset.f = f.i;
    cb.checked = cs.sel.has(f.i);
    cb.disabled = !!f.x;
    cb.addEventListener('change', () => {
      if (cb.checked) cs.sel.add(f.i); else cs.sel.delete(f.i);
      csSyncGroup(gi);
      csUpdateMoveBtn();
    });
    lab.append(cb, csEl('span', 'cf-name', f.n));
    lab.appendChild(csEl('span', 'cf-info', `${csBytes(f.s)} · ${(CS_KINDS[f.k] || [f.k])[0]} (${f.b})` + (f.w ? ` · ${f.w}×${f.h}` : '')));
    if (f.c) lab.appendChild(csEl('span', 'badge', 'only in the cloud'));
    if (f.x) lab.appendChild(csEl('span', 'badge warn', f.x));
    frag.appendChild(lab);
  });
  box.appendChild(frag);
}

function csUpdateMoveBtn() {
  let n = 0, b = 0;
  cs.sel.forEach(i => { const f = cs.files.get(i); if (f) { n++; b += f.s; } });
  const btn = $('#consolMoveBtn');
  btn.textContent = n ? `Move ${csN(n, 'picture')} (${csBytes(b)})` : 'Move selected';
  btn.disabled = n === 0;
}

function csTickAll(on) {
  const filter = $('#consolFilter').value;
  cs.groups.forEach(g => {
    const st = csGroupState(g);
    if ((filter === 'sel' && !st.some) || (filter === 'unsel' && st.some)) return;
    g.files.forEach(f => {
      if (!on) cs.sel.delete(f.i);
      else if (csGroupPick(f) && !g.caution) cs.sel.add(f.i);   // caution groups only by hand
    });
  });
  csRenderGroups();
  csUpdateMoveBtn();
}

async function csMove() {
  const ids = [...cs.sel];
  if (!ids.length) return;
  const target = $('#consolTarget').value.trim();
  if (!target) { toast('Choose the folder to move them into.', 'err'); $('#consolTarget').focus(); return; }
  if (!confirm(`Move ${csN(ids.length, 'picture')} into\n${target}?\n\nEach one goes into a "From Desktop", "From Downloads", ... folder there that keeps its original folder names. Nothing is overwritten, copies already in the library are skipped, and you can undo this afterwards.`)) return;
  try {
    const p = await api('consolidate_start', { body: { search_id: cs.results.search.id, target, ids } });
    await csRunMoves(p.run_id);
  } catch (e) {
    toast(e.message, 'err', 6000);
  }
}

async function csRunMoves(runId) {
  cs.busy = true;
  cs.stop = false;
  csStep('moving');
  $('#consolMoveTitle').textContent = 'Moving…';
  $('#consolMoveCancel').hidden = false;
  $('#consolMoveCancel').disabled = false;
  $('#consolMoveText').textContent = 'Checking what is already in your library…';
  $('#consolMoveSub').textContent = '';
  csBar('#consolMoveBarWrap', '#consolMoveBar', 0);
  try {
    let p = await api('consolidate_tick', { body: { run_id: runId } });
    while (!p.finished) {
      csShowMoves(p);
      if (cs.stop) { await api('consolidate_cancel', { body: { run_id: runId } }); break; }
      p = await api('consolidate_tick', { body: { run_id: runId } });
    }
    $('#consolMoveCancel').disabled = true;
    $('#consolMoveText').textContent = 'Adding the moved pictures to your library…';
    const sum = await api('consolidate_finish', { body: { run_id: runId } });
    csRenderDone(sum);
    if (sum.scan) refreshStats();
  } catch (e) {
    toast(e.message, 'err', 8000);
    $('#consolMoveText').textContent = 'Stopped by an error: ' + e.message + ' Open this dialog again to continue or undo.';
  } finally {
    cs.busy = false;
  }
}

function csShowMoves(p) {
  const c = p.counts, done = c.total - c.queued;
  csBar('#consolMoveBarWrap', '#consolMoveBar', c.total ? 100 * done / c.total : 0);
  if (!p.prepared) return;
  $('#consolMoveText').textContent = cs.stop ? 'Stopping after this file…'
    : `${done.toLocaleString()} of ${c.total.toLocaleString()} done: ${c.moved.toLocaleString()} moved, ${c.duplicate.toLocaleString()} already in the library, ${c.failed.toLocaleString()} could not be moved.`;
}

async function csUndo(runId, resume) {
  if (!resume && !confirm('Put every picture from this consolidation back where it came from?')) return;
  cs.busy = true;
  csStep('moving');
  $('#consolMoveTitle').textContent = 'Putting pictures back…';
  $('#consolMoveCancel').hidden = true;
  $('#consolMoveText').textContent = 'Starting…';
  $('#consolMoveSub').textContent = '';
  csBar('#consolMoveBarWrap', '#consolMoveBar', 0);
  try {
    let p;
    do {
      p = await api('consolidate_undo_tick', { body: { run_id: runId } });
      const c = p.counts, all = c.moved + c.undone + c.undo_failed;
      csBar('#consolMoveBarWrap', '#consolMoveBar', all ? 100 * (c.undone + c.undo_failed) / all : 100);
      $('#consolMoveText').textContent = `${c.undone.toLocaleString()} of ${all.toLocaleString()} put back` + (c.undo_failed ? `, ${c.undo_failed} could not be` : '') + '.';
    } while (!p.undo_finished);
    csRenderDone(p);
    refreshStats();
  } catch (e) {
    toast(e.message, 'err', 8000);
    $('#consolMoveText').textContent = 'Stopped by an error: ' + e.message + ' Open this dialog again to continue.';
  } finally {
    cs.busy = false;
  }
}

function csList(title, items, line, cls) {
  const box = csEl('div');
  box.appendChild(csEl('h3', cls || null, title));
  const ul = csEl('ul', 'cs-list');
  items.slice(0, 300).forEach(it => ul.appendChild(csEl('li', null, line(it))));
  if (items.length > 300) ul.appendChild(csEl('li', null, `…and ${items.length - 300} more`));
  box.appendChild(ul);
  return box;
}

function csRenderDone(s) {
  csStep('done');
  const box = $('#consolDone');
  box.textContent = '';
  const c = s.counts;
  const undone = s.state === 'undone' || s.state === 'undoing';
  if (undone) {
    box.appendChild(csEl('h3', null, s.state === 'undone' ? 'Undone' : 'Undo not finished'));
    box.appendChild(csEl('p', 'cs-lead', `${csN(c.undone, 'picture')} put back where ${c.undone === 1 ? 'it' : 'they'} came from.`
      + (s.undone_at ? ' (' + csWhen(s.undone_at) + ')' : '')));
    if (s.undo_failed_list.length) box.appendChild(csList(`${csN(s.undo_failed_list.length, 'picture')} could not be put back`, s.undo_failed_list,
      it => `${it.path}: ${it.reason} (it is at ${it.now_at})`, 'cs-err'));
  } else {
    box.appendChild(csEl('h3', null, c.cancelled ? 'Stopped' : (s.state === 'running' ? 'Not finished' : 'Done')));
    box.appendChild(csEl('p', 'cs-lead', `Moved ${csN(c.moved, 'picture')} (${csBytes(c.bytes_moved)}) into ${s.target}.` + (s.started_at ? ' (' + csWhen(s.started_at) + ')' : '')));
    if (s.added_photo_dir) box.appendChild(csEl('p', null, `${s.target} was added to your photo folders.`));
    if (s.scan) {
      const p = csEl('p', null, `${csN(s.scan.new_files, 'new photo')} added to the index, waiting to be described. `);
      p.appendChild(csButton('Start indexing', () => { $('#consolDlg').close(); startIndexing(); }));
      box.appendChild(p);
    }
    if (c.cancelled) box.appendChild(csEl('p', 'cg-reason', `${csN(c.cancelled, 'picture')} not moved because you stopped.`));
    if (s.failed_list.length) box.appendChild(csList(`${csN(s.failed_list.length, 'picture')} could not be moved`, s.failed_list, it => `${it.path}: ${it.reason}`, 'cs-err'));
    if (s.duplicates.length) box.appendChild(csList(`${csN(s.duplicates.length, 'picture')} skipped: an exact copy is already in the library (left where ${s.duplicates.length === 1 ? 'it is' : 'they are'})`,
      s.duplicates, it => `${it.path} = ${it.same_as}`));
  }
  const acts = csEl('div', 'cs-actions');
  if (s.state === 'done' && c.moved > 0) acts.appendChild(csButton('Undo this consolidation', () => csUndo(s.run_id)));
  if (s.state === 'undoing') acts.appendChild(csButton('Continue undo', () => csUndo(s.run_id, true)));
  if (s.state === 'running') acts.appendChild(csButton('Continue moving', () => csRunMoves(s.run_id)));
  acts.appendChild(csButton('Back', () => openConsol()));
  acts.appendChild(csButton('Close', () => $('#consolDlg').close(), 'primary'));
  box.appendChild(acts);
}

$('#consolidateBtn').addEventListener('click', openConsol);
$('#consolFromSettings').addEventListener('click', () => { $('#settingsDlg').close(); openConsol(); });
$('#consolClose').addEventListener('click', () => $('#consolDlg').close());
$('#consolSearchBtn').addEventListener('click', () => csSearch());
$('#consolResumeBtn').addEventListener('click', () => {
  const s = cs.search;
  if (!s) return;
  if (s.status === 'running') csSearch(s.id);
  else csLoadResults(s.id).catch(e => toast(e.message, 'err'));
});
$('#consolSearchCancel').addEventListener('click', () => {
  cs.stop = true;
  $('#consolSearchCancel').disabled = true;
  $('#consolSearchText').textContent = 'Stopping…';
});
$('#consolMoveCancel').addEventListener('click', () => {
  cs.stop = true;
  $('#consolMoveCancel').disabled = true;
  $('#consolMoveText').textContent = 'Stopping after this file…';
});
$('#consolFilter').addEventListener('change', csRenderGroups);
$('#consolAll').addEventListener('click', () => csTickAll(true));
$('#consolNone').addEventListener('click', () => csTickAll(false));
$('#consolMoveBtn').addEventListener('click', csMove);
$('#consolNewSearch').addEventListener('click', () => openConsol());
// ---- end of Find photos on this PC ----

refreshStats().then(() => doSearch());
checkUpdates(false);
checkProviderSetup();

// ---- Clean up: review blurry, badly exposed and duplicate photos ----
// Analysis runs on the server from the thumbnails (cleanup_analyze, no AI). Deleting uses the
// existing delete_files action, which moves files to the Recycle Bin. Nothing is pre-selected.
const CU_PAGE = 120;        // cards per page (Blurry, Exposure)
const CU_GROUP_PAGE = 40;   // groups per page (Duplicates)
const CU_TABS = ['blurry', 'exposure', 'duplicates', 'small'];
const cu = {
  tab: 'blurry',
  cfg: { blur_default: 40, blur_min: 10, blur_max: 150, dup_default: 4, batch: 150 },
  status: null,
  lists: { blurry: [], exposure: [], duplicates: [], small: [] },   // from the server (duplicates = groups)
  view: { blurry: [], exposure: [], duplicates: [], small: [] },    // what the current filters show
  sel: { blurry: new Set(), exposure: new Set(), duplicates: new Set(), small: new Set() },
  page: { blurry: 0, exposure: 0, duplicates: 0, small: 0 },
  loaded: false, analysing: false, stop: false, busy: false,
  armTimer: null, unkeepArmed: false, lbSaved: null,
};

function cuFmtSize(b) {
  if (!b) return '';
  if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
  return Math.max(1, Math.round(b / 1024)) + ' KB';
}
function cuBase(p) { return String(p || '').split(/[\\/]/).pop(); }
function cuFolder(p) { const a = String(p || '').split(/[\\/]/); return a.length > 1 ? a[a.length - 2] : ''; }
function cuPlural(n, one, many) { return Number(n).toLocaleString() + ' ' + (n === 1 ? one : (many || one + 's')); }
function cuDescribedKeys(r) { return Object.keys(r.described || {}); }

function cuSetStatus(s) {
  if (!s) return;
  cu.status = s;
  const el = $('#cuStatus');
  el.textContent = '';
  const strong = document.createElement('strong');
  if (s.remaining > 0) {
    strong.textContent = `${s.analyzed.toLocaleString()} of ${s.total.toLocaleString()} photos analysed.`;
    el.append(strong, ' The lists below only cover analysed photos.');
  } else {
    strong.textContent = `${s.total.toLocaleString()} photos analysed.`;
    el.append(strong);
  }
  if (s.no_thumbnail) el.append(` ${cuPlural(s.no_thumbnail, 'photo has', 'photos have')} no thumbnail yet and ${s.no_thumbnail === 1 ? 'was' : 'were'} skipped.`);
  el.append(' Measured on this PC from the thumbnails: no AI, no cost.');
  $('#cuAnalyzeBtn').hidden = !(s.remaining > 0);
  $('#cuAnalyzeBtn').textContent = `Analyse ${s.remaining.toLocaleString()} more`;
  $('#cuKeptTxt').textContent = s.kept ? `${cuPlural(s.kept, 'photo is', 'photos are')} marked Keep and hidden from Clean up.` : '';
  $('#cuUnkeepBtn').hidden = !s.kept;
}

async function cuOpen() {
  $('#cleanupDlg').showModal();
  try {
    const r = await api('cleanup_status');
    Object.assign(cu.cfg, r.config || {});
    const range = $('#cuBlurRange');
    range.min = cu.cfg.blur_min; range.max = cu.cfg.blur_max;
    if (!cu.loaded) range.value = cu.cfg.blur_default;
    cuSetStatus(r.status);
    if (r.status.remaining > 0) await cuAnalyze();
    else await cuLoadAll();
  } catch (e) { toast(e.message, 'err'); }
}

async function cuAnalyze() {
  if (cu.analysing) return;
  cu.analysing = true; cu.stop = false;
  $('#cuProgress').hidden = false;
  $('#cuAnalyzeBtn').disabled = true;
  try {
    while (!cu.stop) {
      const r = await api('cleanup_analyze', { body: { limit: cu.cfg.batch || 150 } });
      cuSetStatus(r);
      $('#cuProg').max = r.total || 1;
      $('#cuProg').value = r.analyzed;
      $('#cuProgTxt').textContent = `Analysing… ${r.analyzed.toLocaleString()} of ${r.total.toLocaleString()} photos`;
      if (!r.processed || !r.remaining) break;
    }
  } catch (e) { toast(e.message, 'err', 6000); }
  cu.analysing = false;
  $('#cuProgress').hidden = true;
  $('#cuAnalyzeBtn').disabled = false;
  if ($('#cleanupDlg').open) await cuLoadAll();
}

async function cuLoad(kind) {
  const qs = 'kind=' + kind + (kind === 'duplicates' ? '&dist=' + encodeURIComponent($('#cuDupMode').value) : '');
  const r = await api('cleanup_list', { qs });
  cu.lists[kind] = kind === 'duplicates' ? (r.groups || []) : (r.items || []);
  if (r.status) cuSetStatus(r.status);
  cuFilter(kind);
}

async function cuLoadAll() {
  $('#cuBody').innerHTML = '<div class="cu-empty">Loading…</div>';
  try {
    await Promise.all(CU_TABS.map(cuLoad));
    cu.loaded = true;
  } catch (e) { toast(e.message, 'err', 6000); }
  cuRender();
}

/** Apply the tab's filters, keep the selection to what is still listed, clamp the page. */
function cuFilter(kind) {
  if (kind === 'blurry') {
    const t = +$('#cuBlurRange').value;
    const ai = $('#cuBlurAi').checked;
    cu.view.blurry = cu.lists.blurry.filter(r => (r.pixel_blur && r.sharpness < t) || (ai && cuDescribedKeys(r).length));
    const pix = cu.lists.blurry.filter(r => r.pixel_blur && r.sharpness < t).length;
    $('#cuBlurVal').textContent = `sharpness below ${t}: ${cuPlural(pix, 'photo')}`;
  } else if (kind === 'small') {
    const side = +$('#cuSmallSide').value;
    cu.view.small = cu.lists.small.filter(r => Math.max(r.width, r.height) < side);
  } else {
    cu.view[kind] = cu.lists[kind];
  }
  const ids = new Set(cuIds(kind));
  cu.sel[kind].forEach(id => { if (!ids.has(id)) cu.sel[kind].delete(id); });
  cu.page[kind] = Math.min(cu.page[kind], Math.max(0, cuPages(kind) - 1));
  const count = kind === 'duplicates' ? cu.view.duplicates.length : cu.view[kind].length;
  $('#cuCount' + kind[0].toUpperCase() + kind.slice(1)).textContent = count.toLocaleString();
}

function cuIds(kind) {
  if (kind === 'duplicates') return cu.view.duplicates.flatMap(g => g.members.map(m => m.id));
  return cu.view[kind].map(r => r.id);
}
function cuPages(kind) {
  const n = cu.view[kind].length;
  return Math.max(1, Math.ceil(n / (kind === 'duplicates' ? CU_GROUP_PAGE : CU_PAGE)));
}

function cuChip(text, cls) {
  const c = document.createElement('span');
  c.className = 'cu-chip' + (cls ? ' ' + cls : '');
  c.textContent = text;
  return c;
}

const CU_REL = { best: 'Best copy', exact: 'Exact copy', smaller: 'Smaller copy', same: 'Same picture, other file', similar: 'Similar shot' };

function cuCard(r, kind, pageItems, idx) {
  const card = document.createElement('div');
  const sel = cu.sel[kind].has(r.id);
  card.className = 'cu-card' + (sel ? ' selected' : '') + (r.best ? ' best' : '');
  card.dataset.id = r.id;
  const name = cuBase(r.path);

  const thumb = document.createElement('button');
  thumb.type = 'button';
  thumb.className = 'cu-thumb';
  thumb.setAttribute('aria-label', 'Open ' + name);
  thumb.title = 'Open full size';
  const img = document.createElement('img');
  img.loading = 'lazy';
  img.alt = '';
  img.src = 'thumb.php?id=' + encodeURIComponent(r.id);
  thumb.appendChild(img);
  thumb.addEventListener('click', () => cuOpenLightbox(pageItems, idx));

  const meta = document.createElement('div');
  meta.className = 'cu-meta';
  const pick = document.createElement('label');
  pick.className = 'cu-pick';
  const cb = document.createElement('input');
  cb.type = 'checkbox';
  cb.checked = sel;
  cb.setAttribute('aria-label', 'Select ' + name);
  cb.addEventListener('change', () => cuToggle(r.id, cb.checked));
  pick.append(cb, 'Select');

  const chips = document.createElement('div');
  chips.className = 'cu-chips';
  if (kind === 'blurry') {
    const t = +$('#cuBlurRange').value;
    chips.appendChild(cuChip((r.pixel_blur && r.sharpness < t ? 'Soft: sharpness ' : 'Sharpness ') + Math.round(r.sharpness)));
    const d = r.described || {};
    if (d.blurry) chips.appendChild(cuChip('AI: "' + d.blurry + '"', 'ai'));
    if (d.lowres) chips.appendChild(cuChip('AI: "' + d.lowres + '"', 'ai'));
  } else if (kind === 'exposure') {
    (r.problems || []).forEach(p => chips.appendChild(cuChip(p.text)));
    Object.values(r.described || {}).forEach(ph => chips.appendChild(cuChip('AI: "' + ph + '"', 'ai')));
  } else if (kind === 'small') {
    chips.appendChild(cuChip('Small: ' + (r.width * r.height / 1e6).toFixed(r.width * r.height < 1e6 ? 2 : 1) + ' megapixels'));
  } else {
    chips.appendChild(cuChip(CU_REL[r.relation] || r.relation, r.best ? 'best' : ''));
    if (r.cleanup_keep) chips.appendChild(cuChip('Kept', 'kept'));
  }

  const nm = document.createElement('div');
  nm.className = 'cu-name';
  nm.textContent = name;
  nm.title = r.path;
  const sub = document.createElement('div');
  const bits = [];
  if (r.width && r.height) bits.push(r.width + '×' + r.height);
  if (r.size) bits.push(cuFmtSize(r.size));
  if (r.taken_at) bits.push(String(r.taken_at).slice(0, 10));
  sub.textContent = bits.join(' · ');
  const folder = document.createElement('div');
  folder.className = 'cu-path';
  folder.textContent = cuFolder(r.path);
  folder.title = r.path;
  meta.append(pick, chips, nm, sub, folder);
  if (kind === 'blurry') {
    const fix = document.createElement('button');
    fix.type = 'button';
    fix.className = 'cu-fix';
    fix.textContent = 'Fix blur…';
    fix.setAttribute('aria-label', 'Fix blur: make a sharper copy of ' + name);
    fix.addEventListener('click', () => dbOpen(r));
    meta.appendChild(fix);
  } else if (kind === 'small') {
    const enl = document.createElement('button');
    enl.type = 'button';
    enl.className = 'cu-fix';
    enl.textContent = 'Enlarge…';
    enl.setAttribute('aria-label', 'Enlarge: make a larger, sharper copy of ' + name);
    enl.addEventListener('click', () => dbOpen(r, 'enlarge'));
    meta.appendChild(enl);
  }
  card.append(thumb, meta);
  return card;
}

function cuRender() {
  const kind = cu.tab;
  const body = $('#cuBody');
  body.innerHTML = '';
  body.setAttribute('aria-labelledby', 'cuTab' + kind[0].toUpperCase() + kind.slice(1));
  const list = cu.view[kind];
  if (!cu.loaded) {
    body.innerHTML = '<div class="cu-empty">Loading…</div>';
  } else if (!list.length) {
    const empty = {
      blurry: 'No blurry photos at this sensitivity.',
      exposure: 'No photos that are too dark, too bright or blank.',
      duplicates: 'No duplicates found.',
      small: `No photos smaller than ${$('#cuSmallSide').value} px on the long side.`,
    }[kind];
    body.innerHTML = '<div class="cu-empty"></div>';
    body.firstChild.textContent = empty + (cu.status && cu.status.remaining ? ' Some photos are not analysed yet.' : '');
  } else if (kind === 'duplicates') {
    const start = cu.page.duplicates * CU_GROUP_PAGE;
    const groups = list.slice(start, start + CU_GROUP_PAGE);
    const pageItems = groups.flatMap(g => g.members);
    let idx = 0;
    groups.forEach(g => {
      const box = document.createElement('section');
      box.className = 'cu-group';
      const head = document.createElement('div');
      head.className = 'cu-group-head';
      const title = document.createElement('strong');
      title.textContent = { exact: 'Exact copies', copies: 'Copies of the same photo', similar: 'Similar shots' }[g.kind] || 'Duplicates';
      const muted = document.createElement('span');
      muted.className = 'muted';
      muted.textContent = cuPlural(g.members.length, 'photo') + ' · best copy: ' + cuBase(g.members[0].path);
      head.append(title, muted);
      const grid = document.createElement('div');
      grid.className = 'cu-grid';
      g.members.forEach(m => grid.appendChild(cuCard(m, kind, pageItems, idx++)));
      box.append(head, grid);
      body.appendChild(box);
    });
  } else {
    const start = cu.page[kind] * CU_PAGE;
    const items = list.slice(start, start + CU_PAGE);
    if (kind === 'blurry') {
      const p = document.createElement('p');
      p.className = 'cu-explain';
      p.textContent = 'Sharpness is measured on the thumbnail: lower is softer. The AI saw each photo at full size, so photos it described as blurry are listed even when the thumbnail looks sharp.';
      body.appendChild(p);
    } else if (kind === 'small') {
      const p = document.createElement('p');
      p.className = 'cu-explain';
      p.textContent = 'Small photos, smallest first: often pictures from old phones and cameras. Enlarge makes a 2× or 4× version on this PC (free) and, if you ask, one redrawn by cloud AI, saves each next to the original and shows them side by side.';
      body.appendChild(p);
    }
    const grid = document.createElement('div');
    grid.className = 'cu-grid';
    items.forEach((r, i) => grid.appendChild(cuCard(r, kind, items, i)));
    body.appendChild(grid);
  }
  const pages = cuPages(kind);
  $('#cuPager').hidden = pages <= 1;
  const total = kind === 'duplicates' ? cuPlural(list.length, 'group') : cuPlural(list.length, 'photo');
  $('#cuPageTxt').textContent = `Page ${cu.page[kind] + 1} of ${pages} (${total})`;
  $('#cuPrev').disabled = cu.page[kind] <= 0;
  $('#cuNext').disabled = cu.page[kind] >= pages - 1;
  cuUpdateSelBar();
}

function cuUpdateSelBar() {
  const n = cu.sel[cu.tab].size;
  $('#cuSelCount').textContent = n.toLocaleString() + ' selected';
  $('#cuKeepBtn').disabled = !n || cu.busy;
  $('#cuFixSelBtn').hidden = cu.tab === 'duplicates';
  $('#cuFixSelBtn').disabled = !n || cu.busy;
  $('#cuDeleteBtn').disabled = !n || cu.busy;
  const all = cu.tab === 'duplicates' ? cuSelectable('all').length : cu.view[cu.tab].length;
  $('#cuSelAll').textContent = cu.tab === 'duplicates' ? `Select all but the best copies (${all.toLocaleString()})` : `Select all (${all.toLocaleString()})`;
  $('#cuSelAll').disabled = !all || cu.busy;
  $('#cuSelNone').disabled = !n || cu.busy;
  const extra = cuSelectable('extra').length;
  $('#cuSelExtra').textContent = `Select all extra copies (${extra.toLocaleString()})`;
  $('#cuSelExtra').disabled = !extra || cu.busy;
}

/** Duplicates: ids "Select all" may pick ('all' = everything but best copies and kept photos;
 *  'extra' = only true copies, not similar shots, which are different moments). */
function cuSelectable(which) {
  const out = [];
  cu.view.duplicates.forEach(g => g.members.forEach(m => {
    if (m.best || m.cleanup_keep) return;
    if (which === 'extra' && m.relation === 'similar') return;
    out.push(m.id);
  }));
  return out;
}

function cuToggle(id, on) {
  const s = cu.sel[cu.tab];
  if (on) s.add(id); else s.delete(id);
  document.querySelectorAll('#cuBody .cu-card[data-id="' + id + '"]').forEach(card => {
    card.classList.toggle('selected', on);
    const cb = card.querySelector('input[type=checkbox]');
    if (cb) cb.checked = on;
  });
  cuDisarm();
  cuUpdateSelBar();
}

function cuSelectIds(ids) {
  const s = cu.sel[cu.tab];
  s.clear();
  ids.forEach(id => s.add(id));
  cuDisarm();
  cuRender();
}

function cuSwitchTab(kind, focus) {
  cu.tab = kind;
  $$('#cleanupDlg [data-cu-tab]').forEach(b => {
    const on = b.dataset.cuTab === kind;
    b.setAttribute('aria-selected', on ? 'true' : 'false');
    b.tabIndex = on ? 0 : -1;
    if (on && focus) b.focus();
  });
  $('#cuCtlBlurry').hidden = kind !== 'blurry';
  $('#cuCtlExposure').hidden = kind !== 'exposure';
  $('#cuCtlDuplicates').hidden = kind !== 'duplicates';
  $('#cuCtlSmall').hidden = kind !== 'small';
  $('#cuSelExtra').hidden = kind !== 'duplicates';
  cuDisarm();
  cuRender();
  $('#cuBody').scrollTop = 0;
}

// Two clicks instead of a confirm() dialog: the first arms the button and says what will happen.
function cuDisarm() {
  const b = $('#cuDeleteBtn');
  if (cu.armTimer) { clearTimeout(cu.armTimer); cu.armTimer = null; }
  b.classList.remove('armed');
  b.textContent = 'Move selected to Recycle Bin';
  $('#cuDeleteCancel').hidden = true;
}
function cuArm() {
  const ids = [...cu.sel[cu.tab]];
  const b = $('#cuDeleteBtn');
  let text = `Click again to move ${cuPlural(ids.length, 'photo')} to the Recycle Bin`;
  if (cu.tab === 'duplicates') {
    const sel = cu.sel.duplicates;
    const whole = cu.view.duplicates.filter(g => g.members.every(m => sel.has(m.id))).length;
    if (whole) text += ` (this removes every copy of ${cuPlural(whole, 'photo')})`;
  }
  b.textContent = text;
  b.classList.add('armed');
  $('#cuDeleteCancel').hidden = false;
  cu.armTimer = setTimeout(cuDisarm, 10000);
}

function cuShowResult(deleted, failed) {
  const box = $('#cuResult');
  box.innerHTML = '';
  box.classList.toggle('has-fail', failed.length > 0);
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'close';
  close.textContent = 'Dismiss';
  close.addEventListener('click', () => { box.hidden = true; });
  const line = document.createElement('div');
  line.textContent = `Moved ${cuPlural(deleted, 'photo')} to the Recycle Bin. You can restore ${deleted === 1 ? 'it' : 'them'} from there.`
    + (failed.length ? ` ${failed.length} could not be moved:` : '');
  box.append(close, line);
  if (failed.length) {
    const ul = document.createElement('ul');
    failed.forEach(f => {
      const li = document.createElement('li');
      li.textContent = (cuBase(f.path) || f.id) + ': ' + f.error;
      li.title = f.path || '';
      ul.appendChild(li);
    });
    box.appendChild(ul);
  }
  box.hidden = false;
}

async function cuDelete() {
  const ids = [...cu.sel[cu.tab]];
  if (!ids.length) return;
  cuDisarm();
  cu.busy = true;
  cuUpdateSelBar();
  const gone = new Set();
  const failed = [];
  let stats = null;
  try {
    for (let i = 0; i < ids.length; i += 100) {
      $('#cuSelCount').textContent = `Moving ${Math.min(i + 100, ids.length)} of ${ids.length}…`;
      const r = await api('delete_files', { body: { ids: ids.slice(i, i + 100) } });
      (r.deleted_ids || []).forEach(id => gone.add(id));
      (r.failed || []).forEach(f => failed.push(f));
      stats = r.stats || stats;
    }
  } catch (e) { toast(e.message, 'err', 6000); }
  cu.busy = false;
  if (stats) setStats(stats);
  // Forget the recycled photos everywhere: these lists, every tab's selection, the main grid.
  CU_TABS.forEach(k => gone.forEach(id => cu.sel[k].delete(id)));
  cu.lists.blurry = cu.lists.blurry.filter(r => !gone.has(r.id));
  cu.lists.exposure = cu.lists.exposure.filter(r => !gone.has(r.id));
  cu.lists.small = cu.lists.small.filter(r => !gone.has(r.id));
  lastResults = lastResults.filter(r => !gone.has(r.id));
  gone.forEach(id => selectedIds.delete(id));
  renderResults(lastResults);
  cuShowResult(gone.size, failed);
  toast(`Moved ${cuPlural(gone.size, 'photo')} to the Recycle Bin` + (failed.length ? ` (${failed.length} failed)` : ''), failed.length ? 'err' : 'ok');
  try { await cuLoad('duplicates'); } catch (e) { toast(e.message, 'err'); }
  cuFilter('blurry'); cuFilter('exposure'); cuFilter('small');
  cuRender();
}

async function cuKeep() {
  const ids = [...cu.sel[cu.tab]];
  if (!ids.length) return;
  cu.busy = true;
  cuUpdateSelBar();
  try {
    const r = await api('cleanup_keep', { body: { ids, keep: true } });
    const kept = new Set(ids);
    CU_TABS.forEach(k => kept.forEach(id => cu.sel[k].delete(id)));
    cu.lists.blurry = cu.lists.blurry.filter(x => !kept.has(x.id));
    cu.lists.exposure = cu.lists.exposure.filter(x => !kept.has(x.id));
    cu.lists.small = cu.lists.small.filter(x => !kept.has(x.id));
    if (r.status) cuSetStatus(r.status);
    toast(`Keeping ${cuPlural(ids.length, 'photo')}: ${ids.length === 1 ? 'it' : 'they'} won't be suggested again.`);
    await cuLoad('duplicates');
  } catch (e) { toast(e.message, 'err'); }
  cu.busy = false;
  cuFilter('blurry'); cuFilter('exposure'); cuFilter('small');
  cuRender();
}

// A photo replaced by Fix blur or Enlarge has new pixels: Clean up measures it again (and lists
// it again if it still qualifies) the next time it opens.
function cuReplaced(row) {
  ['blurry', 'exposure', 'small'].forEach(k => {
    cu.lists[k] = cu.lists[k].filter(r => r.id !== row.id);
    cu.sel[k].delete(row.id);
    cuFilter(k);
  });
  if ($('#cleanupDlg').open) cuRender();
}

// The lightbox shows the clicked page's photos; its own Delete is hidden while it does, so
// everything that deletes from here goes through the review screen's selection.
function cuOpenLightbox(items, idx) {
  if (!cu.lbSaved) cu.lbSaved = lastResults;
  lastResults = items;
  $('#lightbox').classList.add('from-cleanup');
  openLightbox(idx);
}
$('#lightbox').addEventListener('close', () => {
  if (!cu.lbSaved) return;
  lastResults = cu.lbSaved;
  cu.lbSaved = null;
  $('#lightbox').classList.remove('from-cleanup');
});
window.addEventListener('keydown', e => {
  if (cu.lbSaved && $('#lightbox').open && e.key === 'Delete') { e.preventDefault(); e.stopPropagation(); }
}, true);

// ---- Fix blur and Enlarge (lib/Deblur.php) ----
// One photo at a time. The work runs in the background on the server (a job keeps going when this
// window is closed); the window polls deblur_status while something is running. The window has two
// uses (db.op): Fix blur (on this PC; cloud AI; and both, the cloud AI finishing the on-PC result)
// and Enlarge (on this PC; cloud AI). Two views: side by side (the original and every result; zoom
// and pan together) and a slider (the original and the picked result on top of each other).
// Save / Replace act on the picked result ("Use this").
const DB_KIND = {
  local:    { op: 'deblur',  label: 'On this PC',                     who: 'the result from this PC' },
  cloud:    { op: 'deblur',  label: 'Cloud AI (redrawn)',             who: 'the cloud AI result', cloud: true },
  chain:    { op: 'deblur',  label: 'On this PC, then cloud AI',      who: 'the two-step result', cloud: true },
  up_local: { op: 'enlarge', label: 'Enlarged on this PC',            who: 'the enlargement from this PC' },
  up_cloud: { op: 'enlarge', label: 'Enlarged by cloud AI (redrawn)', who: 'the cloud AI enlargement', cloud: true },
  color:    { op: '',        label: 'Colour corrected',               who: 'the colour-corrected version' },   // from a batch
  up_local_2x: { op: '',     label: 'Enlarged 2×',                    who: 'the 2× enlargement' },             // from a batch
  fixed:    { op: '',        label: 'All fixes',                      who: 'the all-fixes version' },          // from a batch
  fixed_2x: { op: '',        label: 'All fixes, 2×',                  who: 'the all-fixes version at 2×' },    // from a batch
};
const DB_OP = {
  deblur: { title: 'Fix blur', local: 'local', cloud: 'cloud', chain: 'chain', verb: 'Fixing',
    hint: 'On this PC suits camera shake and motion blur. Cloud AI (Google Gemini) can also help out-of-focus photos, but it redraws the picture, so check faces and text. "Cloud AI on the on-PC result" lets the cloud AI finish what this PC started. Every version is saved next to the original as it finishes; "Replace the original" puts the one you pick in its place and moves the original to the Recycle Bin.' },
  // Compare: every version the photo has (a batch's, or made one at a time), nothing to start.
  review: { title: 'Compare', local: '', cloud: '', chain: '', verb: '',
    hint: 'Every version of this photo, each saved as a copy next to the original. Zoom in (2×, 4×) to compare. Pick the best ("Use this") and click "Replace the original" to put it in the original\'s place; the original goes to the Recycle Bin.' },
  enlarge: { title: 'Enlarge', local: 'up_local', cloud: 'up_cloud', chain: '', verb: 'Enlarging',
    hint: 'On this PC sharpens edges as it enlarges and adds nothing that was not there; busy textures can look a little smooth. Cloud AI (Google Gemini) redraws the photo at 2K with more fine detail, so check faces and text. Zoom in (2×, 4×) to compare. Every version is saved next to the original as it finishes; "Replace the original" puts the one you pick in its place and moves the original to the Recycle Bin.' },
};
const DB_ENLARGE_MAX = <?= (int)Deblur::ENLARGE_MAX_SIDE ?>;   // Deblur::enlargeInfo() decides; this only hides the viewer's button
const db = { op: 'deblur', row: null, show: '', showing: '', timer: null, s: null, wantLocal: false, armed: '', pending: new Set(),
             shown: '', replaceArmed: false, finished: '', view: 'grid', zoom: 1 };
// The kinds a window shows: its own, plus a batch's colour and all-fixes versions when there are
// any; Compare shows every kind that has a version.
const dbKinds = () => Object.keys(DB_KIND).filter(k => {
  const has = !!(db.s && db.s[k] && db.s[k].state !== 'none');
  return db.op === 'review' ? has : DB_KIND[k].op === db.op || (DB_KIND[k].op === '' && has);
});
const dbPane = k => document.querySelector('#dbGrid .db-pane[data-kind="' + k + '"]');

function dbFmtSecs(s) {
  s = Math.max(0, Math.round(s));
  return s < 60 ? s + ' s' : Math.floor(s / 60) + ' min' + (s % 60 >= 30 ? ' 30 s' : '');
}
function dbStatus(text, err = false, done = false) {
  const el = $('#dbStatus');
  el.textContent = text;
  el.classList.toggle('err', err);
  el.classList.toggle('done', done);
}
function dbResultUrl(kind, stamp) {
  return 'api.php?action=deblur_image&id=' + encodeURIComponent(db.row.id) + '&kind=' + kind + '&t=' + encodeURIComponent(stamp);
}
// Each pane's caption gives the picture's size as shown (the browser applies EXIF orientation).
function dbCaption(img, label) {
  const cap = img.closest('figure').querySelector('.db-cap');
  cap.textContent = label;
  img.onload = () => { cap.textContent = label + ' · ' + img.naturalWidth + '×' + img.naturalHeight; };
}
function dbSetOrig(row) {
  const orig = 'file.php?id=' + encodeURIComponent(row.id) + (row.v ? '&v=' + row.v : '');
  $('#dbBefore').src = orig;
  const img = $('#dbPaneOrig img');
  dbCaption(img, 'Original');
  img.src = orig;
}
function dbOpen(row, op = 'deblur') {
  if (!row || !row.id || !DB_OP[op]) return;
  const o = DB_OP[op];
  clearTimeout(db.replaceTimer);
  Object.assign(db, { op, row, show: o.local, showing: '', s: null, wantLocal: false, armed: '', replaceArmed: false, replaceMsg: '', finished: '', shown: '', pending: new Set(), aiShown: false });
  $('#dbTitle').textContent = o.title;
  $('#dbClose').setAttribute('aria-label', 'Close ' + o.title);
  $('#dbHint').textContent = o.hint;
  $('#dbChainBtn').hidden = !o.chain;
  $('#dbLocalBtn').hidden = !o.local;
  $('#dbCloudBtn').hidden = !o.cloud;
  $('#dbName').textContent = cuBase(row.path);
  $('#dbName').title = row.path || '';
  dbSetOrig(row);
  Object.keys(DB_KIND).forEach(k => {
    const p = dbPane(k), img = p.querySelector('img');
    img.hidden = true; img.removeAttribute('src'); img.dataset.src = ''; p.hidden = true;
    p.querySelector('.db-pane-note').textContent = '';
  });
  dbSetAfter(null);
  $('#dbSlider').value = 50;
  dbSlide();
  dbSetZoom(1);
  dbSetView(db.view);
  dbStatus('');
  ['#dbLocalBtn', '#dbCloudBtn', '#dbChainBtn', '#dbSaveBtn', '#dbReplaceBtn'].forEach(b => { $(b).disabled = true; });
  $('#dbSaveBtn').hidden = true;
  $('#deblurDlg').showModal();
  dbRefresh(true);
}

// ---- views ----
function dbSetView(v) {
  db.view = v;
  $('#dbGrid').hidden = v !== 'grid';
  $('#dbCompare').hidden = v !== 'slider';
  $('#dbZoomBar').hidden = v !== 'grid';
  $('#dbViewGrid').setAttribute('aria-pressed', v === 'grid' ? 'true' : 'false');
  $('#dbViewSlider').setAttribute('aria-pressed', v === 'slider' ? 'true' : 'false');
}
$('#dbViewGrid').addEventListener('click', () => { dbSetView('grid'); dbRefresh(); });
$('#dbViewSlider').addEventListener('click', () => { dbSetView('slider'); dbRefresh(); });

// Side by side: all panes zoom together; moving the mouse over any pane moves the same spot into
// view in all of them, so one detail can be compared across the original and every result.
function dbSetZoom(z) {
  db.zoom = z;
  $$('#dbZoomBar button').forEach(b => b.setAttribute('aria-pressed', +b.dataset.zoom === z ? 'true' : 'false'));
  $$('#dbGrid .db-pane img').forEach(img => {
    img.style.transform = z > 1 ? `scale(${z})` : '';
    if (z === 1) img.style.transformOrigin = '50% 50%';
  });
}
$$('#dbZoomBar button').forEach(b => b.addEventListener('click', () => dbSetZoom(+b.dataset.zoom)));
$('#dbGrid').addEventListener('pointermove', e => {
  if (db.zoom === 1) return;
  const pane = e.target.closest('.db-pane');
  if (!pane) return;
  const r = pane.getBoundingClientRect();
  const x = Math.min(100, Math.max(0, (e.clientX - r.left) / r.width * 100));
  const y = Math.min(100, Math.max(0, (e.clientY - r.top) / r.height * 100));
  $$('#dbGrid .db-pane img').forEach(img => { img.style.transformOrigin = `${x}% ${y}%`; });
});
function dbPick(kind) {
  db.show = kind;
  db.replaceArmed = false;
  db.finished = '';
  dbRefresh();
}
$$('#dbGrid .db-pick').forEach(b => b.addEventListener('click', () => dbPick(b.dataset.kind)));

// Slider view.
function dbSetAfter(kind, stamp = '') {
  const img = $('#dbAfter');
  const on = !!kind;
  ['#dbAfter', '#dbLine', '#dbTagR', '#dbSlider'].forEach(s => { $(s).hidden = !on; });
  if (!on) { img.removeAttribute('src'); db.shown = ''; return; }
  const key = kind + '|' + stamp;
  if (db.shown === key) return;
  db.shown = key;
  $('#dbTagR').textContent = DB_KIND[kind].label;
  img.src = dbResultUrl(kind, stamp);
}
function dbSlide() {
  const v = +$('#dbSlider').value;
  $('#dbAfter').style.clipPath = `inset(0 0 0 ${v}%)`;
  $('#dbLine').style.left = v + '%';
}
$('#dbSlider').addEventListener('input', dbSlide);

// Which result the slider shows, when there is more than one.
function dbRenderWhich(done) {
  const box = $('#dbWhich');
  box.hidden = db.view !== 'slider' || done.length < 2;
  if (box.hidden) return;
  const key = done.join(',');
  if (box.dataset.kinds !== key) {
    box.dataset.kinds = key;
    box.textContent = '';
    done.forEach(k => {
      const b = document.createElement('button');
      b.type = 'button';
      b.dataset.kind = k;
      b.textContent = DB_KIND[k].label;
      b.addEventListener('click', () => dbPick(k));
      box.appendChild(b);
    });
  }
  box.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', b.dataset.kind === db.showing ? 'true' : 'false'));
}

function dbRunningNote(k, j) {
  if (DB_KIND[k].cloud) return 'Google Gemini is working: usually 10–40 seconds…';
  const verb = DB_OP[db.op].verb;
  return j.total ? `${verb} on this PC: part ${j.done} of ${j.total}` + (j.done ? `, about ${dbFmtSecs(j.eta || 0)} left` : '') : 'Starting…';
}

// A batch's AI pick (Deblur::aiPick), shown on the captions.
function dbAiMark(s, k) {
  const a = s && s.ai_pick;
  if (!a) return '';
  return (a.kind === k ? ' · ★ AI pick' : '') + ((a.distorted || []).includes(k) ? ' · ⚠ faces distorted (AI)' : '');
}
// Panes in a logical order: each fix, then the sizes, then the combined ones, then the cloud ones.
const DB_PANE_ORDER = ['local', 'color', 'up_local_2x', 'up_local', 'fixed_2x', 'fixed', 'cloud', 'chain', 'up_cloud'];
function dbRenderPanes(s) {
  const kinds = dbKinds();
  const shown = kinds.filter(k => s[k].state !== 'none');
  const n = 1 + shown.length;
  // Up to three side by side; four as two rows of two, so each stays big enough to judge.
  $('#dbGrid').style.gridTemplateColumns = `repeat(${n <= 3 ? n : n === 4 ? 2 : n <= 6 ? 3 : n <= 8 ? 4 : 5}, minmax(0, 1fr))`;
  $('#dbGrid').style.gridTemplateRows = n > 3 ? 'repeat(2, minmax(0, 1fr))' : '';
  for (const k of kinds) {
    const j = s[k], pane = dbPane(k);
    const img = pane.querySelector('img'), note = pane.querySelector('.db-pane-note'), pick = pane.querySelector('.db-pick');
    pane.hidden = j.state === 'none';
    pane.style.order = String(DB_PANE_ORDER.indexOf(k) + 1);   // the original (order 0) first, then the logical order
    if (j.state === 'done') {
      const src = dbResultUrl(k, String(j.updated || ''));
      if (img.dataset.src !== src) { dbCaption(img, (j.label || DB_KIND[k].label) + dbAiMark(s, k)); img.src = src; img.dataset.src = src; }
      img.hidden = false;
      note.textContent = j.saved ? '✓ Saved as “' + j.saved + '”' : j.save_error ? 'Not saved: ' + j.save_error : j.autosave ? 'Saving a copy…' : '';
    } else {
      img.hidden = true;
      img.removeAttribute('src');
      img.dataset.src = '';
      pane.querySelector('.db-cap').textContent = DB_KIND[k].label;
      note.textContent = j.state === 'running' ? dbRunningNote(k, j) : j.state === 'error' ? DB_KIND[k].label + ': ' + j.error : '';
    }
    pick.disabled = j.state !== 'done';
    pick.setAttribute('aria-pressed', db.showing === k ? 'true' : 'false');
    pick.textContent = db.showing === k ? 'Using this ✓' : 'Use this';
    pane.classList.toggle('picked', db.showing === k);
  }
}

async function dbRefresh(auto = false) {
  clearTimeout(db.timer);
  if (!$('#deblurDlg').open || !db.row) return;
  let s;
  try { s = await api('deblur_status', { qs: 'id=' + encodeURIComponent(db.row.id) }); }
  catch (e) { dbStatus(e.message, true); return; }
  db.s = s;
  const o = DB_OP[db.op], kinds = dbKinds(), st = s.setup;
  const none = { state: 'none' };
  const L = (o.local && s[o.local]) || none, C = (o.cloud && s[o.cloud]) || none, X = o.chain ? s[o.chain] : null;
  const ready = db.op === 'review' || (db.op === 'enlarge' ? s.up_ready : s.ready);
  const en = s.enlarge || { ok: true, cloud_ok: true, scale: 4 };
  const localOk = db.op !== 'enlarge' || en.ok;
  const cloudOk = db.op !== 'enlarge' || en.cloud_ok;
  const settingUp = !!(st && !st.done && !st.error);
  const setupMine = st && (st.which || 'deblur') === (db.op === 'enlarge' ? 'enlarge' : 'deblur');
  const running = kinds.filter(k => s[k].state === 'running');
  // A finished job saves its copy a moment later (the page keeps asking until it has).
  const saving = kinds.filter(k => s[k].state === 'done' && s[k].autosave && !s[k].saved && !s[k].save_error);
  const busy = settingUp || running.length > 0 || saving.length > 0;
  for (const k of kinds) {   // say so as each version is saved, and put it in the grid
    const j = s[k];   // (by kind: a job's start time changes once it reports its own)
    if (j.state === 'running' || saving.includes(k)) db.pending.add(k);
    else if (db.pending.delete(k) && j.saved) dbAnnounceSaved(db.row.id, j);
  }

  // Start on this PC straight away when it is set up and nothing has been tried yet (or when a
  // setup the user started from here has just finished).
  // Only when this photo has no results at all here (not when there is a batch result to compare).
  if (o.local && ready && localOk && L.state === 'none' && !running.length && (db.wantLocal || (auto && kinds.every(k => s[k].state === 'none')))) {
    db.wantLocal = false;
    return dbStart(o.local);
  }

  // The picked result (db.show stays picked while it is still being made), else the newest done.
  const done = kinds.filter(k => s[k].state === 'done');
  if (!db.aiShown && s.ai_pick) {   // once: open on the version the AI picked
    db.aiShown = true;
    if (done.includes(s.ai_pick.kind)) db.show = s.ai_pick.kind;
  }
  db.showing = done.includes(db.show) ? db.show : (done[done.length - 1] || '');
  dbRenderPanes(s);
  if (db.showing) dbSetAfter(db.showing, String(s[db.showing].updated || ''));
  else dbSetAfter(null);
  dbRenderWhich(done);

  // Status line and progress bar.
  const prog = $('#dbProg');
  prog.hidden = true;
  const picked = db.showing ? s[db.showing] : null;
  const errs = kinds.filter(k => s[k].state === 'error');
  const what = (st && st.which === 'enlarge') || (!st && db.op === 'enlarge') ? 'Enlarge' : 'Fix blur';
  if (db.replaceArmed && db.replaceMsg) {
    dbStatus(db.replaceMsg);   // while the red confirm button waits for its second click
  } else if (db.finished && !busy) {
    dbStatus(db.finished, false, true);   // "Replaced …" stays until something new starts
  } else if (settingUp) {
    const pct = st.total ? Math.floor(100 * st.got / st.total) : 0;
    dbStatus(st.status === 'installing' ? `Setting up ${what}: installing…`
      : `Setting up ${what} (one-time download of about ${Math.max(1, Math.round(st.total / 1048576))} MB): ${st.file || ''} ${pct}%`);
    prog.hidden = false; prog.value = pct;
  } else if (running.length && db.view === 'slider') {
    const k = running[0], j = s[k];
    if (DB_KIND[k].cloud) {
      dbStatus('Sending the photo to Google Gemini: usually 10–40 seconds…');
    } else {
      const pct = j.total ? Math.floor(100 * j.done / j.total) : 0;
      dbStatus(dbRunningNote(k, j) + (j.total ? '. You can close this window; it carries on.' : ''));
      prog.hidden = false; prog.value = pct;
    }
  } else if (!ready && setupMine && st.error) {
    dbStatus(`Setting up ${what} failed: ` + st.error, true);
  } else if (!localOk && !picked) {
    dbStatus(`This photo is already ${en.width}×${en.height}: Enlarge is for photos up to ${Number(en.max_side).toLocaleString()} px on the long side.`);
  } else if (picked && picked.saved && s.ai_pick && (s.ai_pick.kind === db.showing || s.ai_pick.kind === 'original')) {
    dbStatus((s.ai_pick.kind === 'original' ? 'AI pick: keep the original. ' : `AI pick: ${(picked.label || DB_KIND[db.showing].label).replace(/^Batch: /, '')}. `)
      + (s.ai_pick.reason || '') + ' Every version is saved next to the original; to put one in the original\'s place, pick it ("Use this") and click "Replace the original".');
  } else if (picked && picked.saved) {
    dbStatus('✓ ' + (done.length > 1 ? 'Every version is saved next to the original; this one as “' + picked.saved + '”.' : 'Saved as “' + picked.saved + '” next to the original.')
      + ' To put a version in the original\'s place, pick it ("Use this") and click "Replace the original".', false, true);
  } else if (picked) {
    const k = db.showing, dims = picked.width && picked.height ? `${picked.width}×${picked.height}, ` : '';
    dbStatus((k === 'chain'
      ? `Using the two-step result (fixed on this PC, then redrawn by Google Gemini in ${dbFmtSecs(picked.seconds || 0)}): check faces and text.`
      : DB_KIND[k].cloud
        ? `Using ${DB_KIND[k].who} (${dims}redrawn by Google Gemini in ${dbFmtSecs(picked.seconds || 0)}): check faces and text.`
        : `Using ${DB_KIND[k].who} (${dims}made in ${dbFmtSecs(picked.seconds || 0)}).`)
      + (picked.save_error ? ' It could not be saved automatically (' + picked.save_error + '): click "Save as a copy" to try again.'
        : picked.autosave ? ' Saving a copy next to the original…' : ' Click "Save as a copy" to keep it.'), !!picked.save_error);
  } else if (busy) {
    dbStatus('Working… the results appear above as they finish. You can close this window; it carries on.');
  } else if (errs.length) {
    dbStatus(DB_KIND[errs[0]].label + ': ' + s[errs[0]].error, true);
  } else if (!ready) {
    dbStatus(db.op === 'enlarge'
      ? `Enlarging on this PC needs a one-time download of about ${s.up_download_mb} MB (the AI model${s.ready ? '' : ' and the program that runs it'}). Photos never leave this PC.`
      : `Fixing on this PC needs a one-time download of about ${s.download_mb} MB (the AI model and the program that runs it). Photos never leave this PC.`);
  } else {
    dbStatus('');
  }

  // Buttons.
  const lb = $('#dbLocalBtn'), cb = $('#dbCloudBtn'), xb = $('#dbChainBtn');
  lb.disabled = busy || !localOk;
  if (db.op === 'enlarge') {
    lb.textContent = !ready ? (settingUp ? 'Setting up…' : `Set up enlarging (${s.up_download_mb} MB)`)
      : L.state === 'running' ? 'Enlarging…' : (L.state === 'done' || L.state === 'error') ? 'Enlarge again on this PC' : `Enlarge ${en.scale}× on this PC`;
  } else {
    lb.textContent = !ready ? (settingUp ? 'Setting up…' : `Set up on this PC (${s.download_mb} MB)`)
      : L.state === 'running' ? 'Fixing…' : (L.state === 'done' || L.state === 'error') ? 'Fix again on this PC' : 'Fix on this PC';
  }
  const cost = db.op === 'enlarge' ? s.up_cloud_cost : s.cloud_cost;
  cb.disabled = C.state === 'running' || !cloudOk;
  cb.title = cloudOk ? '' : 'The cloud AI draws at 2K (2048 px): this photo is already that large.';
  cb.dataset.kind = o.cloud;
  cb.dataset.label = C.state === 'running' ? 'Cloud AI working…'
    : `${db.op === 'enlarge' ? 'Enlarge with cloud AI' : 'Try cloud AI'}${C.state === 'done' ? ' again' : ''} (${cost})`;
  cb.textContent = db.armed === o.cloud && C.state !== 'running' ? 'Send to Google' : cb.dataset.label;
  if (X) {
    xb.disabled = X.state === 'running' || L.state !== 'done';
    xb.title = L.state === 'done' ? 'Send the result from this PC to Google Gemini to finish' : 'Fix it on this PC first';
    xb.dataset.kind = o.chain;
    xb.dataset.label = X.state === 'running' ? 'Cloud AI working…'
      : X.state === 'done' ? 'Cloud AI on the on-PC result again' : `Cloud AI on the on-PC result (${s.cloud_cost})`;
    xb.textContent = db.armed === o.chain && X.state !== 'running' ? 'Send to Google' : xb.dataset.label;
  }
  const sv = $('#dbSaveBtn');   // only for a result its job did not (or could not) save
  sv.hidden = !picked || !!picked.saved || (!!picked.autosave && !picked.save_error) || !!db.finished;
  sv.disabled = false;
  const rb = $('#dbReplaceBtn');
  rb.disabled = !picked || !!db.finished;
  rb.textContent = db.finished ? 'Replaced ✓' : db.replaceArmed ? 'Confirm: replace the original' : 'Replace the original';
  rb.classList.toggle('confirm-armed', db.replaceArmed && !db.finished);

  if (busy) db.timer = setTimeout(() => dbRefresh(), 1500);
}

// A saved version goes into the grid next to its original, as an Enhance copy does.
function dbAddToGrid(origId, row) {
  if (!row || !row.id || cu.lbSaved || !lastResults.some(x => x.id === origId)) return;
  lastResults = lastResults.filter(x => x.id !== row.id);
  lastResults.splice(lastResults.findIndex(x => x.id === origId) + 1, 0, row);
  if (document.querySelector('.card[data-id="' + origId + '"]')) renderResults(lastResults);
}
async function dbAnnounceSaved(origId, j) {
  toast('Saved “' + j.saved + '” next to the original.');
  if (!j.saved_id) return;
  try {
    const row = await api('image_info', { qs: 'id=' + encodeURIComponent(j.saved_id) });
    row.sort_date = row.taken_at || row.folder_year || null;
    row.v = row.mtime;
    dbAddToGrid(origId, row);
    setStats(await api('stats'));
  } catch (e) { }
}

async function dbStart(kind) {
  db.finished = '';
  db.replaceArmed = false;
  db.armed = '';
  const btn = DB_KIND[kind].cloud ? (kind === 'chain' ? $('#dbChainBtn') : $('#dbCloudBtn')) : $('#dbLocalBtn');
  btn.disabled = true;
  btn.textContent = 'Starting…';   // starting the background job takes a second
  try {
    await api('deblur_start', { body: { id: db.row.id, kind } });
    db.show = kind;
  } catch (e) { dbStatus(e.message, true); }
  dbRefresh();
}

$('#dbLocalBtn').addEventListener('click', async () => {
  const s = db.s;
  if (!s) return;
  const enlarge = db.op === 'enlarge';
  if (!(enlarge ? s.up_ready : s.ready)) {
    db.wantLocal = true;
    try { await api('deblur_setup', { body: { which: enlarge ? 'enlarge' : 'deblur' } }); } catch (e) { dbStatus(e.message, true); return; }
    return dbRefresh();
  }
  dbStart(DB_OP[db.op].local);
});
// Cloud AI buttons take two clicks: they send the photo to Google and cost money.
function dbCloudClick(kind) {
  const s = db.s;
  if (!s) return;
  if (!s.cloud_key) {
    dbStatus('Cloud AI needs your Google Gemini API key: add it in Settings > Fix blur.', true);
    return;
  }
  if (db.armed !== kind) {
    db.armed = kind;
    const [cost, model] = kind === 'up_cloud' ? [s.up_cloud_cost, s.up_cloud_model] : [s.cloud_cost, s.cloud_model];
    dbStatus((kind === 'chain' ? 'This sends the result from this PC to Google' : 'This sends the photo to Google')
      + ` and costs ${cost} (${model}). Click "Send to Google" to go ahead.`);
    [$('#dbCloudBtn'), $('#dbChainBtn')].forEach(b => { b.textContent = b.dataset.kind === kind ? 'Send to Google' : b.dataset.label; });
    return;
  }
  dbStart(kind);
}
$('#dbCloudBtn').addEventListener('click', () => dbCloudClick(DB_OP[db.op].cloud));
$('#dbChainBtn').addEventListener('click', () => dbCloudClick('chain'));

$('#dbSaveBtn').addEventListener('click', async () => {
  const id = db.row.id, kind = db.showing;
  if (!kind) return;
  $('#dbSaveBtn').disabled = true;
  try {
    const res = await api('deblur_save', { body: { id, kind } });
    dbAddToGrid(id, res.image);
    setStats(res.stats);
    toast('Saved “' + res.name + '” next to the original.');
  } catch (e) {
    dbStatus(e.message, true);
  }
  dbRefresh();
});
$('#dbReplaceBtn').addEventListener('click', async () => {
  const id = db.row.id, kind = db.showing, s = db.s;
  if (!kind || !s) return;
  if (!db.replaceArmed) {   // two clicks: the first turns the button red for 8 seconds
    db.replaceArmed = true;
    const j = s[kind] || {}, r = s.enlarge || {};   // the original's own size, read from the file
    const smaller = j.width && r.width && r.height && j.width * j.height < r.width * r.height * 0.9
      ? ` Note: this result (${j.width}×${j.height}) is smaller than the original (${r.width}×${r.height}).` : '';
    db.replaceMsg = 'Click the red button within 8 seconds to replace the original with ' + DB_KIND[kind].who
      + ': the original goes to the Recycle Bin (you can restore it from there) and this version takes its name, description and place'
      + (j.saved ? ' (its saved copy, “' + j.saved + '”, goes too, so there is no duplicate; the other versions stay).' : '.') + smaller;
    dbStatus(db.replaceMsg);
    const rb = $('#dbReplaceBtn');
    rb.textContent = 'Confirm: replace the original';
    rb.classList.add('confirm-armed');
    clearTimeout(db.replaceTimer);
    db.replaceTimer = setTimeout(() => { db.replaceArmed = false; dbRefresh(); }, 8000);
    return;
  }
  clearTimeout(db.replaceTimer);
  db.replaceArmed = false;
  $('#dbReplaceBtn').disabled = true;
  $('#dbSaveBtn').disabled = true;
  try {
    const res = await api('deblur_save', { body: { id, kind, mode: 'replace' } });
    const row = res.image;
    const i = lastResults.findIndex(x => x.id === id);
    if (i >= 0) {
      lastResults[i] = Object.assign({}, lastResults[i], row);
      if (document.querySelector('.card[data-id="' + id + '"]')) renderResults(lastResults);
      if ($('#lightbox').open && lbIndex === i) lbShowImage(lastResults[i]);
    }
    if (res.removed_copy) {
      lastResults = lastResults.filter(x => x.id !== res.removed_copy);
      if (document.querySelector('.card[data-id="' + id + '"]')) renderResults(lastResults);
    }
    db.row = Object.assign({}, db.row, row);
    dbSetOrig(db.row);
    setStats(res.stats);
    cuReplaced(db.row);
    db.finished = '✓ Replaced “' + res.name + '” with ' + DB_KIND[kind].who + '. The old version is in the Recycle Bin' + (res.removed_copy ? '; the other saved versions stay next to it.' : '.');
    toast('Replaced “' + res.name + '”. The old version is in the Recycle Bin.');
  } catch (e) {
    dbStatus(e.message, true);
  }
  dbRefresh();
});
$('#dbClose').addEventListener('click', () => $('#deblurDlg').close());
// Keys belong to this window while it is open (not to the photo view or Clean up behind it).
$('#deblurDlg').addEventListener('keydown', e => e.stopPropagation());
$('#deblurDlg').addEventListener('close', () => { clearTimeout(db.timer); });

// ---- Queue (lib/Deblur.php queue()): the batch, every Fix blur / Enlarge job, and describing ----
// The header button shows how many things are in progress (checked every 10 s; every 2 s while
// the Queue is open). Everything listed runs on the server and carries on with the page closed.
const QJ_KIND = { local: 'Fix blur on this PC', cloud: 'Fix blur with cloud AI', chain: 'Fix blur on this PC, then cloud AI',
                  up_local: 'Enlarge on this PC', up_cloud: 'Enlarge with cloud AI' };
const QB_STEP = { deblur: 'remove blur', color: 'correct colour', enlarge: 'enlarge small photos' };
const qs = { timer: null, stopArmed: false, lastBatch: '' };

function qRow(name, title, what, err, btnText, onClick) {
  const row = document.createElement('div');
  row.className = 'q-row' + (err ? ' err' : '');
  const a = document.createElement('span');
  a.className = 'q-name';
  a.textContent = name;
  a.title = title || name;
  const b = document.createElement('span');
  b.className = 'q-what';
  b.textContent = what;
  b.title = what;
  row.append(a, b);
  if (btnText) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = btnText;
    btn.setAttribute('aria-label', btnText + ': ' + name);
    btn.addEventListener('click', onClick);
    row.appendChild(btn);
  } else {
    row.appendChild(document.createElement('span'));
  }
  return row;
}

function qJobText(j) {
  const cloud = j.kind === 'cloud' || j.kind === 'chain' || j.kind === 'up_cloud';
  const what = QJ_KIND[j.kind] || j.kind;
  if (j.state === 'running') {
    return what + ': ' + (cloud ? 'Google Gemini is working…' : j.total ? `part ${j.done} of ${j.total}` + (j.done ? `, about ${dbFmtSecs(j.eta || 0)} left` : '') : 'starting…');
  }
  if (j.saved) return what + ': saved as “' + j.saved + '”';
  if (j.error) return what + ': failed: ' + j.error;
  if (j.active) return what + ': saving a copy…';
  return what + ': done';
}

function qBatchText(b) {
  const items = b.items || [];
  const c = { done: 0, skipped: 0, error: 0, cancelled: 0, queued: 0, running: 0 };
  items.forEach(i => { c[i.state] = (c[i.state] || 0) + 1; });
  const total = items.length, finished = c.done + c.skipped + c.error;
  const steps = (b.steps || []).map(s => QB_STEP[s] || s).join(', ');
  const cur = items.find(i => i.state === 'running');
  let line;
  if (b.state === 'running') {
    // Time left: the average of the photos finished so far, times those still to do.
    const secs = items.filter(i => ['done', 'skipped', 'error'].includes(i.state)).map(i => i.seconds || 0);
    const left = secs.length ? Math.round(secs.reduce((x, y) => x + y, 0) / secs.length * (c.queued + c.running)) : 0;
    line = b.cancelling ? 'Stopping…'
      : `Photo ${Math.min(finished + 1, total)} of ${total} (${steps})` + (cur ? `: ${cur.name}, ${cur.step || 'starting'}`
        + (cur.total ? ` (part ${cur.done} of ${cur.total})` : '') : '') + (left ? `. About ${dbFmtSecs(left)} left for the batch.` : '.');
  } else if (b.state === 'done') {
    line = `Finished (${steps}): ${c.done.toLocaleString()} fixed` + (c.skipped ? `, ${c.skipped} needed no change` : '') + (c.error ? `, ${c.error} failed` : '') + '.';
  } else {
    line = (b.note ? b.note + ' ' : '') + `Stopped (${steps}): ${c.done.toLocaleString()} fixed, ${(c.cancelled + c.queued).toLocaleString()} not done.`;
  }
  return { line, c, total, finished };
}

function qRender(d) {
  qBadge(d.active || 0);
  $('#qSub').textContent = d.active ? `${d.active} in progress` : 'Nothing in progress';
  const b = d.batch;
  $('#qBatch').hidden = !b;
  if (b) {
    const t = qBatchText(b);
    $('#qBatchLine').textContent = t.line;
    $('#qBatchProg').hidden = b.state !== 'running';
    $('#qBatchProg').value = t.total ? Math.round(100 * t.finished / t.total) : 0;
    $('#qBatchCounts').textContent = `${t.c.done.toLocaleString()} fixed · ${t.c.skipped} unchanged · ${t.c.error} failed · ${t.c.queued.toLocaleString()} waiting`
      + (b.already ? ` · ${b.already} left out (already fixed this way)` : '')
      + '. Every version is saved as a copy next to its original. "Review all results" shows every photo with its versions on one page, to choose which originals to replace; "Compare" shows one photo\'s versions large.';
    $('#qReviewBtn').hidden = !t.c.done;
    const stop = $('#qStopBtn');
    stop.hidden = b.state !== 'running' || !!b.cancelling;
    if (stop.hidden) qs.stopArmed = false;
    stop.textContent = qs.stopArmed ? 'Click again to stop' : 'Stop the batch';

    const list = $('#qBatchList');
    list.textContent = '';
    const order = { running: 0, error: 1, done: 2, skipped: 3, cancelled: 4, queued: 5 };
    const shown = (b.items || []).map((it, i) => ({ it, i })).filter(x => x.it.state !== 'queued')
      .sort((x, y) => (order[x.it.state] - order[y.it.state]) || (y.i - x.i)).slice(0, 200);
    shown.forEach(({ it }) => {
      const what = it.state === 'running' ? 'Working: ' + (it.step || 'starting') + (it.total ? ` (part ${it.done} of ${it.total})` : '')
        : it.state === 'done' ? (it.note || 'Saved.') + (it.ai ? (it.ai.error ? ' AI pick failed: ' + it.ai.error
          : ' AI pick: ' + (it.ai.kind === 'original' ? 'keep the original' : ((it.versions || []).find(v => v.kind === it.ai.kind) || { label: it.ai.kind }).label) + '. ' + (it.ai.reason || '')) : '')
        : it.state === 'skipped' ? 'Unchanged: ' + (it.note || '')
        : it.state === 'error' ? 'Failed: ' + (it.error || '')
        : 'Not done (stopped)';
      list.appendChild(qRow(it.name, it.path, what, it.state === 'error', it.state === 'done' ? 'Compare' : '',
        () => dbOpen({ id: it.id, path: it.path }, 'review')));
    });
    if (t.c.queued) {
      const more = document.createElement('div');
      more.className = 'q-line q-mute';
      more.textContent = `${t.c.queued.toLocaleString()} more waiting.`;
      list.appendChild(more);
    }
  }
  const jobs = $('#qJobs');
  jobs.textContent = '';
  (d.jobs || []).forEach(j => jobs.appendChild(qRow(j.name, j.path, qJobText(j), !!j.error && !j.saved, j.path ? 'Open' : '',
    () => dbOpen({ id: j.id, path: j.path }, j.kind.startsWith('up_') ? 'enlarge' : 'deblur'))));
  if (!jobs.childNodes.length) {
    jobs.innerHTML = '<div class="q-line q-mute"></div>';
    jobs.firstChild.textContent = 'Nothing in the last day. Fix blur and Enlarge (photo view, or Clean up) show up here while they work.';
  }
  const ix = d.indexing || {};
  $('#qIndex').textContent = ix.pending
    ? `${Number(ix.pending).toLocaleString()} photos waiting to be described` + (ix.running ? ' (in progress).' : ' (not running now).')
    : 'Nothing waiting: every photo is described.';
}

function qBadge(n) {
  const el = $('#queueBadge');
  el.hidden = !n;
  el.textContent = n ? String(n) : '';
  $('#queueBtn').setAttribute('aria-label', n ? `Queue, ${n} in progress` : 'Queue');
}

async function qTick() {
  clearTimeout(qs.timer);
  const open = $('#queueDlg').open;
  try {
    const d = await api('queue_status');
    if (open) qRender(d); else qBadge(d.active || 0);
    // Say when a batch finishes while the page is open.
    const b = d.batch, key = b ? b.id + '|' + b.state : '';
    if (b && qs.lastBatch === b.id + '|running' && b.state !== 'running') toast('Batch ' + (b.state === 'done' ? 'finished' : 'stopped') + ': ' + qBatchText(b).line);
    qs.lastBatch = key;
  } catch (e) { if (open) $('#qSub').textContent = e.message; }
  qs.timer = setTimeout(qTick, open ? 2000 : 10000);
}
function qOpen() {
  if (!$('#queueDlg').open) $('#queueDlg').showModal();
  qTick();
}
$('#queueBtn').addEventListener('click', qOpen);
$('#qClose').addEventListener('click', () => $('#queueDlg').close());
$('#queueDlg').addEventListener('keydown', e => e.stopPropagation());
$('#queueDlg').addEventListener('close', () => { qs.stopArmed = false; qTick(); });
$('#qStopBtn').addEventListener('click', async () => {
  if (!qs.stopArmed) { qs.stopArmed = true; $('#qStopBtn').textContent = 'Click again to stop'; return; }
  qs.stopArmed = false;
  try { await api('batch_cancel', { body: {} }); } catch (e) { toast(e.message, 'err'); }
  qTick();
});
setTimeout(qTick, 1500);

// ---- Batch review: every photo of the batch with its versions; choose, then replace ----
// Clicking a version chooses it to replace that photo's original (click the original to keep it).
// "Replace the originals" takes two clicks, the first turning it red for 8 seconds, as everywhere.
const rv = { page: 0, data: null, choices: new Map(), touched: new Set(), armed: false, timer: null, busy: false };
function rvThumb(id, v, label, kind, chosen, disabled, onClick) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'rv-pick';
  b.dataset.kind = kind;
  b.setAttribute('aria-pressed', chosen ? 'true' : 'false');
  b.disabled = disabled;
  const img = document.createElement('img');
  img.loading = 'lazy';
  img.alt = '';
  img.src = 'thumb.php?id=' + encodeURIComponent(id) + (v ? '&v=' + v : '');
  const cap = document.createElement('span');
  cap.textContent = label;
  b.append(img, cap);
  b.addEventListener('click', onClick);
  return b;
}
function rvRender() {
  const d = rv.data, body = $('#rvBody');
  body.textContent = '';
  if (!d || !d.items.length) {
    body.innerHTML = '<div class="q-line q-mute"></div>';
    body.firstChild.textContent = 'No finished photos in this batch yet.';
  }
  (d ? d.items : []).forEach(it => {
    const row = document.createElement('div');
    row.className = 'rv-row';
    const head = document.createElement('div');
    head.className = 'rv-head';
    const name = document.createElement('span');
    name.className = 'rv-name';
    name.textContent = it.name;
    name.title = it.path;
    head.appendChild(name);
    const done = it.replaced;
    if (done) {
      const ok = document.createElement('span');
      ok.className = 'rv-done';
      ok.textContent = '✓ Replaced with the ' + (it.versions.find(v => v.kind === done.kind) || { label: 'chosen' }).label.toLowerCase() + ' version';
      head.appendChild(ok);
    } else {
      const cmp = document.createElement('button');
      cmp.type = 'button';
      cmp.textContent = 'Compare large';
      cmp.setAttribute('aria-label', 'Compare large: ' + it.name);
      cmp.addEventListener('click', () => dbOpen({ id: it.id, path: it.path }, 'review'));
      head.appendChild(cmp);
    }
    const thumbs = document.createElement('div');
    thumbs.className = 'rv-thumbs';
    const pick = kind => { rv.touched.add(it.id); if (kind) rv.choices.set(it.id, kind); else rv.choices.delete(it.id); rvDisarm(); rvRender(); };
    const chosen = rv.choices.get(it.id) || '';
    const ai = it.ai && !it.ai.error ? it.ai : null;
    const mark = kind => (ai && ai.kind === kind ? ' ★ AI pick' : '') + (ai && (ai.distorted || []).includes(kind) ? ' ⚠ faces distorted (AI)' : '');
    thumbs.appendChild(rvThumb(it.id, it.v, done ? 'Now: ' + done.name : 'Original' + (it.width ? ` · ${it.width}×${it.height}` : '') + (chosen ? '' : ' · keep'),
      '', !chosen && !done, !!done, () => pick('')));
    it.versions.forEach(v => thumbs.appendChild(rvThumb(v.saved_id, v.v, v.label + (v.width ? ` · ${v.width}×${v.height}` : '') + (v.exists ? '' : ' (copy gone)') + mark(v.kind),
      v.kind, chosen === v.kind, !!done || !v.exists, () => pick(v.kind))));
    row.append(head, thumbs);
    if (it.ai) {
      const why = document.createElement('div');
      why.className = 'q-line q-mute';
      why.textContent = it.ai.error ? 'AI pick failed: ' + it.ai.error
        : (it.ai.kind === 'original' ? 'AI pick: keep the original. ' : 'AI pick (chosen for you unless you change it): ') + (it.ai.reason || '');
      row.appendChild(why);
    }
    body.appendChild(row);
  });
  const pages = d ? Math.max(1, Math.ceil(d.total / d.per)) : 1;
  $('#rvPage').textContent = d ? `Page ${d.page + 1} of ${pages} (${d.total.toLocaleString()} photos)` : '';
  $('#rvPrev').disabled = !d || d.page <= 0;
  $('#rvNext').disabled = !d || d.page >= pages - 1;
  $('#rvSub').textContent = d ? (d.replaced ? `${d.replaced.toLocaleString()} replaced so far. ` : '') + 'Click a version to choose it for that photo; click the original to keep it.' : '';
  rvFoot();
}
function rvFoot() {
  const n = rv.choices.size, b = $('#rvReplace');
  $('#rvChosen').textContent = n ? `${n.toLocaleString()} ${n === 1 ? 'original' : 'originals'} to replace` : 'Nothing chosen';
  b.disabled = !n || rv.busy;
  $('#rvClear').disabled = !n || rv.busy;
  b.classList.toggle('confirm-armed', rv.armed);
  b.textContent = rv.armed ? `Confirm: replace ${n} ${n === 1 ? 'original' : 'originals'}` : (n > 1 ? `Replace ${n} originals` : 'Replace the original');
}
function rvDisarm() { clearTimeout(rv.timer); rv.armed = false; rvFoot(); }
async function rvLoad(page) {
  try {
    rv.data = await api('batch_review', { qs: 'page=' + page });
    // The AI's picks start out chosen; whatever the user clicks wins.
    rv.data.items.forEach(it => {
      if (rv.touched.has(it.id) || it.replaced || !it.ai || it.ai.error || it.ai.kind === 'original') return;
      if (it.versions.some(v => v.kind === it.ai.kind && v.exists)) rv.choices.set(it.id, it.ai.kind);
    });
    rv.page = rv.data.page;
  } catch (e) { $('#rvSub').textContent = e.message; }
  rvRender();
  $('#rvBody').scrollTop = 0;
}
function rvOpen() {
  rv.choices.clear();
  rv.touched = new Set();
  rvDisarm();
  $('#reviewDlg').showModal();
  rvLoad(0);
}
$('#rvReplace').addEventListener('click', async () => {
  const n = rv.choices.size;
  if (!n || rv.busy) return;
  if (!rv.armed) {
    rv.armed = true;
    rvFoot();
    $('#rvSub').textContent = `Click the red button within 8 seconds to replace ${n} ${n === 1 ? 'original' : 'originals'} with the chosen versions: each original goes to the Recycle Bin (you can restore it from there) and the version takes its name, description and place.`;
    rv.timer = setTimeout(() => { rv.armed = false; rvRender(); }, 8000);
    return;
  }
  rvDisarm();
  rv.busy = true;
  rvFoot();
  const all = [...rv.choices].map(([id, kind]) => ({ id, kind }));
  let ok = 0, changed = false;
  const failed = [];
  for (let i = 0; i < all.length; i += 5) {
    $('#rvChosen').textContent = `Replacing ${Math.min(i + 5, all.length)} of ${all.length}…`;
    try {
      const r = await api('batch_replace', { body: { choices: all.slice(i, i + 5) } });
      r.results.forEach(x => {
        if (!x.ok) { failed.push(x); return; }
        ok++;
        rv.choices.delete(x.id);
        const at = lastResults.findIndex(y => y.id === x.id);
        if (at >= 0) { Object.assign(lastResults[at], x.image); changed = true; }
        if (x.removed_copy) { lastResults = lastResults.filter(y => y.id !== x.removed_copy); changed = true; }
      });
      if (r.stats) setStats(r.stats);
    } catch (e) { failed.push({ error: e.message }); break; }
  }
  rv.busy = false;
  if (changed) renderResults(lastResults);
  toast(`Replaced ${ok} ${ok === 1 ? 'original' : 'originals'}; the old versions are in the Recycle Bin.` + (failed.length ? ` ${failed.length} failed: ${failed[0].error}` : ''), failed.length ? 'err' : 'ok', 8000);
  await rvLoad(rv.page);
});
$('#rvClear').addEventListener('click', () => { rv.choices.clear(); rvDisarm(); rvRender(); });
$('#rvPrev').addEventListener('click', () => { rvDisarm(); rvLoad(rv.page - 1); });
$('#rvNext').addEventListener('click', () => { rvDisarm(); rvLoad(rv.page + 1); });
$('#rvClose').addEventListener('click', () => $('#reviewDlg').close());
$('#reviewDlg').addEventListener('keydown', e => e.stopPropagation());
$('#reviewDlg').addEventListener('close', () => { rvDisarm(); });
$('#qReviewBtn').addEventListener('click', rvOpen);

// ---- Clean up: fix the selected photos in a batch ----
const B_DEFAULT = { blurry: ['deblur'], exposure: ['color'], small: ['enlarge'] };
const bs = { src: 'cu' };   // where the selection comes from: Clean up's list ('cu') or the main grid ('grid')
function bSelected() {
  const ids = bs.src === 'grid' ? [...selectedIds] : [...cu.sel[cu.tab]];
  const rows = new Map((bs.src === 'grid' ? lastResults : (cu.lists[cu.tab] || [])).map(r => [r.id, r]));
  return { ids, rows: ids.map(id => rows.get(id)).filter(Boolean) };
}
// A rough time from each photo's size: on this PC a blur fix takes about 6 s per 448 px tile,
// an enlargement about 0.25 s per 112 px tile, colour a few seconds.
function bEstimate() {
  const { ids, rows } = bSelected();
  let s = 0;
  rows.forEach(r => {
    const w = r.width || 1600, h = r.height || 1200;
    if ($('#bDeblur').checked) s += Math.ceil(w / 448) * Math.ceil(h / 448) * 6;
    const many = ['#bDeblur', '#bColor', '#bEnlarge'].filter(x => $(x).checked).length > 1;   // each on its own, and all together
    if ($('#bColor').checked) s += many ? 6 : 3;
    if ($('#bEnlarge').checked && Math.max(w, h) <= DB_ENLARGE_MAX) s += Math.ceil(w / 112) * Math.ceil(h / 112) * 0.25 * (many ? 2 : 1);
  });
  s += (ids.length - rows.length) * 30;
  const ticked = ['#bDeblur', '#bColor', '#bEnlarge'].filter(x => $(x).checked).length;
  $('#bAll').checked = ticked === 3;
  $('#bAll').indeterminate = ticked > 0 && ticked < 3;
  const any = ticked > 0;
  $('#bEstimate').textContent = any ? 'Rough time on this PC: ' + (s < 90 ? 'about a minute.' : s < 5400 ? `about ${Math.round(s / 60)} minutes.` : `about ${(s / 3600).toFixed(1)} hours.`) : 'Tick at least one fix.';
  $('#bStart').disabled = !any;
  // The AI pick: one Claude Haiku request per photo, about $0.002 (images ~1,000 tokens each at $0.10 per million).
  if ($('#bAi').checked && !$('#bAi').disabled && ids.length) {
    $('#bEstimate').textContent += ` AI pick: about $${Math.max(0.01, ids.length * 0.002).toFixed(2)} for ${ids.length.toLocaleString()} ${ids.length === 1 ? 'photo' : 'photos'}, billed to your Anthropic account.`;
  }
}
function bOpen(src) {
  bs.src = src;
  const { ids } = bSelected();
  if (!ids.length) return;
  const def = src === 'cu' ? (B_DEFAULT[cu.tab] || []) : [];
  $('#bDeblur').checked = def.includes('deblur');
  $('#bColor').checked = def.includes('color');
  $('#bEnlarge').checked = def.includes('enlarge');
  $('#bCount').textContent = `${ids.length.toLocaleString()} ${ids.length === 1 ? 'photo' : 'photos'} selected. Fixes to make, in this order:`;
  $('#bAi').checked = false;
  $('#bAi').disabled = true;
  api('batch_ai_available').then(r => {
    $('#bAi').disabled = !r.available;
    $('#bAi').checked = !!r.available;
    $('#bAiNote').textContent = r.available
      ? '(Claude compares every version, checking close-ups of faces for distortion; uses the Claude key that describes your photos)'
      : '(needs Claude as the AI that describes your photos, with its API key: Settings)';
    bEstimate();
  }).catch(() => {});
  $('#bStatus').textContent = '';
  $('#bStart').disabled = false;
  bEstimate();
  $('#batchDlg').showModal();
}
['#bDeblur', '#bColor', '#bEnlarge', '#bAi'].forEach(s => $(s).addEventListener('change', bEstimate));
$('#bAll').addEventListener('change', () => {
  ['#bDeblur', '#bColor', '#bEnlarge'].forEach(s => { $(s).checked = $('#bAll').checked; });
  bEstimate();
});
$('#cuFixSelBtn').addEventListener('click', () => bOpen('cu'));
$('#selFixBtn').addEventListener('click', () => bOpen('grid'));
$('#bCancel').addEventListener('click', () => $('#batchDlg').close());
$('#batchDlg').addEventListener('keydown', e => e.stopPropagation());
$('#bStart').addEventListener('click', async () => {
  const steps = [['#bDeblur', 'deblur'], ['#bColor', 'color'], ['#bEnlarge', 'enlarge']].filter(([s]) => $(s).checked).map(([, k]) => k);
  $('#bStart').disabled = true;
  $('#bStatus').textContent = '';
  try {
    await api('batch_start', { body: { ids: bSelected().ids, steps, ai: $('#bAi').checked && !$('#bAi').disabled } });
    $('#batchDlg').close();
    qOpen();
  } catch (e) {
    $('#bStatus').textContent = e.message;
    $('#bStart').disabled = false;
  }
});

// ---- Rotate (lib/Rotate.php): a quarter turn, lossless (only the EXIF Orientation tag changes) ----
let lbRotating = false;
async function lbRotate(dir) {
  if (lbIndex < 0 || lbRotating || !lastResults[lbIndex]) return;
  const r = lastResults[lbIndex];
  if (!/\.jpe?g$/i.test(r.path || '')) { toast('Rotating works for JPEG photos only.', 'err', 4000); return; }
  lbRotating = true;
  $('#lbRotL').disabled = $('#lbRotR').disabled = true;
  try {
    const res = await api('rotate', { body: { id: r.id, dir } });
    const row = Object.assign({}, r, res.image);
    lastResults[lbIndex] = row;
    lbShowImage(row);
    lbLoadDetails(row);   // its size as shown and its rotation changed
    const img = document.querySelector('.card[data-id="' + r.id + '"] .imgwrap img');
    if (img) img.src = 'thumb.php?id=' + encodeURIComponent(r.id) + '&v=' + row.v;
  } catch (e) {
    toast(e.message, 'err', 5000);
  } finally {
    lbRotating = false;
    $('#lbRotL').disabled = $('#lbRotR').disabled = false;
  }
}
$('#lbRotL').addEventListener('click', e => { e.stopPropagation(); lbRotate('ccw'); });
$('#lbRotR').addEventListener('click', e => { e.stopPropagation(); lbRotate('cw'); });

$('#lbFixBtn').addEventListener('click', e => {
  e.stopPropagation();
  if (lbIndex >= 0 && lastResults[lbIndex]) dbOpen(lastResults[lbIndex]);
});
$('#lbEnlBtn').addEventListener('click', e => {
  e.stopPropagation();
  if (lbIndex >= 0 && lastResults[lbIndex]) dbOpen(lastResults[lbIndex], 'enlarge');
});

$('#cleanupBtn').addEventListener('click', cuOpen);
$('#cuClose').addEventListener('click', () => $('#cleanupDlg').close());
$('#cleanupDlg').addEventListener('close', () => { cu.stop = true; cuDisarm(); });
$('#cuAnalyzeBtn').addEventListener('click', cuAnalyze);
$('#cuStopBtn').addEventListener('click', () => { cu.stop = true; $('#cuProgTxt').textContent = 'Stopping after this batch…'; });
$$('#cleanupDlg [data-cu-tab]').forEach(b => {
  b.addEventListener('click', () => cuSwitchTab(b.dataset.cuTab));
  b.addEventListener('keydown', e => {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    e.preventDefault();
    const i = CU_TABS.indexOf(cu.tab) + (e.key === 'ArrowRight' ? 1 : -1);
    cuSwitchTab(CU_TABS[(i + CU_TABS.length) % CU_TABS.length], true);
  });
});
$('#cuBlurRange').addEventListener('input', () => { cu.page.blurry = 0; cuFilter('blurry'); cuDisarm(); cuRender(); });
$('#cuBlurAi').addEventListener('change', () => { cu.page.blurry = 0; cuFilter('blurry'); cuDisarm(); cuRender(); });
$('#cuSmallSide').addEventListener('change', () => { cu.page.small = 0; cuFilter('small'); cuDisarm(); cuRender(); });
$('#cuDupMode').addEventListener('change', async () => {
  cu.page.duplicates = 0;
  $('#cuBody').innerHTML = '<div class="cu-empty">Looking for duplicates…</div>';
  try { await cuLoad('duplicates'); } catch (e) { toast(e.message, 'err'); }
  cuDisarm();
  cuRender();
});
$('#cuSelAll').addEventListener('click', () => cuSelectIds(cu.tab === 'duplicates' ? cuSelectable('all') : cuIds(cu.tab)));
$('#cuSelNone').addEventListener('click', () => cuSelectIds([]));
$('#cuSelExtra').addEventListener('click', () => cuSelectIds(cuSelectable('extra')));
$('#cuKeepBtn').addEventListener('click', cuKeep);
$('#cuDeleteBtn').addEventListener('click', () => {
  if (!cu.sel[cu.tab].size || cu.busy) return;
  if ($('#cuDeleteBtn').classList.contains('armed')) cuDelete(); else cuArm();
});
$('#cuDeleteCancel').addEventListener('click', cuDisarm);
$('#cuPrev').addEventListener('click', () => { cu.page[cu.tab]--; cuRender(); $('#cuBody').scrollTop = 0; });
$('#cuNext').addEventListener('click', () => { cu.page[cu.tab]++; cuRender(); $('#cuBody').scrollTop = 0; });
$('#cuUnkeepBtn').addEventListener('click', async () => {
  const b = $('#cuUnkeepBtn');
  if (!cu.unkeepArmed) {
    cu.unkeepArmed = true;
    b.textContent = 'Click again to show them again';
    setTimeout(() => { cu.unkeepArmed = false; b.textContent = 'Show kept photos again'; }, 8000);
    return;
  }
  cu.unkeepArmed = false;
  b.textContent = 'Show kept photos again';
  try {
    const r = await api('cleanup_unkeep_all', { body: {} });
    if (r.status) cuSetStatus(r.status);
    toast(`${cuPlural(r.changed, 'photo')} will be suggested again`);
    await cuLoadAll();
  } catch (e) { toast(e.message, 'err'); }
});
// A link to index.php#cleanup (or #cleanup-duplicates etc.) opens the review screen.
if (/^#cleanup\b/.test(location.hash)) {
  const t = location.hash.split('-')[1];
  if (CU_TABS.includes(t)) cuSwitchTab(t);
  cuOpen();
}
// ---- end Clean up ----
</script>
</body>
</html>
