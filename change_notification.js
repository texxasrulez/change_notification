// change_notification.js
// Force custom new-mail chime + safe single-play "Play Notification" preview (no double-trigger)

(function () {
  function onReady(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function getCustomUrl() {
    if (typeof window.rcmail === 'undefined' || !rcmail.env) return null;
    return rcmail.env.change_notification_url || null;
  }

  // ---- Interception logic (unchanged) ----
  function isSystemAudio(el){
    if (!el || el.tagName !== 'AUDIO') return false;
    var id = (el.id || '').toLowerCase();
    var cls = (el.className || '').toLowerCase();
    if (id.includes('sound') || id.includes('chime') || id.includes('notify') || id.includes('newmail')) return true;
    if (cls.includes('sound') || cls.includes('chime') || cls.includes('notify') || cls.includes('newmail')) return true;
    if (el.getAttribute && el.getAttribute('data-sound')) return true;
    var style = window.getComputedStyle ? getComputedStyle(el) : null;
    var hidden = (!el.controls) || (style && (style.display === 'none' || style.visibility === 'hidden' || parseFloat(style.opacity) === 0));
    if (hidden) return true;
    var rect = el.getBoundingClientRect ? el.getBoundingClientRect() : {width:0,height:0};
    if (rect.width < 4 && rect.height < 4) return true;
    var src = (el.currentSrc || el.src || '').toLowerCase();
    if (src.includes('/program/resources/') || src.includes('/skins/') || src.includes('/sounds/')) return true;
    return false;
  }

  function setAllSourcesTo(el, url){
    try {
      var changed = false;
      var sources = el.querySelectorAll ? el.querySelectorAll('source') : [];
      for (var i=0; i<sources.length; i++){
        if (sources[i].src !== url) { sources[i].src = url; changed = true; }
      }
      if (el.src !== url) { el.src = url; changed = true; }
      if (changed && typeof el.load === 'function') el.load();
    } catch(e){}
  }

  function installMediaPlayPatch(){
    var custom = getCustomUrl();
    if (!custom) return;
    if (window.__cs_media_play_patched) return;
    window.__cs_media_play_patched = true;

    var Proto = window.HTMLMediaElement && HTMLMediaElement.prototype;
    if (!Proto || typeof Proto.play !== 'function') return;

    var nativePlay = Proto.play;
    Proto.play = function(){
      try {
        if (this && this.tagName === 'AUDIO' && isSystemAudio(this)) {
          var curr = this.currentSrc || this.src || '';
          if (curr !== custom) setAllSourcesTo(this, custom);
        }
      } catch(e){}
      return nativePlay.apply(this, arguments);
    };
  }

  function installAudioConstructorPatch(){
    var custom = getCustomUrl();
    if (!custom) return;
    if (window.__cs_Audio_patched) return;
    window.__cs_Audio_patched = true;

    if (typeof window.Audio !== 'function') return;
    var NativeAudio = window.Audio;
    var PatchedAudio = function(src){
      var a = new NativeAudio();
      try {
        var s = (typeof src === 'string') ? src : '';
        var looksAsset = s.toLowerCase().includes('/program/resources/') || s.toLowerCase().includes('/skins/') || s.toLowerCase().includes('/sounds/');
        if (!s || looksAsset) setAllSourcesTo(a, custom);
        else setAllSourcesTo(a, s);
      } catch(e){}
      return a;
    };
    PatchedAudio.prototype = NativeAudio.prototype;
    try { Object.setPrototypeOf(PatchedAudio, NativeAudio); } catch(e){}
    window.Audio = PatchedAudio;
  }

  function observeNewAudioTags(){
    if (window.__cs_audio_mut_obs || !window.MutationObserver) return;
    window.__cs_audio_mut_obs = true;
    var mo = new MutationObserver(function(muts){
      for (var m of muts){
        for (var n of m.addedNodes){
          if (!n) continue;
          if (n.tagName === 'AUDIO') {
            if (!n.controls) n.setAttribute('data-sound','1');
          } else if (n.querySelectorAll) {
            var auds = n.querySelectorAll('audio');
            for (var i=0;i<auds.length;i++){ if (!auds[i].controls) auds[i].setAttribute('data-sound','1'); }
          }
        }
      }
    });
    mo.observe(document.documentElement || document.body, {childList:true, subtree:true});
  }

  function hookAjax(){
    if (!window.rcmail) return;
    var reinstall = function(){
      installMediaPlayPatch();
      installAudioConstructorPatch();
      safeBindPreview(); // rebind after any partial reloads
    };
    rcmail.addEventListener('init', reinstall);
    rcmail.addEventListener('responseafter', reinstall);
  }

  // ---- FIX: single-play preview binding ----
  function safeBindPreview() {
    var url = getCustomUrl();
    if (!url) return;

    // Find the preview link our PHP renders
    var link = document.querySelector('a[href*="_action=plugin.change_notification.get"]');
    if (!link) return;

    // Avoid binding twice
    if (link.dataset.csPreviewBound === '1') return;

    // If PHP added inline onclick that plays audio, remove it to prevent double-fire
    if (link.getAttribute('onclick')) {
      link.removeAttribute('onclick');
    }

    // Our single handler: prevent navigation and stop all other handlers
    var handler = function(ev){
      if (ev) {
        ev.preventDefault();
        // Stop any other listeners that might try to play as well
        if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
        else if (typeof ev.stopPropagation === 'function') ev.stopPropagation();
      }
      try {
        var a = new Audio(url);
        var p = a.play();
        if (p && typeof p.then === 'function') p.catch(function(){});
      } catch(e){}
      return false;
    };

    // Capture phase to pre-empt any theme/plugin listeners
    link.addEventListener('click', handler, {capture: true});
    link.dataset.csPreviewBound = '1';
  }

  // Debug helper
  function installDebug(){
    if (window.__cs_debug_installed) return;
    window.__cs_debug_installed = true;
    window.__cs_info = function(){
      return {
        customUrl: getCustomUrl(),
        audioConstructorPatched: !!window.__cs_Audio_patched,
        mediaPlayPatched: !!window.__cs_media_play_patched
      };
    };
    window.__cs_test = function(){
      try { var a = new Audio(); setTimeout(function(){ a.play().catch(function(){}); }, 0); return 'constructor-test fired'; }
      catch(e){ return 'error: ' + e; }
    };
  }

  onReady(function () {
    installMediaPlayPatch();
    installAudioConstructorPatch();
    observeNewAudioTags();
    hookAjax();
    safeBindPreview();
    installDebug();
  });
})();
