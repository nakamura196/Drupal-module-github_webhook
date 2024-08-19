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
    $repos = $config->get("repositories");

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
      "#type" => "details",
      "#title" => $this->t("Repositories"),
      "#prefix" => '<div id="names-fieldset-wrapper">',
      "#suffix" => "</div>",
      "#open" => true,
    ];

    $index = 0;

    for ($row_no = 0; $row_no < $row_count; $row_no++) {
      $is_active_row = $form_state->get("row_" . $row_no . "_active");

      //We check if the row is active
      if ($is_active_row) {
        $index++;

        $form["repositories"]["repo" . $row_no] = [
          # '#type' => 'fieldset',
          "#type" => "details",
          "#title" => $this->t("Repository @row", ["@row" => $index]),
          "#open" => true,
        ];

        $form["repositories"]["repo" . $row_no][$row_no]["owner"] = [
          "#type" => "textfield",
          "#title" => $this->t("Owner"),
          "#default_value" => isset($repos[$row_no]["owner"])
            ? $repos[$row_no]["owner"]
            : "",
          "#placeholder" => "OWNER",
          "#description" => $this->t(
            "Enter the owner of the GitHub repository."
          ),
        ];

        // repo
        $form["repositories"]["repo" . $row_no][$row_no]["repo"] = [
          "#type" => "textfield",
          "#title" => $this->t("Repo"),
          "#default_value" => isset($repos[$row_no]["repo"])
            ? $repos[$row_no]["repo"]
            : "",
          "#placeholder" => "REPO",
          "#description" => $this->t(
            "Enter the name of the GitHub repository."
          ),
        ];

        // token
        $form["repositories"]["repo" . $row_no][$row_no]["github_token"] = [
          "#type" => "password",
          "#title" => $this->t("GitHub Token"),
          "#default_value" => isset($repos[$row_no]["github_token"])
            ? $repos[$row_no]["github_token"]
            : "",
          "#placeholder" => "ghp_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX",
          "#description" => $this->t(
            "Enter your GitHub Token. This will be hidden for security."
          ),
        ];

        // event_type
        $form["repositories"]["repo" . $row_no][$row_no]["event_type"] = [
          "#type" => "textfield",
          "#title" => $this->t("Event Type"),
          "#default_value" => isset($repos[$row_no]["event_type"])
            ? $repos[$row_no]["event_type"]
            : "webhook",
          "#description" => $this->t(
            "Enter the event type to trigger the webhook."
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

    $form["trigger_webhook_section"] = [
      "#type" => "details",
      "#title" => $this->t("Trigger Webhook"),
      "#open" => true, // 初期状態でアコーディオンを閉じる
    ];

    $options = [];

    foreach ($repos as $key => $repo) {
      $options[$key] = $repo["owner"] . "/" . $repo["repo"];
    }

    $form["trigger_webhook_section"]["select_repo"] = [
      "#type" => "select",
      "#title" => $this->t("Select Repository to Trigger"),
      "#options" => $options,
      "#empty_option" => $this->t("- Select a repository -"),
    ];

    $form["trigger_webhook_section"]["trigger_webhook"] = [
      "#type" => "submit",
      "#value" => $this->t("Trigger Webhook"),
      "#submit" => ["::triggerWebhook"],
    ];

    $form["actions"]["submit"] = [
      "#type" => "submit",
      "#value" => $this->t("Submit"),
    ];

    return $form;
  }

  /**
   * Callback for both ajax-enabled buttons.
   *
   * Selects and returns the fieldset with the names in it.
   */
  public function addmoreCallback(array &$form, FormStateInterface $form_state)
  {
    return $form["repositories"];
  }

  /**
   * Submit handler for the "add-one-more" button.
   *
   * Increments the max counter and causes a rebuild.
   */
  public function addOne(array &$form, FormStateInterface $form_state)
  {
    $cur_rows = $form_state->get("row_count");
    $rows = $cur_rows + 1;
    $form_state->set("row_count", $rows);
    // $this->messenger()->addMessage($rows);
    $form_state->set("row_" . $cur_rows . "_active", 1);
    // Since our buildForm() method relies on the value of 'row_count' to
    // generate 'owner' form elements, we have to tell the form to rebuild. If we
    // don't do this, the form builder will not call buildForm().
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "remove one" button.
   *
   * Decrements the max counter and causes a form rebuild.
   */
  public function removeCallback(array &$form, FormStateInterface $form_state)
  {
    $button_clicked = $form_state->getTriggeringElement()["#name"];
    $form_state->set("row_" . $button_clicked . "_active", 0);

    $form_state->setRebuild();
  }

  /**
   * Final submit handler.
   *
   * Reports what values were finally set.
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $row_count = $form_state->get("row_count");

    $repos = [];

    for ($row_no = 0; $row_no < $row_count; $row_no++) {
      $is_active_row = $form_state->get("row_" . $row_no . "_active");
      if ($is_active_row) {
        $repos[] = [
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
          "github_token" => $form_state->getValue([
            "repositories",
            "repo" . $row_no,
            $row_no,
            "github_token",
          ]),
          "event_type" => $form_state->getValue([
            "repositories",
            "repo" . $row_no,
            $row_no,
            "event_type",
          ]),
        ];
      }
    }

    $this->config("github_webhook.settings")
      ->set("repositories", $repos)
      ->save();
  }

  /**
   * Submit handler for the 'Trigger GitHub Webhook' button.
   */
  public function triggerWebhook(array &$form, FormStateInterface $form_state)
  {
    $config = $this->config("github_webhook.settings");
    $selected_repo = $form_state->getValue([
      "trigger_webhook_section",
      "select_repo",
    ]);

    if ($selected_repo === null) {
      \Drupal::messenger()->addError(
        $this->t("No repository selected. Please select a repository.")
      );
      return;
    }

    $repositories = $config->get("repositories");
    $repository = $repositories[$selected_repo];

    $token = $repository["github_token"];
    $event_type = $repository["event_type"];
    $owner = $repository["owner"];
    $repo = $repository["repo"];

    $client = new \GuzzleHttp\Client();
    $headers = [
      "Accept" => "application/vnd.github+json",
      "Authorization" => "Bearer " . $token,
      "Content-Type" => "application/json",
      "X-GitHub-Api-Version" => "2022-11-28",
    ];
    $body = json_encode(["event_type" => $event_type]);

    try {
      $request = new \GuzzleHttp\Psr7\Request(
        "POST",
        "https://api.github.com/repos/" . $owner . "/" . $repo . "/dispatches",
        $headers,
        $body
      );
      $response = $client->send($request);

      \Drupal::messenger()->addMessage(
        $this->t("GitHub webhook triggered successfully for @repository.", [
          "@repository" => $owner . "/" . $repo,
        ])
      );
    } catch (ClientException $e) {
      if ($e->getResponse()->getStatusCode() == 401) {
        \Drupal::messenger()->addError(
          $this->t(
            "Failed to trigger GitHub webhook for @repository: Unauthorized. Please check your GitHub token.",
            ["@repository" => $owner . "/" . $repo]
          )
        );
      } else {
        \Drupal::messenger()->addError(
          $this->t(
            "Failed to trigger GitHub webhook for @repository. Error: @error",
            [
              "@repository" => $owner . "/" . $repo,
              "@error" => $e->getMessage(),
            ]
          )
        );
      }
    } catch (RequestException $e) {
      \Drupal::messenger()->addError(
        $this->t(
          "Failed to trigger GitHub webhook for @repository. Error: @error",
          ["@repository" => $owner . "/" . $repo, "@error" => $e->getMessage()]
        )
      );
    } catch (GuzzleException $e) {
      \Drupal::messenger()->addError(
        $this->t(
          "A general error occurred while trying to trigger GitHub webhook for @repository. Error: @error",
          ["@repository" => $owner . "/" . $repo, "@error" => $e->getMessage()]
        )
      );
    } catch (\Exception $e) {
      \Drupal::messenger()->addError(
        $this->t("An unexpected error occurred for @repository: @error", [
          "@repository" => $owner . "/" . $repo,
          "@error" => $e->getMessage(),
        ])
      );
    }
  }
}