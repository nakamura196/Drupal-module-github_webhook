(function (Drupal, drupalSettings) {
  'use strict';

  var POLL_INTERVAL = 5000;
  var RETRY_INTERVAL = 3000;
  var MAX_RETRIES = 4;
  var pollTimer = null;
  var retryCount = 0;
  var currentRepoIndex = null;
  var statusContainer = null;
  var canCancel = true;
  var lastData = null;

  function showMessage(container, message, type) {
    var msgContainer = document.getElementById('github-webhook-messages');
    if (!msgContainer) {
      msgContainer = document.createElement('div');
      msgContainer.id = 'github-webhook-messages';
      container.parentNode.insertBefore(msgContainer, container);
    }
    var cls = type === 'error' ? 'messages--error' : 'messages--status';
    var role = type === 'error' ? 'alert' : 'status';
    msgContainer.innerHTML = '<div class="messages ' + cls + '" role="' + role + '">' + message + '</div>';
  }

  function fetchStatus(repoIndex, container) {
    currentRepoIndex = repoIndex;
    statusContainer = container;
    var url = drupalSettings.github_webhook.status_base_url + '/' + repoIndex;

    fetch(url)
      .then(function (response) { return response.json(); })
      .then(function (data) {
        lastData = data;
        renderStatus(container, data);

        var hasActive = data.runs && data.runs.some(function (run) {
          return run.status === 'queued' || run.status === 'in_progress';
        });

        if (hasActive) {
          retryCount = 0;
          pollTimer = setTimeout(function () {
            fetchStatus(repoIndex, container);
          }, POLL_INTERVAL);
        } else if (retryCount < MAX_RETRIES) {
          retryCount++;
          pollTimer = setTimeout(function () {
            fetchStatus(repoIndex, container);
          }, RETRY_INTERVAL);
        }
      })
      .catch(function () {
        container.innerHTML = '<p>' + Drupal.t('Failed to load workflow status.') + '</p>';
      });
  }

  function triggerWebhook(repoIndex, container, button) {
    var url = drupalSettings.github_webhook.trigger_base_url + '/' + repoIndex;

    button.disabled = true;
    button.value = Drupal.t('Triggering...');

    fetch(Drupal.url('session/token'))
      .then(function (response) { return response.text(); })
      .then(function (token) {
        return fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
          },
        });
      })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        button.disabled = false;
        button.value = Drupal.t('Trigger Webhook');

        if (data.success) {
          var msg = data.message;
          if (data.actions_url) {
            msg += ' <a href="' + data.actions_url + '" target="_blank">View Actions</a>';
          }
          showMessage(container, msg, 'status');

          if (pollTimer) clearTimeout(pollTimer);
          retryCount = 0;
          pollTimer = setTimeout(function () {
            fetchStatus(repoIndex, container);
          }, RETRY_INTERVAL);
        } else {
          showMessage(container, data.message, 'error');
        }
      })
      .catch(function () {
        button.disabled = false;
        button.value = Drupal.t('Trigger Webhook');
        showMessage(container, Drupal.t('An error occurred while triggering the webhook.'), 'error');
      });
  }

  function cancelWorkflowRun(runId, button) {
    var url = drupalSettings.github_webhook.cancel_base_url + '/' + currentRepoIndex + '/' + runId;

    button.disabled = true;
    button.textContent = Drupal.t('Cancelling...');

    fetch(Drupal.url('session/token'))
      .then(function (response) { return response.text(); })
      .then(function (token) {
        return fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
          },
        });
      })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data.success) {
          showMessage(statusContainer, data.message, 'status');
          if (pollTimer) clearTimeout(pollTimer);
          retryCount = 0;
          fetchStatus(currentRepoIndex, statusContainer);
        } else {
          // If forbidden, hide cancel buttons from now on.
          if (data.message && data.message.indexOf('permission') !== -1) {
            canCancel = false;
            if (lastData) {
              renderStatus(statusContainer, lastData);
            }
          } else {
            button.disabled = false;
            button.textContent = Drupal.t('Cancel');
          }
          showMessage(statusContainer, data.message, 'error');
        }
      })
      .catch(function () {
        button.disabled = false;
        button.textContent = Drupal.t('Cancel');
        showMessage(statusContainer, Drupal.t('Failed to cancel workflow run.'), 'error');
      });
  }

  function renderStatus(container, data) {
    if (!data.runs || data.runs.length === 0) {
      container.innerHTML = '<p>' + Drupal.t('No recent workflow runs.') + '</p>';
      return;
    }

    var isAdmin = drupalSettings.github_webhook.is_admin;

    var hasAnyActive = canCancel && data.runs.some(function (run) {
      return run.status === 'queued' || run.status === 'in_progress';
    });

    var html = '<h3>' + Drupal.t('Recent Workflow Runs') + '</h3>';
    html += '<table class="github-webhook-runs"><thead><tr>';
    html += '<th>' + Drupal.t('Status') + '</th>';
    html += '<th>' + Drupal.t('Started') + '</th>';
    html += '<th>' + Drupal.t('Duration') + '</th>';
    if (hasAnyActive) {
      html += '<th></th>';
    }
    html += '</tr></thead><tbody>';

    data.runs.forEach(function (run) {
      var statusClass = getStatusClass(run);
      var statusLabel = getStatusLabel(run);
      var timeAgo = getTimeAgo(new Date(run.created_at));
      var duration = getDuration(run);
      var isActive = run.status === 'queued' || run.status === 'in_progress';

      html += '<tr class="' + statusClass + '">';
      html += '<td><span class="status-indicator"></span> ';
      if (isAdmin && run.html_url) {
        html += '<a href="' + run.html_url + '" target="_blank">' + statusLabel + '</a>';
      } else {
        html += statusLabel;
      }
      html += '</td>';
      html += '<td>' + timeAgo + '</td>';
      html += '<td>' + duration + '</td>';
      if (hasAnyActive) {
        html += '<td>';
        if (isActive) {
          html += '<button type="button" class="github-webhook-cancel-btn button button--small button--danger" data-run-id="' + run.id + '">' + Drupal.t('Cancel') + '</button>';
        }
        html += '</td>';
      }
      html += '</tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;

    container.querySelectorAll('.github-webhook-cancel-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        cancelWorkflowRun(btn.dataset.runId, btn);
      });
    });
  }

  function getStatusClass(run) {
    if (run.status === 'queued') return 'run-queued';
    if (run.status === 'in_progress') return 'run-in-progress';
    if (run.status === 'completed') {
      if (run.conclusion === 'success') return 'run-success';
      if (run.conclusion === 'failure') return 'run-failure';
      if (run.conclusion === 'cancelled') return 'run-cancelled';
    }
    return 'run-unknown';
  }

  function getStatusLabel(run) {
    if (run.status === 'queued') return Drupal.t('Queued');
    if (run.status === 'in_progress') return Drupal.t('In progress');
    if (run.status === 'completed') {
      if (run.conclusion === 'success') return Drupal.t('Success');
      if (run.conclusion === 'failure') return Drupal.t('Failed');
      if (run.conclusion === 'cancelled') return Drupal.t('Cancelled');
      return run.conclusion || Drupal.t('Completed');
    }
    return run.status;
  }

  function getTimeAgo(date) {
    var seconds = Math.floor((new Date() - date) / 1000);
    if (seconds < 60) return Drupal.t('Just now');
    var minutes = Math.floor(seconds / 60);
    if (minutes < 60) return Drupal.t('@count min ago', { '@count': minutes });
    var hours = Math.floor(minutes / 60);
    if (hours < 24) return Drupal.t('@count hr ago', { '@count': hours });
    var days = Math.floor(hours / 24);
    return Drupal.t('@count days ago', { '@count': days });
  }

  function getDuration(run) {
    var start = new Date(run.created_at);
    var end = run.status === 'completed' ? new Date(run.updated_at) : new Date();
    var seconds = Math.floor((end - start) / 1000);
    if (seconds < 60) return seconds + 's';
    var minutes = Math.floor(seconds / 60);
    var secs = seconds % 60;
    if (minutes < 60) return minutes + 'm ' + secs + 's';
    var hours = Math.floor(minutes / 60);
    minutes = minutes % 60;
    return hours + 'h ' + minutes + 'm';
  }

  Drupal.behaviors.githubWebhookStatus = {
    attach: function (context) {
      var container = context.querySelector
        ? context.querySelector('#github-webhook-status')
        : null;
      if (!container || container.dataset.processed) return;
      container.dataset.processed = 'true';

      var select = document.querySelector('[name="select_repo"]');
      var triggerBtn = document.getElementById('github-webhook-trigger-btn');
      if (!select) return;

      function loadStatus() {
        if (pollTimer) clearTimeout(pollTimer);
        retryCount = 0;
        var val = select.value;
        if (val !== '') {
          fetchStatus(val, container);
        } else {
          container.innerHTML = '';
        }
      }

      loadStatus();

      select.addEventListener('change', loadStatus);

      if (triggerBtn) {
        triggerBtn.addEventListener('click', function (e) {
          e.preventDefault();
          var val = select.value;
          if (val === '') {
            alert(Drupal.t('Please select a repository.'));
            return;
          }
          triggerWebhook(val, container, triggerBtn);
        });
      }
    }
  };

})(Drupal, drupalSettings);
