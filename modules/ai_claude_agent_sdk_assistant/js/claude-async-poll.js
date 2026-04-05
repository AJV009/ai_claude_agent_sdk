/**
 * @file
 * Frontend-agnostic async polling for Claude Code executions.
 *
 * Watches for .claude-async-execution elements, polls the status endpoint,
 * and updates the UI with tool progress and final results.
 *
 * Emits custom DOM events for any consumer to listen to:
 * - claude-async-start
 * - claude-async-progress
 * - claude-async-complete
 * - claude-async-error
 */
(function (Drupal) {
  'use strict';

  const POLL_INTERVAL_ACTIVE_MS = 1000;
  const POLL_INTERVAL_IDLE_MS = 2000;
  const TIMEOUT_MS = 300000; // 5 minutes

  // Shared beforeunload state: counter of active executions prevents stacking.
  var activeExecutionCount = 0;
  var sharedUnloadHandler = function (e) {
    if (activeExecutionCount > 0) {
      e.preventDefault();
      e.returnValue = Drupal.t('Claude is still processing. Are you sure you want to leave?');
    }
  };
  var unloadHandlerRegistered = false;

  Drupal.behaviors.claudeAsyncPoll = {
    attach: function (context) {
      if (this._observerStarted) {
        return;
      }
      this._observerStarted = true;

      var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
          mutation.addedNodes.forEach(function (node) {
            if (node.nodeType !== Node.ELEMENT_NODE) {
              return;
            }
            var markers = [];
            if (node.classList && node.classList.contains('claude-async-execution')) {
              markers.push(node);
            }
            if (node.querySelectorAll) {
              node.querySelectorAll('.claude-async-execution').forEach(function (el) {
                markers.push(el);
              });
            }
            markers.forEach(function (marker) {
              if (marker.dataset.claudePolling) {
                return;
              }
              marker.dataset.claudePolling = 'true';
              startPolling(marker);
            });
          });
        });
      });

      observer.observe(document.body, { childList: true, subtree: true });

      // Shadow DOM support: DeepChat and other web components render content
      // inside shadow roots, which are invisible to document-level observers.
      // We observe each shadow root we find, and watch for new elements that
      // might have shadow roots (e.g., <deep-chat>).
      function observeShadowRoot(shadowRoot) {
        observer.observe(shadowRoot, { childList: true, subtree: true });
        // Check for markers already inside this shadow root.
        shadowRoot.querySelectorAll('.claude-async-execution').forEach(function (marker) {
          if (!marker.dataset.claudePolling) {
            marker.dataset.claudePolling = 'true';
            startPolling(marker);
          }
        });
        // Permission button click handler inside shadow DOM.
        // Shadow DOM events don't bubble to document, so we attach here.
        if (!shadowRoot._claudePermissionHandler) {
          shadowRoot._claudePermissionHandler = true;
          shadowRoot.addEventListener('click', handlePermissionClick);
        }
      }

      // Observe shadow roots of known web components.
      document.querySelectorAll('deep-chat').forEach(function (el) {
        if (el.shadowRoot) {
          observeShadowRoot(el.shadowRoot);
        }
      });

      // Watch for new web components being added to the DOM.
      var shadowObserver = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
          mutation.addedNodes.forEach(function (node) {
            if (node.nodeType === Node.ELEMENT_NODE && node.shadowRoot) {
              observeShadowRoot(node.shadowRoot);
            }
            // Also check children for shadow roots.
            if (node.nodeType === Node.ELEMENT_NODE && node.querySelectorAll) {
              node.querySelectorAll('deep-chat').forEach(function (el) {
                if (el.shadowRoot) {
                  observeShadowRoot(el.shadowRoot);
                }
              });
            }
          });
        });
      });
      shadowObserver.observe(document.body, { childList: true, subtree: true });

      // Check for markers already in the DOM (regular DOM).
      document.querySelectorAll('.claude-async-execution').forEach(function (marker) {
        if (!marker.dataset.claudePolling) {
          marker.dataset.claudePolling = 'true';
          startPolling(marker);
        }
      });
    }
  };

  function startPolling(marker) {
    var queryIdEl = marker.querySelector('.claude-async-query-id');
    if (!queryIdEl) {
      return;
    }

    var queryId = queryIdEl.textContent.trim();
    if (!queryId) {
      return;
    }

    // Hide the query ID code element.
    queryIdEl.style.display = 'none';

    // Build poll URL.
    var basePath = Drupal.url ? Drupal.url('api/claude-runner/poll/' + queryId) : '/api/claude-runner/poll/' + queryId;

    // Create status line that shows current activity.
    var statusLine = marker.querySelector('.claude-async-status') || document.createElement('span');
    if (!statusLine.className) {
      statusLine.className = 'claude-async-status';
      statusLine.textContent = Drupal.t('Claude is working...');
      marker.appendChild(statusLine);
    }

    // Stop/interrupt button.
    var stopBtn = document.createElement('button');
    stopBtn.className = 'claude-async-stop';
    stopBtn.textContent = Drupal.t('Stop');
    stopBtn.title = Drupal.t('Interrupt the current execution');
    stopBtn.addEventListener('click', function () {
      var abortUrl = Drupal.url
        ? Drupal.url('api/claude-runner/abort/' + queryId)
        : '/api/claude-runner/abort/' + queryId;
      fetch(abortUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
      }).then(function () {
        isPolling = false;
        activeExecutionCount = Math.max(0, activeExecutionCount - 1);
        if (statusLine) {
          statusLine.textContent = Drupal.t('Execution interrupted.');
        }
        stopBtn.remove();
        marker.dispatchEvent(new CustomEvent('claude-async-error', {
          bubbles: true,
          detail: { queryId: queryId, message: 'Aborted by user' }
        }));
      }).catch(function () {
        if (statusLine) {
          statusLine.textContent = Drupal.t('Failed to stop execution.');
        }
      });
    });
    marker.appendChild(stopBtn);

    // Create progress list inside a collapsible details element.
    var progressDetails = document.createElement('details');
    progressDetails.className = 'claude-async-steps';
    var progressSummary = document.createElement('summary');
    progressSummary.textContent = Drupal.t('Steps taken (0)');
    progressDetails.appendChild(progressSummary);
    var progressList = document.createElement('ul');
    progressList.className = 'claude-async-progress';
    progressDetails.appendChild(progressList);
    marker.appendChild(progressDetails);

    var renderedPermissions = {};

    // Emit start event.
    marker.dispatchEvent(new CustomEvent('claude-async-start', {
      bubbles: true,
      detail: { queryId: queryId }
    }));

    var lastEventId = 0;
    var startTime = Date.now();
    var isPolling = true;

    // Page unload protection (shared counter — no stacking).
    activeExecutionCount++;
    if (!unloadHandlerRegistered) {
      window.addEventListener('beforeunload', sharedUnloadHandler);
      unloadHandlerRegistered = true;
    }

    var currentInterval = POLL_INTERVAL_IDLE_MS;

    function schedulePoll() {
      setTimeout(pollOnce, currentInterval);
    }

    function pollOnce() {
      // Timeout check.
      if (Date.now() - startTime > TIMEOUT_MS) {
        isPolling = false;
        activeExecutionCount = Math.max(0, activeExecutionCount - 1);
        stopBtn.remove();
        marker.innerHTML = '<p>' + Drupal.t('Execution timed out. Please try again.') + '</p>';
        marker.dispatchEvent(new CustomEvent('claude-async-error', {
          bubbles: true,
          detail: { queryId: queryId, message: 'Timeout' }
        }));
        return;
      }

      var url = basePath + (lastEventId ? '?since=' + lastEventId : '');
      fetch(url, { credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (data.status === 'running') {
            var hadEvents = data.events && data.events.length > 0;
            if (hadEvents) {
              var latestToolName = '';
              var hadText = false;
              data.events.forEach(function (event) {
                if (event.type === 'assistant_text' && event.text) {
                  var textEl = document.createElement('li');
                  textEl.className = 'claude-async-text';
                  textEl.innerHTML = event.text;
                  progressList.appendChild(textEl);
                  hadText = true;
                }
                else {
                  var toolName = event.tool || event.type;
                  if (toolName && !toolName.startsWith('toolu_')) {
                    var li = document.createElement('li');
                    li.className = 'claude-async-tool';
                    li.textContent = toolName + (event.input ? ': ' + event.input : '');
                    progressList.appendChild(li);
                    latestToolName = toolName;
                    // Annotate auto-decided policy events.
                    if (event.was_ask && event.was_ask_reason) {
                      li.textContent += ' (' + event.was_ask_reason + ')';
                      li.classList.add('claude-async-tool--auto-decided');
                    }
                  }
                }

                if (event.id > lastEventId) {
                  lastEventId = event.id;
                }
              });

              // Update status line based on latest event type.
              if (latestToolName && statusLine) {
                statusLine.textContent = Drupal.t('Running: @tool...', { '@tool': latestToolName });
              } else if (hadText && statusLine) {
                statusLine.textContent = Drupal.t('Claude is responding...');
              }

              // Update collapsible summary with step count.
              if (progressSummary) {
                progressSummary.textContent = Drupal.t('Steps taken (@count)', { '@count': progressList.children.length });
              }

              marker.dispatchEvent(new CustomEvent('claude-async-progress', {
                bubbles: true,
                detail: { queryId: queryId, events: data.events }
              }));
            }

            // Render pending permission requests as separate messages.
            if (data.pendingPermissions && data.pendingPermissions.length > 0) {
              data.pendingPermissions.forEach(function (perm) {
                if (renderedPermissions[perm.requestId]) return;
                renderedPermissions[perm.requestId] = true;

                // Update status line.
                if (statusLine) {
                  statusLine.textContent = Drupal.t('Waiting for your approval...');
                }

                var permEl = document.createElement('div');
                permEl.className = 'claude-permission-request';
                permEl.dataset.requestId = perm.requestId;

                var toolDisplay = perm.toolName;
                if (perm.blockedPath) {
                  toolDisplay += ' \u2192 ' + perm.blockedPath;
                } else if (perm.input && perm.input.command) {
                  toolDisplay += ' \u2192 ' + perm.input.command.substring(0, 80);
                } else if (perm.input && perm.input.file_path) {
                  toolDisplay += ' \u2192 ' + perm.input.file_path;
                }

                var agentLabel = perm.agentId
                  ? '<div class="claude-permission-agent">' + Drupal.t('Sub-agent: @agent', { '@agent': perm.agentId }) + '</div>'
                  : '';

                permEl.innerHTML =
                  '<div class="claude-permission-header">' + Drupal.t('Permission Required') + '</div>'
                  + agentLabel
                  + '<div class="claude-permission-tool">' + Drupal.checkPlain(toolDisplay) + '</div>'
                  + (perm.decisionReason
                    ? '<div class="claude-permission-reason">' + Drupal.checkPlain(perm.decisionReason) + '</div>'
                    : '')
                  + '<div class="claude-permission-actions">'
                  + '<button class="claude-permission-allow" data-query-id="' + Drupal.checkPlain(queryId) + '" '
                  + 'data-request-id="' + Drupal.checkPlain(perm.requestId) + '">' + Drupal.t('Allow') + '</button>'
                  + '<button class="claude-permission-deny" data-query-id="' + Drupal.checkPlain(queryId) + '" '
                  + 'data-request-id="' + Drupal.checkPlain(perm.requestId) + '">' + Drupal.t('Deny') + '</button>'
                  + '</div>'
                  + '<div class="claude-permission-hint">' + Drupal.t('Or type a message below to respond') + '</div>';

                // Insert after the marker element as a sibling (separate message).
                marker.parentNode.insertBefore(permEl, marker.nextSibling);
              });
            }

            // Adaptive interval: faster when active, slower when idle.
            currentInterval = hadEvents ? POLL_INTERVAL_ACTIVE_MS : POLL_INTERVAL_IDLE_MS;
            schedulePoll();
          }
          else if (data.status === 'completed') {
            isPolling = false;
            activeExecutionCount = Math.max(0, activeExecutionCount - 1);
            stopBtn.remove();

            // Preserve tool + text progress as a collapsible section above the response.
            var completedHtml = '';
            if (progressList.children.length > 0) {
              // Collapse the details on completion.
              progressDetails.removeAttribute('open');
              progressSummary.textContent = Drupal.t('Steps taken (@count)', { '@count': progressList.children.length });
              completedHtml += progressDetails.outerHTML;
            }
            completedHtml += data.html;

            marker.innerHTML = completedHtml;
            marker.classList.remove('claude-async-execution');
            marker.classList.add('claude-async-done');
            marker.dispatchEvent(new CustomEvent('claude-async-complete', {
              bubbles: true,
              detail: { queryId: queryId, html: data.html }
            }));
          }
          else if (data.status === 'error') {
            isPolling = false;
            activeExecutionCount = Math.max(0, activeExecutionCount - 1);
            stopBtn.remove();
            marker.innerHTML = '<p>' + Drupal.t('Error: @message', { '@message': data.message }) + '</p>';
            marker.classList.remove('claude-async-execution');
            marker.classList.add('claude-async-error');
            marker.dispatchEvent(new CustomEvent('claude-async-error', {
              bubbles: true,
              detail: { queryId: queryId, message: data.message }
            }));
          }
        })
        .catch(function () {
          // Network error — keep polling, don't abort.
          schedulePoll();
        });
    }

    schedulePoll();
  }

  // Permission Allow/Deny click handler.
  // Attached to both document (for regular DOM) and shadow roots (for web components).
  function handlePermissionClick(e) {
    var btn = e.target.closest('.claude-permission-allow, .claude-permission-deny');
    if (!btn) return;

    var behavior = btn.classList.contains('claude-permission-allow') ? 'allow' : 'deny';
    var permQueryId = btn.dataset.queryId;
    var requestId = btn.dataset.requestId;

    var url = Drupal.url ? Drupal.url('api/claude-runner/permission-response') : '/api/claude-runner/permission-response';

    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        queryId: permQueryId,
        requestId: requestId,
        behavior: behavior
      }),
    });

    // Collapse the permission prompt, then fade out and remove.
    var permEl = btn.closest('.claude-permission-request');
    if (permEl) {
      permEl.innerHTML = '<span class="claude-permission-resolved">'
        + (behavior === 'allow' ? Drupal.t('Approved by user') : Drupal.t('Denied by user'))
        + '</span>';
      permEl.className = 'claude-permission-resolved-container';
      setTimeout(function () {
        permEl.style.transition = 'opacity 0.5s';
        permEl.style.opacity = '0';
        setTimeout(function () { permEl.remove(); }, 500);
      }, 3000);
    }
  }

  // Global click handler for permission buttons outside shadow DOM.
  document.addEventListener('click', handlePermissionClick);

})(Drupal);
