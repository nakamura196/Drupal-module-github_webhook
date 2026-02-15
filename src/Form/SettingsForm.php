<?php

namespace Drupal\github_webhook\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class SettingsForm extends ConfigFormBase
{
  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return "github_webhook_settings";
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

    $key_module_available = \Drupal::moduleHandler()->moduleExists('key');
    $key_options = [];
    if ($key_module_available) {
      $keys = \Drupal::service('key.repository')->getKeys();
      foreach ($keys as $key) {
        $key_options[$key->id()] = $key->label();
      }
    }

    // Gather the number of rows in the form already.
    $row_count = $form_state->get("row_count");
    // We have to ensure that there is at least one row field.
    if ($row_count === null) {
      $form_state->set("row_count", count($repos));
      $row_count = count($repos);

      for ($row_no = 0; $row_no < $row_count; $row_no++) {
        $form_state->set("row_" . $row_no . "_active", 1);
      }
    }

    $form["#tree"] = true;
    $form["repositories"] = [
      "#type" => "container",
      "#prefix" => '<div id="names-fieldset-wrapper">',
      "#suffix" => "</div>",
    ];

    $index = 0;

    for ($row_no = 0; $row_no < $row_count; $row_no++) {
      $is_active_row = $form_state->get("row_" . $row_no . "_active");

      if ($is_active_row) {
        $index++;

        $label_value = $repos[$row_no]["label"] ?? "";
        $detail_title = $label_value
          ? $label_value
          : ($repos[$row_no]["owner"] ?? "") . "/" . ($repos[$row_no]["repo"] ?? "");
        if (!$detail_title || $detail_title === "/") {
          $detail_title = $this->t("Repository @row", ["@row" => $index]);
        }

        $form["repositories"]["repo" . $row_no] = [
          "#type" => "details",
          "#title" => $detail_title,
          "#open" => true,
        ];

        $form["repositories"]["repo" . $row_no][$row_no]["label"] = [
          "#type" => "textfield",
          "#title" => $this->t("Label"),
          "#default_value" => $label_value,
          "#placeholder" => $this->t("e.g. Production site"),
          "#description" => $this->t(
            "Optional display name for this repository. If empty, owner/repo will be used."
          ),
        ];

        $form["repositories"]["repo" . $row_no][$row_no]["owner"] = [
          "#type" => "textfield",
          "#title" => $this->t("Owner"),
          "#default_value" => $repos[$row_no]["owner"] ?? "",
          "#placeholder" => "OWNER",
          "#description" => $this->t(
            "Enter the owner of the GitHub repository."
          ),
        ];

        $form["repositories"]["repo" . $row_no][$row_no]["repo"] = [
          "#type" => "textfield",
          "#title" => $this->t("Repo"),
          "#default_value" => $repos[$row_no]["repo"] ?? "",
          "#placeholder" => "REPO",
          "#description" => $this->t(
            "Enter the name of the GitHub repository."
          ),
        ];

        // Token source selection (only when Key module is available).
        if ($key_module_available) {
          $form["repositories"]["repo" . $row_no][$row_no]["token_source"] = [
            "#type" => "select",
            "#title" => $this->t("Token Source"),
            "#options" => [
              "manual" => $this->t("Manual input"),
              "key" => $this->t("Key module"),
            ],
            "#default_value" => $repos[$row_no]["token_source"] ?? "manual",
            "#description" => $this->t(
              "Choose how to provide the GitHub token."
            ),
          ];
        }

        // GitHub Token (manual input) — never return the saved value.
        $has_manual_token = !empty($repos[$row_no]["github_token"]);
        $token_field = [
          "#type" => "password",
          "#title" => $this->t("GitHub Token"),
          "#placeholder" => "github_pat_XXXXXXXXXXXXXXXXXXXXXXXXXXXX",
          "#description" => $has_manual_token
            ? $this->t("A token is already saved. Leave blank to keep the current token.")
            : $this->t("Enter your GitHub personal access token. A fine-grained token with minimal permissions is recommended."),
        ];
        if ($key_module_available) {
          $token_field["#states"] = [
            "visible" => [
              ':input[name="repositories[repo' . $row_no . '][' . $row_no . '][token_source]"]' => ["value" => "manual"],
            ],
          ];
        }
        $form["repositories"]["repo" . $row_no][$row_no]["github_token"] = $token_field;

        // Key module token selector.
        if ($key_module_available) {
          $form["repositories"]["repo" . $row_no][$row_no]["token_key"] = [
            "#type" => "select",
            "#title" => $this->t("Key"),
            "#options" => $key_options,
            "#empty_option" => $this->t("- Select a key -"),
            "#default_value" => $repos[$row_no]["token_key"] ?? "",
            "#description" => $this->t(
              "Select a key that contains the GitHub token."
            ),
            "#states" => [
              "visible" => [
                ':input[name="repositories[repo' . $row_no . '][' . $row_no . '][token_source]"]' => ["value" => "key"],
              ],
            ],
          ];
        }

        // event_type
        $form["repositories"]["repo" . $row_no][$row_no]["event_type"] = [
          "#type" => "textfield",
          "#title" => $this->t("Event Type"),
          "#default_value" => $repos[$row_no]["event_type"] ?? "webhook",
          "#description" => $this->t(
            "Enter the event type to trigger the webhook."
          ),
        ];

        // workflow_file
        $form["repositories"]["repo" . $row_no][$row_no]["workflow_file"] = [
          "#type" => "textfield",
          "#title" => $this->t("Workflow File"),
          "#default_value" => $repos[$row_no]["workflow_file"] ?? "",
          "#placeholder" => "deploy.yml",
          "#description" => $this->t(
            "Optional. The workflow filename (e.g. deploy.yml) to filter status display. Leave blank to show all repository_dispatch runs."
          ),
        ];

        $form["repositories"]["repo" . $row_no][$row_no]["remove_name"] = [
          "#type" => "submit",
          "#name" => $row_no,
          "#value" => $this->t("Remove repository"),
          "#submit" => ["::removeCallback"],
          "#ajax" => [
            "callback" => "::addmoreCallback",
            "wrapper" => "names-fieldset-wrapper",
          ],
        ];
      }
    }

    $form["repositories"]["actions"] = [
      "#type" => "actions",
    ];

    $form["repositories"]["actions"]["add_name"] = [
      "#type" => "submit",
      "#value" => $this->t("Add repository"),
      "#submit" => ["::addOne"],
      "#ajax" => [
        "callback" => "::addmoreCallback",
        "wrapper" => "names-fieldset-wrapper",
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
    return $form["repositories"];
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

    $repos = [];

    for ($row_no = 0; $row_no < $row_count; $row_no++) {
      $is_active_row = $form_state->get("row_" . $row_no . "_active");
      if ($is_active_row) {
        $current_repo = [
          "label" => $form_state->getValue([
            "repositories",
            "repo" . $row_no,
            $row_no,
            "label",
          ]) ?? "",
          "owner" => $form_state->getValue([
            "repositories",
            "repo" . $row_no,
            $row_no,
            "owner",
          ]),
          "repo" => $form_state->getValue([
            "repositories",
            "repo" . $row_no,
            $row_no,
            "repo",
          ]),
          "event_type" => $form_state->getValue([
            "repositories",
            "repo" . $row_no,
            $row_no,
            "event_type",
          ]),
          "workflow_file" => $form_state->getValue([
            "repositories",
            "repo" . $row_no,
            $row_no,
            "workflow_file",
          ]) ?? "",
        ];

        // Save token source and key ID.
        $token_source = $form_state->getValue([
          "repositories",
          "repo" . $row_no,
          $row_no,
          "token_source",
        ]);
        $current_repo["token_source"] = $token_source ?? "manual";
        $current_repo["token_key"] = $form_state->getValue([
          "repositories",
          "repo" . $row_no,
          $row_no,
          "token_key",
        ]) ?? "";

        // Handle manual token — keep existing if blank.
        $github_token = $form_state->getValue([
          "repositories",
          "repo" . $row_no,
          $row_no,
          "github_token",
        ]);

        if (!empty($github_token)) {
          $current_repo["github_token"] = $github_token;
        } else {
          $existing_config = $this->config('github_webhook.settings')->get('repositories');
          $current_repo["github_token"] = $existing_config[$row_no]["github_token"] ?? "";
        }

        $repos[] = $current_repo;
      }
    }

    $this->config("github_webhook.settings")
      ->set("repositories", $repos)
      ->save();
  }
}
