<?php

namespace Drupal\deploy_trigger\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class VercelSettingsForm extends ConfigFormBase
{
  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The Key repository service (optional).
   *
   * @var \Drupal\key\KeyRepositoryInterface|null
   */
  protected ?object $keyRepository;

  /**
   * Constructs a VercelSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\key\KeyRepositoryInterface|null $key_repository
   *   The Key repository service, or NULL if Key module is not installed.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, ModuleHandlerInterface $module_handler, ?object $key_repository = NULL) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->moduleHandler = $module_handler;
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('module_handler'),
      $container->has('key.repository') ? $container->get('key.repository') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return "deploy_trigger_vercel_settings";
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
    $projects = $config->get("vercel_projects") ?? [];

    $key_module_available = $this->moduleHandler->moduleExists('key');
    $key_options = [];
    if ($key_module_available && $this->keyRepository) {
      $keys = $this->keyRepository->getKeys();
      foreach ($keys as $key) {
        $key_options[$key->id()] = $key->label();
      }
    }

    $row_count = $form_state->get("row_count");
    if ($row_count === null) {
      $form_state->set("row_count", count($projects));
      $row_count = count($projects);

      for ($row_no = 0; $row_no < $row_count; $row_no++) {
        $form_state->set("row_" . $row_no . "_active", 1);
      }
    }

    $form["#tree"] = true;
    $form["projects"] = [
      "#type" => "container",
      "#prefix" => '<div id="vercel-fieldset-wrapper">',
      "#suffix" => "</div>",
    ];

    $index = 0;

    for ($row_no = 0; $row_no < $row_count; $row_no++) {
      $is_active_row = $form_state->get("row_" . $row_no . "_active");

      if ($is_active_row) {
        $index++;

        $label_value = $projects[$row_no]["label"] ?? "";
        $detail_title = $label_value ?: $this->t("Project @row", ["@row" => $index]);

        $form["projects"]["project" . $row_no] = [
          "#type" => "fieldset",
          "#title" => $detail_title,
        ];

        $form["projects"]["project" . $row_no][$row_no]["label"] = [
          "#type" => "textfield",
          "#title" => $this->t("Label"),
          "#default_value" => $label_value,
          "#placeholder" => $this->t("e.g. My Vercel Site"),
          "#description" => $this->t("Display name for this Vercel project."),
        ];

        // Deploy Hook URL — password field, never return saved value.
        $has_hook_url = !empty($projects[$row_no]["deploy_hook_url"]);
        $form["projects"]["project" . $row_no][$row_no]["deploy_hook_url"] = [
          "#type" => "password",
          "#title" => $this->t("Deploy Hook URL"),
          "#required" => !$has_hook_url,
          "#placeholder" => "https://api.vercel.com/v1/integrations/deploy/...",
          "#description" => $has_hook_url
            ? $this->t("A Deploy Hook URL is already saved. Leave blank to keep the current URL.")
            : $this->t("Enter the Vercel Deploy Hook URL. This URL is a secret and will be stored securely."),
        ];

        $form["projects"]["project" . $row_no][$row_no]["project_id"] = [
          "#type" => "textfield",
          "#title" => $this->t("Project ID"),
          "#default_value" => $projects[$row_no]["project_id"] ?? "",
          "#placeholder" => "prj_xxxxxxxxxxxxxxxxxxxx",
          "#description" => $this->t("Vercel project ID for status monitoring. Leave blank if you only need to trigger deploys."),
        ];

        // Token source selection (only when Key module is available).
        if ($key_module_available) {
          $form["projects"]["project" . $row_no][$row_no]["token_source"] = [
            "#type" => "select",
            "#title" => $this->t("Token Source"),
            "#options" => [
              "manual" => $this->t("Manual input"),
              "key" => $this->t("Key module"),
            ],
            "#default_value" => $projects[$row_no]["token_source"] ?? "manual",
            "#description" => $this->t("Choose how to provide the Vercel API token."),
          ];
        }

        // Vercel Token (manual input) — never return the saved value.
        $has_manual_token = !empty($projects[$row_no]["vercel_token"]);
        $token_field = [
          "#type" => "password",
          "#title" => $this->t("Vercel Token"),
          "#description" => $has_manual_token
            ? $this->t("A token is already saved. Leave blank to keep the current token.")
            : $this->t("Vercel API token for status monitoring and cancel operations. Not required for triggering deploys."),
        ];
        if ($key_module_available) {
          $token_field["#states"] = [
            "visible" => [
              ':input[name="projects[project' . $row_no . '][' . $row_no . '][token_source]"]' => ["value" => "manual"],
            ],
          ];
        }
        $form["projects"]["project" . $row_no][$row_no]["vercel_token"] = $token_field;

        // Key module token selector.
        if ($key_module_available) {
          $form["projects"]["project" . $row_no][$row_no]["token_key"] = [
            "#type" => "select",
            "#title" => $this->t("Key"),
            "#options" => $key_options,
            "#empty_option" => $this->t("- Select a key -"),
            "#default_value" => $projects[$row_no]["token_key"] ?? "",
            "#description" => $this->t("Select a key that contains the Vercel API token."),
            "#states" => [
              "visible" => [
                ':input[name="projects[project' . $row_no . '][' . $row_no . '][token_source]"]' => ["value" => "key"],
              ],
            ],
          ];
        }

        $form["projects"]["project" . $row_no][$row_no]["remove_name"] = [
          "#type" => "submit",
          "#name" => $row_no,
          "#value" => $this->t("Remove project"),
          "#submit" => ["::removeCallback"],
          "#ajax" => [
            "callback" => "::addmoreCallback",
            "wrapper" => "vercel-fieldset-wrapper",
          ],
        ];
      }
    }

    $form["projects"]["actions"] = [
      "#type" => "actions",
    ];

    $form["projects"]["actions"]["add_name"] = [
      "#type" => "submit",
      "#value" => $this->t("Add project"),
      "#submit" => ["::addOne"],
      "#ajax" => [
        "callback" => "::addmoreCallback",
        "wrapper" => "vercel-fieldset-wrapper",
      ],
    ];

    $form["actions"]["submit"] = [
      "#type" => "submit",
      "#value" => $this->t("Submit"),
    ];

    return $form;
  }

  /**
   * Callback for both ajax-enabled buttons.
   */
  public function addmoreCallback(array &$form, FormStateInterface $form_state)
  {
    return $form["projects"];
  }

  /**
   * Submit handler for the "add-one-more" button.
   */
  public function addOne(array &$form, FormStateInterface $form_state)
  {
    $cur_rows = $form_state->get("row_count");
    $rows = $cur_rows + 1;
    $form_state->set("row_count", $rows);
    $form_state->set("row_" . $cur_rows . "_active", 1);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "remove one" button.
   */
  public function removeCallback(array &$form, FormStateInterface $form_state)
  {
    $button_clicked = $form_state->getTriggeringElement()["#name"];
    $form_state->set("row_" . $button_clicked . "_active", 0);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $row_count = $form_state->get("row_count");

    $projects = [];

    for ($row_no = 0; $row_no < $row_count; $row_no++) {
      $is_active_row = $form_state->get("row_" . $row_no . "_active");
      if ($is_active_row) {
        $current_project = [
          "label" => $form_state->getValue([
            "projects",
            "project" . $row_no,
            $row_no,
            "label",
          ]) ?? "",
          "project_id" => $form_state->getValue([
            "projects",
            "project" . $row_no,
            $row_no,
            "project_id",
          ]) ?? "",
        ];

        // Save token source and key ID.
        $token_source = $form_state->getValue([
          "projects",
          "project" . $row_no,
          $row_no,
          "token_source",
        ]);
        $current_project["token_source"] = $token_source ?? "manual";
        $current_project["token_key"] = $form_state->getValue([
          "projects",
          "project" . $row_no,
          $row_no,
          "token_key",
        ]) ?? "";

        // Handle Deploy Hook URL — keep existing if blank.
        $deploy_hook_url = $form_state->getValue([
          "projects",
          "project" . $row_no,
          $row_no,
          "deploy_hook_url",
        ]);
        if (!empty($deploy_hook_url)) {
          $current_project["deploy_hook_url"] = $deploy_hook_url;
        } else {
          $existing_config = $this->config('deploy_trigger.settings')->get('vercel_projects');
          $current_project["deploy_hook_url"] = $existing_config[$row_no]["deploy_hook_url"] ?? "";
        }

        // Handle Vercel token — keep existing if blank.
        $vercel_token = $form_state->getValue([
          "projects",
          "project" . $row_no,
          $row_no,
          "vercel_token",
        ]);
        if (!empty($vercel_token)) {
          $current_project["vercel_token"] = $vercel_token;
        } else {
          $existing_config = $this->config('deploy_trigger.settings')->get('vercel_projects');
          $current_project["vercel_token"] = $existing_config[$row_no]["vercel_token"] ?? "";
        }

        $projects[] = $current_project;
      }
    }

    $this->config("deploy_trigger.settings")
      ->set("vercel_projects", $projects)
      ->save();

    parent::submitForm($form, $form_state);
  }
}
