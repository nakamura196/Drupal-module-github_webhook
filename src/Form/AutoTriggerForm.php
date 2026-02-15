<?php

namespace Drupal\github_webhook\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AutoTriggerForm extends ConfigFormBase
{
  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs an AutoTriggerForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return "github_webhook_auto_trigger";
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return ["github_webhook.settings"];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config("github_webhook.settings");
    $repos = $config->get("repositories") ?? [];

    $form["auto_trigger_enabled"] = [
      "#type" => "checkbox",
      "#title" => $this->t("Enable Auto Trigger"),
      "#default_value" => $config->get("auto_trigger_enabled") ?? FALSE,
      "#description" => $this->t("Automatically trigger webhooks when content is saved."),
    ];

    // Content type checkboxes.
    $content_types = $this->entityTypeManager
      ->getStorage('node_type')
      ->loadMultiple();
    $content_type_options = [];
    foreach ($content_types as $type) {
      $content_type_options[$type->id()] = $type->label();
    }

    $form["auto_trigger_content_types"] = [
      "#type" => "checkboxes",
      "#title" => $this->t("Content Types"),
      "#options" => $content_type_options,
      "#default_value" => $config->get("auto_trigger_content_types") ?? [],
      "#description" => $this->t("Select content types that will trigger webhooks on save."),
    ];

    // Repository checkboxes.
    $repo_options = [];
    foreach ($repos as $key => $repo) {
      $repo_options[$key] = !empty($repo["label"]) ? $repo["label"] : $repo["owner"] . "/" . $repo["repo"];
    }

    if (empty($repo_options)) {
      $form["no_repos"] = [
        "#markup" => '<p>' . $this->t('No repositories configured. <a href=":url">Add repositories</a> first.', [
          ':url' => Url::fromRoute('github_webhook.trigger')->toString(),
        ]) . '</p>',
      ];
    }

    $form["auto_trigger_repositories"] = [
      "#type" => "checkboxes",
      "#title" => $this->t("Repositories"),
      "#options" => $repo_options,
      "#default_value" => array_map('strval', $config->get("auto_trigger_repositories") ?? []),
      "#description" => $this->t("Select repositories to trigger when content is saved."),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $auto_trigger_content_types = array_values(array_filter(
      $form_state->getValue("auto_trigger_content_types") ?? []
    ));
    $auto_trigger_repositories = array_values(array_map('intval', array_filter(
      $form_state->getValue("auto_trigger_repositories") ?? [],
      function ($v) { return $v !== 0 && $v !== '0' && !empty($v); }
    )));

    $this->config("github_webhook.settings")
      ->set("auto_trigger_enabled", (bool) $form_state->getValue("auto_trigger_enabled"))
      ->set("auto_trigger_content_types", $auto_trigger_content_types)
      ->set("auto_trigger_repositories", $auto_trigger_repositories)
      ->save();

    parent::submitForm($form, $form_state);
  }
}
