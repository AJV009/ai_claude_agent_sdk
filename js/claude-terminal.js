/**
 * @file
 * Claude Terminal - xterm.js WebSocket client.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  const CDN_BASE = 'https://esm.sh';
  const XTERM_VERSION = '5.5.0';
  const FIT_VERSION = '0.10.0';
  const WEBGL_VERSION = '0.18.0';

  Drupal.behaviors.claudeTerminal = {
    attach: function (context) {
      once('claude-terminal', '#claude-terminal', context).forEach(function (el) {
        initTerminal(el);
      });
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

  async function initTerminal(container) {
    const settings = drupalSettings.claudeTerminal || {};
    const statusEl = document.getElementById('claude-terminal-status');

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

    // WebSocket connection.
    let ws = null;
    let reconnectDelay = 1000;
    const MAX_RECONNECT_DELAY = 16000;

    function updateStatus(state, text) {
      if (statusEl) {
        statusEl.innerHTML = '<span class="claude-status-dot ' + state + '"></span> ' + Drupal.checkPlain(text);
      }
    }

    function getWsUrl() {
      if (settings.wsUrl) {
        return settings.wsUrl;
      }
      // DDEV convention: http_port 3099, https_port 3100.
      const isHttps = location.protocol === 'https:';
      const protocol = isHttps ? 'wss:' : 'ws:';
      const port = isHttps ? 3100 : 3099;
      return protocol + '//' + location.hostname + ':' + port + '/ws';
    }

    function connect() {
      const wsUrl = getWsUrl();
      updateStatus('connecting', 'Connecting...');

      try {
        ws = new WebSocket(wsUrl);
      }
      catch (e) {
        updateStatus('error', 'Connection failed');
        scheduleReconnect();
        return;
      }

      ws.onopen = function () {
        reconnectDelay = 1000;
        updateStatus('connected', 'Connected');
        ws.send(JSON.stringify({ type: 'spawn', profile: {} }));
        // Send initial size.
        ws.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows }));
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
          }
          else if (msg.type === 'spawned') {
            // Session started - no action needed.
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
        scheduleReconnect();
      };

      ws.onerror = function () {
        updateStatus('error', 'Connection error');
      };
    }

    function scheduleReconnect() {
      setTimeout(function () {
        reconnectDelay = Math.min(reconnectDelay * 2, MAX_RECONNECT_DELAY);
        connect();
      }, reconnectDelay);
    }

    // Forward terminal input to WebSocket.
    term.onData(function (data) {
      if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(data);
      }
    });

    connect();
  }

})(Drupal, drupalSettings, once);
