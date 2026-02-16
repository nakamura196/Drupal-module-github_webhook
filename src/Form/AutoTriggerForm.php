<?php

namespace Drupal\deploy_trigger\Form;

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
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
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
    return "deploy_trigger_auto_trigger";
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return ["deploy_trigger.settings"];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config("deploy_trigger.settings");
    $repos = $config->get("repositories") ?? [];
    $vercel_projects = $config->get("vercel_projects") ?? [];

    $form["auto_trigger_enabled"] = [
      "#type" => "checkbox",
      "#title" => $this->t("Enable Auto Trigger"),
      "#default_value" => $config->get("auto_trigger_enabled") ?? FALSE,
      "#description" => $this->t("Automatically trigger deploys when content is saved."),
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
      "#description" => $this->t("Select content types that will trigger deploys on save."),
    ];

    // GitHub repository checkboxes.
    $repo_options = [];
    foreach ($repos as $key => $repo) {
      $repo_options[$key] = !empty($repo["label"]) ? $repo["label"] : $repo["owner"] . "/" . $repo["repo"];
    }

    if (empty($repo_options)) {
      $form["no_repos"] = [
        "#markup" => '<p>' . $this->t('No GitHub repositories configured. <a href=":url">Add repositories</a> first.', [
          ':url' => Url::fromRoute('deploy_trigger.repositories')->toString(),
        ]) . '</p>',
      ];
    }

    $form["auto_trigger_repositories"] = [
      "#type" => "checkboxes",
      "#title" => $this->t("GitHub Repositories"),
      "#options" => $repo_options,
      "#default_value" => array_map('strval', $config->get("auto_trigger_repositories") ?? []),
      "#description" => $this->t("Select GitHub repositories to trigger when content is saved."),
    ];

    // Vercel project checkboxes.
    $vercel_options = [];
    foreach ($vercel_projects as $key => $project) {
      $vercel_options[$key] = !empty($project["label"]) ? $project["label"] : ('Vercel project ' . $key);
    }

    if (empty($vercel_options)) {
      $form["no_vercel"] = [
        "#markup" => '<p>' . $this->t('No Vercel projects configured. <a href=":url">Add projects</a> first.', [
          ':url' => Url::fromRoute('deploy_trigger.vercel_projects')->toString(),
        ]) . '</p>',
      ];
    }

    $form["auto_trigger_vercel_projects"] = [
      "#type" => "checkboxes",
      "#title" => $this->t("Vercel Projects"),
      "#options" => $vercel_options,
      "#default_value" => array_map('strval', $config->get("auto_trigger_vercel_projects") ?? []),
      "#description" => $this->t("Select Vercel projects to trigger when content is saved."),
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
    $auto_trigger_vercel_projects = array_values(array_map('intval', array_filter(
      $form_state->getValue("auto_trigger_vercel_projects") ?? [],
      function ($v) { return $v !== 0 && $v !== '0' && !empty($v); }
    )));

    $this->config("deploy_trigger.settings")
      ->set("auto_trigger_enabled", (bool) $form_state->getValue("auto_trigger_enabled"))
      ->set("auto_trigger_content_types", $auto_trigger_content_types)
      ->set("auto_trigger_repositories", $auto_trigger_repositories)
      ->set("auto_trigger_vercel_projects", $auto_trigger_vercel_projects)
      ->save();

    parent::submitForm($form, $form_state);
  }
}
