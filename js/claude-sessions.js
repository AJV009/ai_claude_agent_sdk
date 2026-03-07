/**
 * @file
 * Claude Sessions - session management dashboard.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.claudeSessions = {
    attach: function (context) {
      once('claude-sessions', '#claude-sessions-app', context).forEach(initSessions);
    }
  };

  function initSessions(container) {
    const { terminalUrl } = drupalSettings.claudeSessions;

    // Compute browser-facing sidecar base URL (DDEV exposes 3099/3100).
    const apiBase = (function () {
      const isHttps = location.protocol === 'https:';
      const protocol = isHttps ? 'https:' : 'http:';
      const port = isHttps ? 3100 : 3099;
      return protocol + '//' + location.hostname + ':' + port;
    })();

    let allSessions = [];
    let displayCount = 10;
    const PAGE_SIZE = 10;

    const header = document.createElement('div');
    header.className = 'claude-sessions-header';
    header.innerHTML = '<h3>Sessions</h3>';

    const refreshBtn = document.createElement('button');
    refreshBtn.type = 'button';
    refreshBtn.className = 'claude-sessions-refresh';
    refreshBtn.textContent = '\u21BB Refresh';
    refreshBtn.addEventListener('click', function () { loadAll(); });
    header.appendChild(refreshBtn);

    const list = document.createElement('div');
    list.id = 'claude-sessions-list';

    const footer = document.createElement('div');
    footer.id = 'claude-sessions-footer';

    container.appendChild(header);
    container.appendChild(list);
    container.appendChild(footer);

    async function loadAll() {
      list.innerHTML = '<div class="claude-sessions-empty">Loading sessions\u2026</div>';
      footer.innerHTML = '';

      try {
        const [sessionsRes, activeRes] = await Promise.all([
          fetch(apiBase + '/api/sessions?limit=200').then(function (r) { return r.json(); }),
          fetch(apiBase + '/api/sessions/active').then(function (r) { return r.json(); }),
        ]);
        const activeIds = new Set((activeRes.active || []).map(function (a) { return a.sessionId; }));
        allSessions = (sessionsRes.sessions || []).map(function (s) {
          return Object.assign({}, s, { isActive: activeIds.has(s.sessionId) });
        });
        displayCount = PAGE_SIZE;
        renderList();
      }
      catch (e) {
        list.innerHTML = '<div class="claude-sessions-empty">Unable to load sessions. Check sidecar connection.</div>';
        footer.innerHTML = '';
      }
    }

    function renderList() {
      list.innerHTML = '';
      if (allSessions.length === 0) {
        list.innerHTML = '<div class="claude-sessions-empty">No sessions yet. <a href="' + esc(terminalUrl) + '">Open the terminal</a> to start one.</div>';
        footer.innerHTML = '';
        return;
      }

      var visible = allSessions.slice(0, displayCount);
      visible.forEach(function (s) { list.appendChild(buildCard(s)); });

      footer.innerHTML = '';
      var count = document.createElement('span');
      count.className = 'claude-sessions-count';
      count.textContent = 'Showing ' + visible.length + ' of ' + allSessions.length + ' sessions';
      footer.appendChild(count);

      if (displayCount < allSessions.length) {
        var moreBtn = document.createElement('button');
        moreBtn.type = 'button';
        moreBtn.className = 'claude-sessions-load-more';
        moreBtn.textContent = 'Load More';
        moreBtn.addEventListener('click', function () {
          displayCount += PAGE_SIZE;
          renderList();
        });
        footer.appendChild(moreBtn);
      }
    }

    function buildCard(s) {
      var card = document.createElement('div');
      card.className = 'claude-session-card' + (s.isActive ? ' active' : '');

      // Header row: status dot + title + time.
      var cardHeader = document.createElement('div');
      cardHeader.className = 'card-header';

      var dot = document.createElement('span');
      dot.className = 'status-dot' + (s.isActive ? ' active' : '');
      dot.title = s.isActive ? 'Active' : 'Inactive';
      cardHeader.appendChild(dot);

      var title = document.createElement('span');
      title.className = 'title';
      title.textContent = s.summary || s.name || s.sessionId || 'Untitled Session';
      cardHeader.appendChild(title);

      var time = document.createElement('span');
      time.className = 'time';
      time.textContent = timeAgo(s.lastModified || s.createdAt || s.updatedAt);
      cardHeader.appendChild(time);

      card.appendChild(cardHeader);

      // Meta line.
      var metaParts = [];
      if (s.gitBranch || s.branch) {
        metaParts.push(esc(s.gitBranch || s.branch));
      }
      if (s.fileSize) {
        metaParts.push(formatBytes(s.fileSize));
      }
      if (s.messageCount !== undefined) {
        metaParts.push(s.messageCount + ' messages');
      }
      if (metaParts.length > 0) {
        var meta = document.createElement('div');
        meta.className = 'meta';
        meta.textContent = metaParts.join(' \u00B7 ');
        card.appendChild(meta);
      }

      // First prompt preview.
      if (s.firstPrompt) {
        var prompt = document.createElement('div');
        prompt.className = 'first-prompt';
        var truncated = s.firstPrompt.length > 120 ? s.firstPrompt.substring(0, 120) + '\u2026' : s.firstPrompt;
        prompt.textContent = truncated;
        card.appendChild(prompt);
      }

      // Actions.
      var actions = document.createElement('div');
      actions.className = 'actions';

      var msgBtn = document.createElement('button');
      msgBtn.type = 'button';
      msgBtn.className = 'btn-messages';
      msgBtn.textContent = 'View Messages';
      var messagesPanel = null;
      msgBtn.addEventListener('click', function () {
        if (messagesPanel) {
          messagesPanel.remove();
          messagesPanel = null;
          msgBtn.textContent = 'View Messages';
          return;
        }
        messagesPanel = document.createElement('div');
        messagesPanel.className = 'messages-panel';
        messagesPanel.innerHTML = '<div class="claude-sessions-empty">Loading\u2026</div>';
        card.appendChild(messagesPanel);
        msgBtn.textContent = 'Hide Messages';
        loadMessages(s.sessionId, messagesPanel);
      });
      actions.appendChild(msgBtn);

      var termBtn = document.createElement('a');
      termBtn.className = 'btn-terminal';
      termBtn.href = terminalUrl + '#session=' + encodeURIComponent(s.sessionId);
      termBtn.textContent = 'Open Terminal';
      actions.appendChild(termBtn);

      card.appendChild(actions);

      return card;
    }

    async function loadMessages(sessionId, panel) {
      try {
        var res = await fetch(apiBase + '/api/sessions/' + encodeURIComponent(sessionId));
        var data = await res.json();
        var messages = data.messages || data.session?.messages || [];
        if (messages.length === 0) {
          panel.innerHTML = '<div class="claude-sessions-empty">No messages in this session.</div>';
          return;
        }
        panel.innerHTML = '';
        messages.forEach(function (msg) {
          // Unwrap: API returns { type, message: { role, content } }.
          var inner = msg.message || msg;
          var msgEl = document.createElement('div');
          var role = inner.role || msg.type || 'unknown';
          msgEl.className = 'msg msg-' + esc(role);

          var roleSpan = document.createElement('span');
          roleSpan.className = 'msg-role';
          roleSpan.textContent = role;
          msgEl.appendChild(roleSpan);

          var rawContent = inner.content;
          var content = '';
          if (typeof rawContent === 'string') {
            content = rawContent;
          }
          else if (Array.isArray(rawContent)) {
            content = rawContent
              .filter(function (b) { return b.type === 'text'; })
              .map(function (b) { return b.text; })
              .join('\n');
          }
          var textSpan = document.createElement('span');
          textSpan.className = 'msg-text';
          textSpan.textContent = content.length > 500 ? content.substring(0, 500) + '\u2026' : content;
          msgEl.appendChild(textSpan);

          panel.appendChild(msgEl);
        });
      }
      catch (e) {
        panel.innerHTML = '<div class="claude-sessions-empty">Failed to load messages.</div>';
      }
    }

    function esc(str) {
      return Drupal.checkPlain(String(str || ''));
    }

    function timeAgo(ts) {
      if (!ts) {
        return '';
      }
      var seconds = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
      if (seconds < 60) {
        return 'just now';
      }
      var minutes = Math.floor(seconds / 60);
      if (minutes < 60) {
        return minutes + 'm ago';
      }
      var hours = Math.floor(minutes / 60);
      if (hours < 24) {
        return hours + 'h ago';
      }
      var days = Math.floor(hours / 24);
      return days + 'd ago';
    }

    function formatBytes(bytes) {
      if (!bytes || bytes === 0) {
        return '0 B';
      }
      var units = ['B', 'KB', 'MB', 'GB'];
      var i = Math.floor(Math.log(bytes) / Math.log(1024));
      return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
    }

    loadAll();
  }

})(Drupal, drupalSettings, once);
