(function (Drupal, drupalSettings) {
  'use strict';

  var POLL_INTERVAL = 5000;
  var RETRY_INTERVAL = 3000;
  var MAX_RETRIES = 4;
  var pollTimer = null;
  var retryCount = 0;
  var currentTarget = null;
  var statusContainer = null;
  var canCancel = true;
  var lastData = null;

  function parseTarget(value) {
    var parts = value.split('_');
    var provider = parts[0];
    var index = parts.slice(1).join('_');
    return { provider: provider, index: index };
  }

  function getUrls(provider) {
    var settings = drupalSettings.deploy_trigger;
    if (provider === 'vercel') {
      return settings.vercel;
    }
    return settings.github;
  }

  function isActive(run, provider) {
    if (provider === 'vercel') {
      var s = run.state;
      return s === 'QUEUED' || s === 'BUILDING' || s === 'INITIALIZING';
    }
    return run.status === 'queued' || run.status === 'in_progress';
  }

  function getStatusClass(run, provider) {
    if (provider === 'vercel') {
      var s = run.state;
      if (s === 'QUEUED') return 'run-queued';
      if (s === 'BUILDING' || s === 'INITIALIZING') return 'run-in-progress';
      if (s === 'READY') return 'run-success';
      if (s === 'ERROR') return 'run-failure';
      if (s === 'CANCELED') return 'run-cancelled';
      return 'run-unknown';
    }
    if (run.status === 'queued') return 'run-queued';
    if (run.status === 'in_progress') return 'run-in-progress';
    if (run.status === 'completed') {
      if (run.conclusion === 'success') return 'run-success';
      if (run.conclusion === 'failure') return 'run-failure';
      if (run.conclusion === 'cancelled') return 'run-cancelled';
    }
    return 'run-unknown';
  }

  function getStatusLabel(run, provider) {
    if (provider === 'vercel') {
      var s = run.state;
      if (s === 'QUEUED') return Drupal.t('Queued');
      if (s === 'BUILDING') return Drupal.t('Building');
      if (s === 'INITIALIZING') return Drupal.t('Initializing');
      if (s === 'READY') return Drupal.t('Ready');
      if (s === 'ERROR') return Drupal.t('Error');
      if (s === 'CANCELED') return Drupal.t('Cancelled');
      return s || Drupal.t('Unknown');
    }
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

  function showMessage(container, message, type) {
    var msgContainer = document.getElementById('deploy-trigger-messages');
    if (!msgContainer) {
      msgContainer = document.createElement('div');
      msgContainer.id = 'deploy-trigger-messages';
      container.parentNode.insertBefore(msgContainer, container);
    }
    var cls = type === 'error' ? 'messages--error' : 'messages--status';
    var role = type === 'error' ? 'alert' : 'status';
    msgContainer.innerHTML = '<div class="messages ' + cls + '" role="' + role + '">' + message + '</div>';
  }

  function fetchStatus(target, container) {
    currentTarget = target;
    statusContainer = container;
    var urls = getUrls(target.provider);
    var url = urls.status_base_url + '/' + target.index;

    fetch(url)
      .then(function (response) { return response.json(); })
      .then(function (data) {
        lastData = data;
        renderStatus(container, data, target.provider);

        var hasActiveRun = data.runs && data.runs.some(function (run) {
          return isActive(run, target.provider);
        });

        if (hasActiveRun) {
          retryCount = 0;
          pollTimer = setTimeout(function () {
            fetchStatus(target, container);
          }, POLL_INTERVAL);
        } else if (retryCount < MAX_RETRIES) {
          retryCount++;
          pollTimer = setTimeout(function () {
            fetchStatus(target, container);
          }, RETRY_INTERVAL);
        }
      })
      .catch(function () {
        container.innerHTML = '<p>' + Drupal.t('Failed to load status.') + '</p>';
      });
  }

  function triggerDeploy(target, container, button) {
    var urls = getUrls(target.provider);
    var url = urls.trigger_base_url + '/' + target.index;

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
        button.value = Drupal.t('Trigger Deploy');

        if (data.success) {
          var msg = data.message;
          if (data.actions_url) {
            msg += ' <a href="' + data.actions_url + '" target="_blank">View Actions</a>';
          }
          showMessage(container, msg, 'status');

          if (pollTimer) clearTimeout(pollTimer);
          retryCount = 0;
          pollTimer = setTimeout(function () {
            fetchStatus(target, container);
          }, RETRY_INTERVAL);
        } else {
          showMessage(container, data.message, 'error');
        }
      })
      .catch(function () {
        button.disabled = false;
        button.value = Drupal.t('Trigger Deploy');
        showMessage(container, Drupal.t('An error occurred while triggering the deploy.'), 'error');
      });
  }

  function cancelRun(runId, button) {
    var urls = getUrls(currentTarget.provider);
    var url = urls.cancel_base_url + '/' + currentTarget.index + '/' + runId;

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
          fetchStatus(currentTarget, statusContainer);
        } else {
          if (data.message && data.message.indexOf('permission') !== -1) {
            canCancel = false;
            if (lastData) {
              renderStatus(statusContainer, lastData, currentTarget.provider);
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
        showMessage(statusContainer, Drupal.t('Failed to cancel.'), 'error');
      });
  }

  function renderStatus(container, data, provider) {
    if (!data.runs || data.runs.length === 0) {
      container.innerHTML = '<p>' + Drupal.t('No recent runs.') + '</p>';
      return;
    }

    var isAdmin = drupalSettings.deploy_trigger.is_admin;

    var hasAnyActive = canCancel && data.runs.some(function (run) {
      return isActive(run, provider);
    });

    var html = '<h3>' + Drupal.t('Recent Runs') + '</h3>';
    html += '<table class="deploy-trigger-runs"><thead><tr>';
    html += '<th>' + Drupal.t('Status') + '</th>';
    html += '<th>' + Drupal.t('Started') + '</th>';
    html += '<th>' + Drupal.t('Duration') + '</th>';
    if (hasAnyActive) {
      html += '<th></th>';
    }
    html += '</tr></thead><tbody>';

    data.runs.forEach(function (run) {
      var statusClass = getStatusClass(run, provider);
      var statusLabel = getStatusLabel(run, provider);
      var timeAgo = getTimeAgo(new Date(run.created_at));
      var duration = getDuration(run, provider);
      var active = isActive(run, provider);
      var linkUrl = provider === 'vercel' ? run.url : run.html_url;

      html += '<tr class="' + statusClass + '">';
      html += '<td><span class="status-indicator"></span> ';
      if (isAdmin && linkUrl) {
        html += '<a href="' + linkUrl + '" target="_blank">' + statusLabel + '</a>';
      } else {
        html += statusLabel;
      }
      html += '</td>';
      html += '<td>' + timeAgo + '</td>';
      html += '<td>' + duration + '</td>';
      if (hasAnyActive) {
        html += '<td>';
        if (active) {
          html += '<button type="button" class="deploy-trigger-cancel-btn button button--small button--danger" data-run-id="' + run.id + '">' + Drupal.t('Cancel') + '</button>';
        }
        html += '</td>';
      }
      html += '</tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;

    container.querySelectorAll('.deploy-trigger-cancel-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        cancelRun(btn.dataset.runId, btn);
      });
    });
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

  function getDuration(run, provider) {
    var start = new Date(run.created_at);
    var isCompleted;
    if (provider === 'vercel') {
      isCompleted = run.state === 'READY' || run.state === 'ERROR' || run.state === 'CANCELED';
    } else {
      isCompleted = run.status === 'completed';
    }
    var end = isCompleted && run.updated_at ? new Date(run.updated_at) : new Date();
    var seconds = Math.floor((end - start) / 1000);
    if (seconds < 60) return seconds + 's';
    var minutes = Math.floor(seconds / 60);
    var secs = seconds % 60;
    if (minutes < 60) return minutes + 'm ' + secs + 's';
    var hours = Math.floor(minutes / 60);
    minutes = minutes % 60;
    return hours + 'h ' + minutes + 'm';
  }

  Drupal.behaviors.deployTriggerStatus = {
    attach: function (context) {
      var container = context.querySelector
        ? context.querySelector('#deploy-trigger-status')
        : null;
      if (!container || container.dataset.processed) return;
      container.dataset.processed = 'true';

      var select = document.querySelector('[name="select_repo"]');
      var triggerBtn = document.getElementById('deploy-trigger-btn');
      if (!select) return;

      function loadStatus() {
        if (pollTimer) clearTimeout(pollTimer);
        retryCount = 0;
        canCancel = true;
        var val = select.value;
        if (val !== '') {
          var target = parseTarget(val);
          fetchStatus(target, container);
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
            alert(Drupal.t('Please select a deploy target.'));
            return;
          }
          var target = parseTarget(val);
          triggerDeploy(target, container, triggerBtn);
        });
      }
    }
  };

})(Drupal, drupalSettings);
