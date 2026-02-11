<?php

namespace Drupal\github_webhook\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class TriggerForm extends FormBase
{
  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return "github_webhook_trigger";
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = \Drupal::config("github_webhook.settings");
    $repos = $config->get("repositories") ?? [];

    $options = [];
    foreach ($repos as $key => $repo) {
      $options[$key] = $repo["owner"] . "/" . $repo["repo"];
    }

    if (empty($options)) {
      $form["no_repos"] = [
        "#markup" => '<p>' . $this->t('No repositories configured.') . '</p>',
      ];
      return $form;
    }

    $select = [
      "#type" => "select",
      "#title" => $this->t("Select Repository"),
      "#options" => $options,
    ];

    // Auto-select if only one repository.
    if (count($options) === 1) {
      $select["#default_value"] = array_key_first($options);
    } else {
      $select["#empty_option"] = $this->t("- Select a repository -");
    }

    $form["select_repo"] = $select;

    $form["actions"]["trigger"] = [
      "#type" => "submit",
      "#value" => $this->t("Trigger Webhook"),
    ];

    // Workflow run status container (populated via JavaScript).
    $form["status_section"] = [
      "#type" => "container",
      "#attributes" => [
        "id" => "github-webhook-status",
        "class" => ["github-webhook-status"],
      ],
    ];

    $form["status_section"]["loading"] = [
      "#markup" => '<p class="github-webhook-status-loading">' . $this->t("Loading workflow status...") . '</p>',
    ];

    // Attach JavaScript and settings.
    $form["#attached"]["library"][] = "github_webhook/status";
    $status_url = \Drupal\Core\Url::fromRoute('github_webhook.status', ['repo_index' => 0])->toString();
    $status_base_url = preg_replace('#/0$#', '', $status_url);
    $form["#attached"]["drupalSettings"]["github_webhook"] = [
      "status_base_url" => $status_base_url,
      "is_admin" => \Drupal::currentUser()->hasPermission('administer github webhook'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $config = \Drupal::config("github_webhook.settings");
    $selected_repo = $form_state->getValue("select_repo");

    if ($selected_repo === null || $selected_repo === '') {
      $this->messenger()->addError(
        $this->t("No repository selected. Please select a repository.")
      );
      return;
    }

    $repositories = $config->get("repositories");
    $repository = $repositories[$selected_repo];

    /** @var \Drupal\github_webhook\Service\WebhookTriggerService $trigger_service */
    $trigger_service = \Drupal::service('github_webhook.trigger');
    $success = $trigger_service->triggerRepository($repository);

    $owner = $repository['owner'];
    $repo = $repository['repo'];
    $label = $owner . '/' . $repo;

    if ($success) {
      if (\Drupal::currentUser()->hasPermission('administer github webhook')) {
        $actions_url = 'https://github.com/' . $owner . '/' . $repo . '/actions';
        $this->messenger()->addMessage(
          $this->t('GitHub webhook triggered successfully for @repository. <a href=":url" target="_blank">View Actions</a>', [
            "@repository" => $label,
            ":url" => $actions_url,
          ])
        );
      } else {
        $this->messenger()->addMessage(
          $this->t("GitHub webhook triggered successfully for @repository.", [
            "@repository" => $label,
          ])
        );
      }
    } else {
      $this->messenger()->addError(
        $this->t("Failed to trigger GitHub webhook for @repository. Please contact an administrator.", [
          "@repository" => $label,
        ])
      );
    }
  }
}
