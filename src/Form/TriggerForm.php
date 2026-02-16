<?php

namespace Drupal\deploy_trigger\Form;

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
    return "deploy_trigger_trigger";
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->configFactory->get("deploy_trigger.settings");
    $repos = $config->get("repositories") ?? [];
    $vercel_projects = $config->get("vercel_projects") ?? [];

    $options = [];
    foreach ($repos as $key => $repo) {
      $label = !empty($repo["label"]) ? $repo["label"] : $repo["owner"] . "/" . $repo["repo"];
      $options["github_" . $key] = $label;
    }
    foreach ($vercel_projects as $key => $project) {
      $label = !empty($project["label"]) ? $project["label"] : ('Vercel project ' . $key);
      $options["vercel_" . $key] = $label;
    }

    if (empty($options)) {
      $form["no_targets"] = [
        "#markup" => '<p>' . $this->t('No deploy targets configured.') . '</p>',
      ];
      return $form;
    }

    $select = [
      "#type" => "select",
      "#title" => $this->t("Select Deploy Target"),
      "#options" => $options,
    ];

    // Auto-select if only one target.
    if (count($options) === 1) {
      $select["#default_value"] = array_key_first($options);
    } else {
      $select["#empty_option"] = $this->t("- Select a target -");
    }

    $form["select_repo"] = $select;

    $form["actions"]["trigger"] = [
      "#type" => "button",
      "#value" => $this->t("Trigger Deploy"),
      "#attributes" => [
        "id" => "deploy-trigger-btn",
        "type" => "button",
      ],
    ];

    // Status container (populated via JavaScript).
    $form["status_section"] = [
      "#type" => "container",
      "#attributes" => [
        "id" => "deploy-trigger-status",
        "class" => ["deploy-trigger-status"],
      ],
    ];

    $form["status_section"]["loading"] = [
      "#markup" => '<p class="deploy-trigger-status-loading">' . $this->t("Loading status...") . '</p>',
    ];

    // Attach JavaScript and settings.
    $form["#attached"]["library"][] = "deploy_trigger/status";

    // GitHub API URLs.
    $github_status_url = Url::fromRoute('deploy_trigger.github_status', ['repo_index' => 0])->toString();
    $github_status_base = preg_replace('#/0$#', '', $github_status_url);
    $github_trigger_url = Url::fromRoute('deploy_trigger.github_trigger_api', ['repo_index' => 0])->toString();
    $github_trigger_base = preg_replace('#/0$#', '', $github_trigger_url);
    $github_cancel_url = Url::fromRoute('deploy_trigger.github_cancel_api', ['repo_index' => 0, 'run_id' => 0])->toString();
    $github_cancel_base = preg_replace('#/0/0$#', '', $github_cancel_url);

    // Vercel API URLs.
    $vercel_status_url = Url::fromRoute('deploy_trigger.vercel_status', ['project_index' => 0])->toString();
    $vercel_status_base = preg_replace('#/0$#', '', $vercel_status_url);
    $vercel_trigger_url = Url::fromRoute('deploy_trigger.vercel_trigger_api', ['project_index' => 0])->toString();
    $vercel_trigger_base = preg_replace('#/0$#', '', $vercel_trigger_url);
    $vercel_cancel_url = Url::fromRoute('deploy_trigger.vercel_cancel_api', ['project_index' => 0, 'deployment_id' => '_'])->toString();
    $vercel_cancel_base = preg_replace('#/_$#', '', $vercel_cancel_url);

    $form["#attached"]["drupalSettings"]["deploy_trigger"] = [
      "github" => [
        "status_base_url" => $github_status_base,
        "trigger_base_url" => $github_trigger_base,
        "cancel_base_url" => $github_cancel_base,
      ],
      "vercel" => [
        "status_base_url" => $vercel_status_base,
        "trigger_base_url" => $vercel_trigger_base,
        "cancel_base_url" => $vercel_cancel_base,
      ],
      "is_admin" => $this->currentUser->hasPermission('administer deploy trigger'),
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
