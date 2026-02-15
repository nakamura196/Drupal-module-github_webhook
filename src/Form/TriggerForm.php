<?php

namespace Drupal\github_webhook\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

class TriggerForm extends FormBase
{
  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs a TriggerForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(ConfigFactoryInterface $config_factory, AccountProxyInterface $current_user) {
    $this->setConfigFactory($config_factory);
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('current_user'),
    );
  }

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
    $config = $this->configFactory->get("github_webhook.settings");
    $repos = $config->get("repositories") ?? [];

    $options = [];
    foreach ($repos as $key => $repo) {
      $options[$key] = !empty($repo["label"]) ? $repo["label"] : $repo["owner"] . "/" . $repo["repo"];
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
      "#type" => "button",
      "#value" => $this->t("Trigger Webhook"),
      "#attributes" => [
        "id" => "github-webhook-trigger-btn",
        "type" => "button",
      ],
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
    $status_url = Url::fromRoute('github_webhook.status', ['repo_index' => 0])->toString();
    $status_base_url = preg_replace('#/0$#', '', $status_url);
    $trigger_url = Url::fromRoute('github_webhook.trigger_api', ['repo_index' => 0])->toString();
    $trigger_base_url = preg_replace('#/0$#', '', $trigger_url);
    $cancel_url = Url::fromRoute('github_webhook.cancel_api', ['repo_index' => 0, 'run_id' => 0])->toString();
    $cancel_base_url = preg_replace('#/0/0$#', '', $cancel_url);
    $form["#attached"]["drupalSettings"]["github_webhook"] = [
      "status_base_url" => $status_base_url,
      "trigger_base_url" => $trigger_base_url,
      "cancel_base_url" => $cancel_base_url,
      "is_admin" => $this->currentUser->hasPermission('administer github webhook'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // Trigger is handled via AJAX — no server-side form submission needed.
  }
}
