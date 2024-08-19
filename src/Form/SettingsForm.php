<?php

namespace Drupal\github_webhook\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'github_webhook_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['github_webhook.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('github_webhook.settings');

    
    $form['settings'] = [    
      '#type' => 'details',
      '#title' => $this->t('Settings'),
      '#open' => TRUE,  // 初期状態でアコーディオンを閉じる
    ];

    $form['settings']['owner'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Owner'),
      '#default_value' => $config->get('owner'),
      '#description' => $this->t('Enter the owner of the GitHub repository.'),
    ];

    $form['settings']['repo'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repo'),
      '#default_value' => $config->get('repo'),
      '#description' => $this->t('Enter the name of the GitHub repository.'),
    ];

    $form['settings']['github_token'] = [
      '#type' => 'password',
      '#title' => $this->t('GitHub Token'),
      '#default_value' => $config->get('github_token'),
      '#description' => $this->t('Enter your GitHub Token. This will be hidden for security.'),
    ];

    $form['settings']['event_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event Type'),
      '#default_value' => $config->get('event_type'),
    ];

    

    // トリガーWebフックのボタンをアコーディオンで表示
    $form['trigger_webhook_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Trigger Webhook'),
      '#open' => TRUE,  // 初期状態でアコーディオンを閉じる
    ];

    $form['trigger_webhook_section']['trigger_webhook'] = [
      '#type' => 'submit',
      '#value' => $this->t('Trigger GitHub Webhook'),
      '#submit' => ['::triggerWebhook'], // Custom submit handler
    ];
  
    return parent::buildForm($form, $form_state);
  }

  

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save the settings to configuration
    $this->config('github_webhook.settings')
      ->set('owner', $form_state->getValue('owner'))
      ->set('repo', $form_state->getValue('repo'))
      ->set('github_token', $form_state->getValue('github_token'))
      ->set('event_type', $form_state->getValue('event_type'))
      ->save();

    parent::submitForm($form, $form_state);
  }
  /**
   * Submit handler for the 'Trigger GitHub Webhook' button.
   */
  public function triggerWebhook(array &$form, FormStateInterface $form_state) {
    $config = $this->config('github_webhook.settings');
    $token = $config->get('github_token');
    $event_type = $config->get('event_type');
    $owner = $config->get('owner');
    $repo = $config->get('repo');

    $client = new \GuzzleHttp\Client();
    $headers = [
        'Accept' => 'application/vnd.github+json',
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
        'X-GitHub-Api-Version' => '2022-11-28',
    ];
    $body = json_encode(['event_type' => $event_type]);

    try {
        $request = new \GuzzleHttp\Psr7\Request('POST', 'https://api.github.com/repos/' . $owner . '/' . $repo . '/dispatches', $headers, $body);
        $response = $client->send($request);

        // 成功した場合のメッセージ
        \Drupal::messenger()->addMessage($this->t('GitHub webhook triggered successfully.'));
    } catch (ClientException $e) {
        // Handle 401 Unauthorized specifically
        if ($e->getResponse()->getStatusCode() == 401) {
            \Drupal::messenger()->addError($this->t('Failed to trigger GitHub webhook: Unauthorized. Please check your GitHub token.'));
        } else {
            \Drupal::messenger()->addError($this->t('Failed to trigger GitHub webhook. Error: @error', ['@error' => $e->getMessage()]));
        }
    } catch (RequestException $e) {
        // エラーハンドリング
        \Drupal::messenger()->addError($this->t('Failed to trigger GitHub webhook. Error: @error', ['@error' => $e->getMessage()]));
    } catch (GuzzleException $e) {
        // GuzzleExceptionを捕捉する
        \Drupal::messenger()->addError($this->t('A general error occurred while trying to trigger GitHub webhook. Error: @error', ['@error' => $e->getMessage()]));
    } catch (\Exception $e) {
        // Catch any other general exceptions
        \Drupal::messenger()->addError($this->t('An unexpected error occurred: @error', ['@error' => $e->getMessage()]));
    }
}

}