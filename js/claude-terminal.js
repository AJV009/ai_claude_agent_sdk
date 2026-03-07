/**
 * @file
 * Claude Terminal - xterm.js WebSocket client with toolbar controls.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  const CDN_BASE = 'https://esm.sh';
  const XTERM_VERSION = '5.5.0';
  const FIT_VERSION = '0.10.0';
  const WEBGL_VERSION = '0.18.0';

  let initialized = false;

  Drupal.behaviors.claudeTerminal = {
    attach: function (context) {
      if (initialized) return;
      const el = document.getElementById('claude-terminal');
      if (el && context.contains(el)) {
        initialized = true;
        initTerminal(el);
      }
    }
  };

  async function loadXtermModules() {
    const [xtermModule, fitModule, webglModule] = await Promise.all([
      import(`${CDN_BASE}/@xterm/xterm@${XTERM_VERSION}`),
      import(`${CDN_BASE}/@xterm/addon-fit@${FIT_VERSION}`),
      import(`${CDN_BASE}/@xterm/addon-webgl@${WEBGL_VERSION}`).catch(() => null),
    ]);

    // Load xterm CSS.
    const linkId = 'xterm-css';
    if (!document.getElementById(linkId)) {
      const link = document.createElement('link');
      link.id = linkId;
      link.rel = 'stylesheet';
      link.href = `${CDN_BASE}/@xterm/xterm@${XTERM_VERSION}/css/xterm.css`;
      document.head.appendChild(link);
    }

    return { Terminal: xtermModule.Terminal, FitAddon: fitModule.FitAddon, WebglAddon: webglModule?.WebglAddon || null };
  }

  function timeAgo(timestamp) {
    const seconds = Math.floor((Date.now() - timestamp) / 1000);
    if (seconds < 60) return seconds + 's ago';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return minutes + 'm ago';
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return hours + 'h ago';
    const days = Math.floor(hours / 24);
    return days + 'd ago';
  }

  function readHash() {
    const params = {};
    const hash = window.location.hash.replace('#', '');
    hash.split('&').forEach(function (part) {
      const [key, val] = part.split('=');
      if (key && val) {
        params[decodeURIComponent(key)] = decodeURIComponent(val);
      }
    });
    return params;
  }

  function updateHash(profileId, sessionId) {
    let hash = 'profile=' + encodeURIComponent(profileId);
    if (sessionId) {
      hash += '&session=' + encodeURIComponent(sessionId);
    }
    history.replaceState(null, '', '#' + hash);
  }

  function buildToolbar(settings) {
    const toolbar = document.getElementById('claude-terminal-toolbar');
    if (!toolbar) return {};

    const profiles = settings.profiles || {};
    const defaultProfile = settings.defaultProfile || Object.keys(profiles)[0] || '';
    const hashParams = readHash();

    // Profile selector.
    const profileGroup = document.createElement('div');
    profileGroup.className = 'claude-toolbar-group';
    const profileLabel = document.createElement('label');
    profileLabel.textContent = 'Profile:';
    profileLabel.setAttribute('for', 'claude-profile-select');
    const profileSelect = document.createElement('select');
    profileSelect.id = 'claude-profile-select';
    Object.entries(profiles).forEach(function ([id, profile]) {
      const opt = document.createElement('option');
      opt.value = id;
      opt.textContent = Drupal.checkPlain(profile.label || id);
      if (id === (hashParams.profile || defaultProfile)) {
        opt.selected = true;
      }
      profileSelect.appendChild(opt);
    });
    if (profileSelect.options.length === 0) {
      const opt = document.createElement('option');
      opt.value = 'default';
      opt.textContent = 'Default';
      profileSelect.appendChild(opt);
    }
    profileGroup.appendChild(profileLabel);
    profileGroup.appendChild(profileSelect);

    // Session selector.
    const sessionGroup = document.createElement('div');
    sessionGroup.className = 'claude-toolbar-group';
    const sessionLabel = document.createElement('label');
    sessionLabel.textContent = 'Session:';
    sessionLabel.setAttribute('for', 'claude-session-select');
    const sessionSelect = document.createElement('select');
    sessionSelect.id = 'claude-session-select';
    const newOpt = document.createElement('option');
    newOpt.value = '';
    newOpt.textContent = 'New Session';
    sessionSelect.appendChild(newOpt);
    sessionGroup.appendChild(sessionLabel);
    sessionGroup.appendChild(sessionSelect);

    // Prompt input.
    const promptInput = document.createElement('input');
    promptInput.type = 'text';
    promptInput.id = 'claude-prompt-input';
    promptInput.placeholder = 'Enter prompt or /command...';

    // Trigger button.
    const triggerBtn = document.createElement('button');
    triggerBtn.type = 'button';
    triggerBtn.id = 'claude-trigger-btn';
    triggerBtn.className = 'claude-btn-start';
    triggerBtn.textContent = '\u25B6 Start';

    toolbar.appendChild(profileGroup);
    toolbar.appendChild(sessionGroup);
    toolbar.appendChild(promptInput);
    toolbar.appendChild(triggerBtn);

    return { profileSelect, sessionSelect, promptInput, triggerBtn };
  }

  async function loadSkills(apiBase) {
    if (!apiBase) return [];
    try {
      const resp = await fetch(apiBase + '/api/skills');
      if (!resp.ok) return [];
      const data = await resp.json();
      return data.skills || [];
    } catch (_) {
      return [];
    }
  }

  function buildSkillRow(skills, promptInput, wsGetter) {
    // Filter to user-invocable skills only.
    var invocable = skills.filter(function (s) { return s.userInvocable !== false; });
    if (invocable.length === 0) return;

    // Order: project-skill first, then user-skill.
    invocable.sort(function (a, b) {
      if (a.category === b.category) return 0;
      return a.category === 'project-skill' ? -1 : 1;
    });

    var row = document.createElement('div');
    row.id = 'claude-command-row';

    var MAX_BUTTONS = 6;
    var buttonSkills = invocable.slice(0, MAX_BUTTONS);
    var overflowSkills = invocable.slice(MAX_BUTTONS);

    function handleSkill(skill) {
      if (skill.hasArgs) {
        promptInput.value = '/' + skill.name + ' ';
        promptInput.focus();
        return;
      }
      var currentWs = wsGetter();
      if (currentWs && currentWs.readyState === WebSocket.OPEN) {
        currentWs.send('/' + skill.name + '\r');
      } else {
        promptInput.value = '/' + skill.name;
      }
    }

    buttonSkills.forEach(function (skill) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'claude-cmd-btn';
      btn.setAttribute('data-category', skill.category);
      btn.textContent = '/' + skill.name;
      var tooltip = skill.description || skill.name;
      if (skill.argumentHint) {
        tooltip = '/' + skill.name + ' ' + skill.argumentHint + ' - ' + tooltip;
      }
      btn.title = tooltip;
      btn.addEventListener('click', function () {
        handleSkill(skill);
      });
      row.appendChild(btn);
    });

    if (overflowSkills.length > 0) {
      var select = document.createElement('select');
      select.className = 'claude-cmd-more';
      var placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'More...';
      placeholder.disabled = true;
      placeholder.selected = true;
      select.appendChild(placeholder);

      overflowSkills.forEach(function (skill) {
        var opt = document.createElement('option');
        opt.value = skill.name;
        opt.textContent = '/' + skill.name;
        opt.title = skill.description;
        opt.setAttribute('data-category', skill.category);
        opt.setAttribute('data-has-args', skill.hasArgs ? '1' : '0');
        select.appendChild(opt);
      });

      select.addEventListener('change', function () {
        var name = select.value;
        var skill = overflowSkills.find(function (s) { return s.name === name; });
        if (skill) {
          handleSkill(skill);
        }
        select.selectedIndex = 0;
      });
      row.appendChild(select);
    }

    var toolbar = document.getElementById('claude-terminal-toolbar');
    if (toolbar) {
      toolbar.after(row);
    }
  }

  async function loadSessions(apiBase, sessionSelect, hashParams) {
    if (!apiBase || !sessionSelect) return;
    try {
      const resp = await fetch(apiBase + '/api/sessions?limit=30');
      if (!resp.ok) return;
      const sessions = await resp.json();
      // Clear existing options except "New Session".
      while (sessionSelect.options.length > 1) {
        sessionSelect.remove(1);
      }
      const list = Array.isArray(sessions) ? sessions : (sessions.sessions || []);
      list.forEach(function (session) {
        const label = session.customTitle || session.summary || (session.firstPrompt ? session.firstPrompt.substring(0, 50) : null) || (session.sessionId ? session.sessionId.substring(0, 8) : 'unknown');
        const timeStr = session.lastModified ? timeAgo(session.lastModified) : '';
        const opt = document.createElement('option');
        opt.value = session.sessionId || '';
        opt.textContent = label + (timeStr ? ' \u2014 ' + timeStr : '');
        if (hashParams && hashParams.session === opt.value) {
          opt.selected = true;
        }
        sessionSelect.appendChild(opt);
      });
    }
    catch (e) {
      // Silently leave just "New Session".
    }
  }

  async function initTerminal(container) {
    const settings = drupalSettings.claudeTerminal || {};
    const statusEl = document.getElementById('claude-terminal-status');
    const hashParams = readHash();

    // Compute browser-facing sidecar base URL (DDEV exposes 3099/3100).
    const browserApiBase = (function () {
      if (settings.wsUrl) {
        return settings.wsUrl.replace(/^wss:/, 'https:').replace(/^ws:/, 'http:').replace(/\/ws\/?$/, '');
      }
      const isHttps = location.protocol === 'https:';
      const protocol = isHttps ? 'https:' : 'http:';
      const port = isHttps ? 3100 : 3099;
      return protocol + '//' + location.hostname + ':' + port;
    })();

    // Build toolbar controls.
    const { profileSelect, sessionSelect, promptInput, triggerBtn } = buildToolbar(settings);

    // Load sessions async.
    loadSessions(browserApiBase, sessionSelect, hashParams);

    // Reload sessions on dropdown focus.
    if (sessionSelect) {
      sessionSelect.addEventListener('focus', function () {
        loadSessions(browserApiBase, sessionSelect);
      });
    }

    // Load and render skill buttons.
    const skills = await loadSkills(browserApiBase);
    if (skills.length > 0 && promptInput) {
      buildSkillRow(skills, promptInput, function () { return ws; });
    }

    const { Terminal, FitAddon, WebglAddon } = await loadXtermModules();

    const darkTheme = {
      background: '#1a1a2e',
      foreground: '#e0e0e0',
      cursor: '#e0e0e0',
      cursorAccent: '#1a1a2e',
      selectionBackground: '#3a3a5e',
    };

    const term = new Terminal({
      cursorBlink: true,
      fontSize: 14,
      fontFamily: 'Menlo, Monaco, "Courier New", monospace',
      theme: darkTheme,
      convertEol: true,
    });

    const fitAddon = new FitAddon();
    term.loadAddon(fitAddon);

    term.open(container);

    // Try WebGL addon, fall back to canvas.
    if (WebglAddon) {
      try {
        term.loadAddon(new WebglAddon());
      }
      catch (e) {
        // Canvas fallback - no action needed.
      }
    }

    fitAddon.fit();

    // Resize observer.
    const resizeObserver = new ResizeObserver(() => {
      fitAddon.fit();
      if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows }));
      }
    });
    resizeObserver.observe(container);

    // WebSocket state.
    let ws = null;
    let reconnectDelay = 1000;
    let intentionalClose = false;
    let connected = false;
    const MAX_RECONNECT_DELAY = 16000;

    function updateStatus(state, text) {
      if (statusEl) {
        statusEl.innerHTML = '<span class="claude-status-dot ' + state + '"></span> ' + Drupal.checkPlain(text);
      }
    }

    function updateTriggerButton() {
      if (!triggerBtn) return;
      if (!ws || ws.readyState === WebSocket.CLOSED || ws.readyState === WebSocket.CLOSING) {
        triggerBtn.className = 'claude-btn-start';
        triggerBtn.textContent = '\u25B6 Start';
        connected = false;
      }
      else if (ws.readyState === WebSocket.CONNECTING) {
        triggerBtn.className = 'claude-btn-connecting';
        triggerBtn.textContent = '\u27F3 ...';
      }
      else if (ws.readyState === WebSocket.OPEN) {
        connected = true;
        if (promptInput && promptInput.value.trim()) {
          triggerBtn.className = 'claude-btn-send';
          triggerBtn.textContent = '\u27A4 Send';
        }
        else {
          triggerBtn.className = 'claude-btn-idle';
          triggerBtn.textContent = '\u27A4 Send';
        }
      }
    }

    function getWsUrl() {
      if (settings.wsUrl) {
        return settings.wsUrl;
      }
      return browserApiBase.replace(/^https:/, 'wss:').replace(/^http:/, 'ws:') + '/ws';
    }

    function connectAndSpawn(profileId, sessionId, promptText) {
      // Close existing connection if any.
      if (ws) {
        intentionalClose = true;
        ws.close();
        ws = null;
      }

      intentionalClose = false;
      const wsUrl = getWsUrl();
      updateStatus('connecting', 'Connecting...');
      updateTriggerButton();

      try {
        ws = new WebSocket(wsUrl);
      }
      catch (e) {
        updateStatus('error', 'Connection failed');
        updateTriggerButton();
        return;
      }

      updateTriggerButton();

      ws.onopen = function () {
        reconnectDelay = 1000;
        updateStatus('connected', 'Connected');

        const profiles = settings.profiles || {};
        const profile = (profiles[profileId] && profiles[profileId].config) ? profiles[profileId].config : {};

        const spawnMsg = { type: 'spawn', profile: profile };
        if (settings.env) {
          spawnMsg.env = settings.env;
        }
        if (sessionId) {
          spawnMsg.resume = sessionId;
        }
        if (promptText) {
          spawnMsg.prompt = promptText;
        }
        ws.send(JSON.stringify(spawnMsg));

        // Send initial size.
        ws.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows }));

        // Clear input and update state.
        if (promptInput) {
          promptInput.value = '';
        }
        updateTriggerButton();
        updateHash(profileId, sessionId);
      };

      ws.onmessage = function (event) {
        const data = event.data;
        try {
          const msg = JSON.parse(data);
          if (msg.type === 'error') {
            updateStatus('error', 'Error: ' + (msg.message || 'Unknown'));
          }
          else if (msg.type === 'exit') {
            updateStatus('disconnected', 'Process exited (code ' + (msg.code ?? '?') + ')');
            intentionalClose = true;
            connected = false;
            updateTriggerButton();
          }
          else if (msg.type === 'spawned') {
            // Session started.
          }
          else if (msg.type === 'data') {
            term.write(msg.data);
          }
        }
        catch (e) {
          // Raw PTY data (non-JSON).
          term.write(data);
        }
      };

      ws.onclose = function () {
        updateStatus('disconnected', 'Disconnected');
        updateTriggerButton();
        if (!intentionalClose) {
          scheduleReconnect(profileId, sessionId);
        }
      };

      ws.onerror = function () {
        updateStatus('error', 'Connection error');
        updateTriggerButton();
      };
    }

    function scheduleReconnect(profileId, sessionId) {
      setTimeout(function () {
        reconnectDelay = Math.min(reconnectDelay * 2, MAX_RECONNECT_DELAY);
        connectAndSpawn(profileId, sessionId, '');
      }, reconnectDelay);
    }

    function handleTrigger() {
      const profileId = profileSelect ? profileSelect.value : 'default';
      const sessionId = sessionSelect ? sessionSelect.value : '';
      const promptText = promptInput ? promptInput.value.trim() : '';

      if (!connected || !ws || ws.readyState !== WebSocket.OPEN) {
        // Not connected - spawn.
        connectAndSpawn(profileId, sessionId, promptText);
      }
      else if (promptText) {
        // Connected with text - send to PTY.
        ws.send(promptText + '\r');
        promptInput.value = '';
        term.focus();
        updateTriggerButton();
      }
      // Connected + no text: do nothing.
    }

    // Wire up event listeners.
    if (promptInput) {
      promptInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          handleTrigger();
        }
      });
      promptInput.addEventListener('input', function () {
        updateTriggerButton();
      });
    }

    if (triggerBtn) {
      triggerBtn.addEventListener('click', function () {
        handleTrigger();
      });
    }

    // Forward terminal input to WebSocket.
    term.onData(function (data) {
      if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(data);
      }
    });

    // Show welcome message instead of auto-connecting.
    term.write('Select a profile and click Start to begin.\r\n');
  }

})(Drupal, drupalSettings, once);
