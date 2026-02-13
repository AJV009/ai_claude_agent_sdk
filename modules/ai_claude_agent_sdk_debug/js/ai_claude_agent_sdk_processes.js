(function (Drupal, once, drupalSettings) {
  'use strict';

  Drupal.behaviors.claudeAgentSdkProcesses = {
    attach: function (context) {
      once('ai-claude-agent-sdk-processes', '#claude-agent-sdk-processes-table', context).forEach(function (container) {
        if (container.dataset.aiClaudeAgentSdkProcessesInitialized === '1') {
          return;
        }
        container.dataset.aiClaudeAgentSdkProcessesInitialized = '1';
        container.textContent = '';

        const dataUrl = (drupalSettings && drupalSettings.claudeAgentSdkProcesses && drupalSettings.claudeAgentSdkProcesses.dataUrl) || '';
        const killUrl = (drupalSettings && drupalSettings.claudeAgentSdkProcesses && drupalSettings.claudeAgentSdkProcesses.killUrl) || '';
        const killToken = (drupalSettings && drupalSettings.claudeAgentSdkProcesses && drupalSettings.claudeAgentSdkProcesses.killToken) || '';
        if (!dataUrl) {
          container.textContent = 'No data URL configured.';
          return;
        }

        const status = document.createElement('div');
        status.className = 'claude-agent-sdk-terminal-status';
        container.appendChild(status);

        const actions = document.createElement('div');
        actions.style.margin = '12px 0';
        const killButton = document.createElement('button');
        killButton.type = 'button';
        killButton.className = 'button button--danger';
        killButton.textContent = 'Kill all Claude processes';
        actions.appendChild(killButton);
        container.appendChild(actions);

        const table = document.createElement('table');
        table.style.width = '100%';
        table.style.borderCollapse = 'collapse';
        table.innerHTML = '<thead><tr>' +
          '<th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">PID</th>' +
          '<th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Elapsed</th>' +
          '<th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">CPU</th>' +
          '<th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">MEM</th>' +
          '<th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Command</th>' +
          '</tr></thead><tbody></tbody>';
        container.appendChild(table);

        const tbody = table.querySelector('tbody');

        const sessionsHeading = document.createElement('h3');
        sessionsHeading.textContent = 'Sessions';
        sessionsHeading.style.marginTop = '20px';
        container.appendChild(sessionsHeading);

        const sessionsTable = document.createElement('table');
        sessionsTable.style.width = '100%';
        sessionsTable.style.borderCollapse = 'collapse';
        sessionsTable.innerHTML = '<thead><tr>' +
          '<th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Session ID</th>' +
          '<th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Mode</th>' +
          '<th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Source</th>' +
          '<th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Resume</th>' +
          '<th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">First Seen</th>' +
          '<th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Last Seen</th>' +
          '</tr></thead><tbody></tbody>';
        container.appendChild(sessionsTable);

        const sessionsBody = sessionsTable.querySelector('tbody');

        function render(rows) {
          tbody.innerHTML = '';
          const safeRows = Array.isArray(rows) ? rows : [];
          safeRows.forEach(function (row) {
            const item = row && typeof row === 'object' ? row : {};
            const tr = document.createElement('tr');
            [
              item.pid,
              item.etime,
              item.cpu,
              item.mem,
              item.command,
            ].forEach(function (value) {
              const td = document.createElement('td');
              td.style.padding = '6px';
              td.style.borderBottom = '1px solid #eee';
              td.style.fontFamily = 'monospace';
              td.textContent = String(value || '');
              tr.appendChild(td);
            });
            tbody.appendChild(tr);
          });
        }

        function renderSessions(sessions) {
          sessionsBody.innerHTML = '';
          const safeSessions = Array.isArray(sessions) ? sessions : [];
          if (!safeSessions.length) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="6" style="padding:6px; border-bottom:1px solid #eee; font-family:monospace;">No sessions tracked yet.</td>';
            sessionsBody.appendChild(tr);
            return;
          }

          safeSessions.forEach(function (session) {
            const item = session && typeof session === 'object' ? session : {};
            const tr = document.createElement('tr');
            const firstSeen = item.first_seen ? new Date(item.first_seen * 1000).toLocaleString() : '';
            const lastSeen = item.last_seen ? new Date(item.last_seen * 1000).toLocaleString() : '';
            [
              item.session_id,
              item.mode || '',
              item.source || '',
              item.resume || '',
              firstSeen,
              lastSeen,
            ].forEach(function (value) {
              const td = document.createElement('td');
              td.style.padding = '6px';
              td.style.borderBottom = '1px solid #eee';
              td.style.fontFamily = 'monospace';
              td.textContent = String(value || '');
              tr.appendChild(td);
            });
            sessionsBody.appendChild(tr);
          });
        }

        function poll() {
          fetch(dataUrl, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
              if (data.error) {
                status.textContent = 'Error: ' + data.error;
                return;
              }
              status.textContent = 'Running Claude processes: ' + data.count + ' | Sessions tracked: ' + (data.sessions_count || 0) + ' (updated ' + new Date(data.timestamp * 1000).toLocaleTimeString() + ')';
              render(data.rows || []);
              renderSessions(data.sessions || []);
            })
            .catch(function (error) {
              status.textContent = 'Error: ' + error.message;
            });
        }

        poll();
        setInterval(poll, 2000);

        killButton.addEventListener('click', function () {
          if (!killUrl) {
            status.textContent = 'Kill URL not configured.';
            return;
          }
          if (!confirm('Kill all running Claude CLI processes?')) {
            return;
          }
          fetch(killUrl, {
            method: 'POST',
            headers: {
              'X-CSRF-Token': killToken,
            },
            credentials: 'same-origin'
          })
            .then(function (response) { return response.json(); })
            .then(function (data) {
              status.textContent = 'Killed: ' + data.killed + ' (before ' + data.before + ', after ' + data.after + ')';
              poll();
            })
            .catch(function (error) {
              status.textContent = 'Error: ' + error.message;
            });
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
