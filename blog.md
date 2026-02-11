# Drupal の GitHub Webhook モジュールを大幅に改善した

Drupal の管理画面から GitHub Actions をトリガーするカスタムモジュール「GitHub Webhook」を大幅に改善しました。元は複数リポジトリ対応の基本的なモジュールでしたが、UI のタブ分離、権限の細分化、ワークフローステータス表示、自動トリガーなどの機能を追加しています。

## 改善前のモジュール

元のモジュールは、以下のような構成でした。

- **ファイル数**: 5ファイル（`info.yml`、`routing.yml`、`links.menu.yml`、`permissions.yml`、`SettingsForm.php`）
- **対応バージョン**: Drupal 10 のみ
- **リポジトリ**: 複数対応済み（AJAX で動的追加・削除）
- **画面**: 設定とトリガーが同一画面（アコーディオン2つ）
- **権限**: `access github webhook settings` の1権限のみ（設定もトリガーも同じ権限）
- **トークン管理**: パスワードフィールドに `#default_value` を設定（HTML ソースに平文で出力される）
- **HTTP クライアント**: `new \GuzzleHttp\Client()` を直接インスタンス化
- **例外クラス**: `use` 文なしで catch ブロックに記述（名前空間の解決が不正）

```php
// 改善前: トークンが #default_value に設定されていた
$form['settings']['github_token'] = [
  '#type' => 'password',
  '#title' => $this->t('GitHub Token'),
  '#default_value' => $config->get('github_token'),  // HTML に平文出力される
];
```

```php
// 改善前: Guzzle クライアントを直接 new していた
$client = new \GuzzleHttp\Client();
```

## 変更の全体像

改善前後のファイル構成の比較です。`*` は変更、`+` は新規追加を示します。

```
github_webhook/
  既存ファイル（変更）:
  * github_webhook.info.yml           # Drupal 11 対応、PHP 要件追加
  * github_webhook.routing.yml        # 1ルート → 4ルートに拡張
  * github_webhook.links.menu.yml     # エントリポイント変更
  * github_webhook.permissions.yml    # 1権限 → 2権限に分離
  * src/Form/SettingsForm.php         # トリガー部分を分離、Key モジュール連携追加

  新規ファイル:
  + composer.json                     # Composer パッケージ定義
  + github_webhook.links.task.yml     # タブ定義（3タブ）
  + github_webhook.services.yml       # サービス定義
  + github_webhook.libraries.yml      # JS/CSS ライブラリ定義
  + github_webhook.module             # Entity フック（自動トリガー）
  + src/Form/TriggerForm.php          # 手動トリガー画面
  + src/Form/AutoTriggerForm.php      # 自動トリガー設定画面
  + src/Service/WebhookTriggerService.php  # Webhook 実行サービス
  + src/Controller/StatusController.php    # ワークフロー状態 JSON API
  + js/github-webhook-status.js       # ステータスポーリング
  + css/github-webhook-status.css     # ステータス表示スタイル
  + config/schema/github_webhook.schema.yml  # 設定スキーマ
  + translations/ja.po                # 日本語翻訳
  + Dockerfile                        # 検証用 Docker 環境
  + docker-compose.yml
```

## 1. 単一画面からタブ分離へ

### Before

設定とトリガーが1つの画面にアコーディオンで並んでいました。管理者もコンテンツ編集者も同じ画面を使うため、一般ユーザーにトークン入力欄が見えてしまう問題がありました。

```
/admin/config/github_webhook
├── [Settings] アコーディオン  ← 管理者用
│   ├── Owner / Repo / Token / Event Type
│   └── Submit
└── [Trigger Webhook] アコーディオン  ← 一般ユーザー用
    └── Trigger GitHub Webhook ボタン
```

### After

Drupal の **Local Tasks**（タブ）を使い、3つの画面に分離しました。一般ユーザーには「Trigger」タブのみが表示されます。

```
/github-webhook/settings
├── [Trigger] タブ         ← 一般ユーザー向け（デフォルト）
├── [Repositories] タブ    ← 管理者のみ
└── [Auto Trigger] タブ    ← 管理者のみ
```

```yaml
# github_webhook.links.task.yml（新規）
github_webhook.trigger_tab:
  route_name: github_webhook.trigger
  title: 'Trigger'
  base_route: github_webhook.trigger

github_webhook.repositories_tab:
  route_name: github_webhook.repositories
  title: 'Repositories'
  base_route: github_webhook.trigger

github_webhook.auto_trigger_tab:
  route_name: github_webhook.auto_trigger
  title: 'Auto Trigger'
  base_route: github_webhook.trigger
```

最も利用頻度の高い「Trigger」タブをデフォルトのルートに設定し、コンテンツ編集者が迷わない設計にしています。

## 2. 権限の分離

### Before

カスタム権限 `access github webhook settings` が1つだけ定義されており、設定変更もトリガーも同じ権限でアクセスしていました。

```yaml
# 改善前
access github webhook settings:
  title: 'Access GitHub Webhook Settings'
  description: 'Allow users to access GitHub webhook configuration.'
```

### After

管理者向けと一般ユーザー向けの2つの権限に分離しました。

```yaml
# github_webhook.permissions.yml
administer github webhook:
  title: 'Administer GitHub Webhook'
  description: 'Configure repositories, tokens, and auto-trigger settings.'
  restrict access: true

trigger github webhook:
  title: 'Trigger GitHub Webhook'
  description: 'Trigger repository_dispatch webhooks.'
```

`restrict access: true` を付けることで、Drupal の権限画面で管理者権限に警告マークが表示されます。

この分離により、管理者が PAT（Personal Access Token）を登録しておけば、GitHub アカウントを持っていない一般ユーザーでもビルドをトリガーできます。成功メッセージの内容もロールに応じて切り替えています。

```php
// 管理者には GitHub Actions へのリンクを表示
if (\Drupal::currentUser()->hasPermission('administer github webhook')) {
  $this->messenger()->addMessage(
    $this->t('... <a href=":url" target="_blank">View Actions</a>', [':url' => $actions_url])
  );
} else {
  // 一般ユーザーにはテキストのみ
  $this->messenger()->addMessage(
    $this->t("GitHub webhook triggered successfully for @repository.", [...])
  );
}
```

## 3. トークンセキュリティの改善

### Before

パスワードフィールドに `#default_value` を設定していたため、HTML ソースにトークンが平文で出力されていました。

```php
// 改善前: 危険
'#default_value' => $config->get('github_token'),
```

### After

`#default_value` を削除し、保存済みかどうかを説明文で示すようにしました。

```php
$has_manual_token = !empty($repos[$row_no]["github_token"]);
$token_field = [
  "#type" => "password",
  "#title" => $this->t("GitHub Token"),
  "#placeholder" => "github_pat_XXXXXXXXXXXXXXXXXXXXXXXXXXXX",
  "#description" => $has_manual_token
    ? $this->t("A token is already saved. Leave blank to keep the current token.")
    : $this->t("Enter your GitHub personal access token. ..."),
];
```

保存時には、空欄の場合は既存のトークンを維持します。

```php
if (!empty($github_token)) {
  $current_repo["github_token"] = $github_token;
} else {
  $existing_config = $this->config('github_webhook.settings')->get('repositories');
  $current_repo["github_token"] = $existing_config[$row_no]["github_token"] ?? "";
}
```

### Key モジュール連携（新機能）

[Key](https://www.drupal.org/project/key) モジュールがインストールされている場合、トークンの保管方法を切り替えられるようにしました。Drupal の `#states` API でフォームフィールドを動的に切り替えます。

```php
if ($key_module_available) {
  $form["repositories"]["repo" . $row_no][$row_no]["token_source"] = [
    "#type" => "select",
    "#options" => [
      "manual" => $this->t("Manual input"),
      "key" => $this->t("Key module"),
    ],
  ];
}
```

Key モジュールを使えば、トークンを環境変数や HashiCorp Vault に保管でき、Drupal データベースや `drush config:export` にトークンが含まれなくなります。

## 4. 設定スキーマの追加と設定構造の拡張

### Before

複数リポジトリ対応は済んでいたものの、設定スキーマ（`schema.yml`）が存在せず、設定のバリデーションや型チェックが効いていませんでした。また、自動トリガー関連の設定もありませんでした。

### After

設定スキーマを新規に定義し、リポジトリ設定に `token_source`、`token_key`、`workflow_file` フィールドを追加。自動トリガー関連の設定も追加しました。

```yaml
# config/schema/github_webhook.schema.yml（新規）
github_webhook.settings:
  type: config_object
  mapping:
    repositories:
      type: sequence
      sequence:
        type: mapping
        mapping:
          owner:
            type: string
          repo:
            type: string
          event_type:
            type: string
          token_source:
            type: string
          github_token:
            type: string
          token_key:
            type: string
          workflow_file:
            type: string
    auto_trigger_enabled:
      type: boolean
    auto_trigger_content_types:
      type: sequence
      sequence:
        type: string
    auto_trigger_repositories:
      type: sequence
      sequence:
        type: integer
```

## 5. ビジネスロジックのサービス化

### Before

Webhook のトリガー処理がフォームクラスの `triggerWebhook()` メソッドに直接実装されていました。

```php
// 改善前: フォームクラスにロジックが直接記述
public function triggerWebhook(array &$form, FormStateInterface $form_state) {
  $client = new \GuzzleHttp\Client();
  // ... API 呼び出し
}
```

### After

`WebhookTriggerService` としてサービスに切り出しました。フォームからも Entity フック（自動トリガー）からも呼び出せます。

```php
class WebhookTriggerService {
  public function triggerRepository(array $repository): bool { ... }
  public function resolveToken(array $repository): ?string { ... }
  public function getWorkflowRuns(array $repository, int $perPage = 5): array { ... }
}
```

```yaml
# github_webhook.services.yml（新規）
services:
  github_webhook.trigger:
    class: Drupal\github_webhook\Service\WebhookTriggerService
```

HTTP クライアントも `\Drupal::httpClient()` に変更し、Drupal のサービスコンテナ経由で取得するようにしました。

## 6. GitHub Actions ステータス表示（新機能）

トリガー後に GitHub にアクセスしなくても、ワークフローの実行状況を Drupal 上で確認できる機能を追加しました。

### アーキテクチャ

```
[TriggerForm]
    ↓ drupalSettings で設定を渡す
[github-webhook-status.js]
    ↓ fetch() で 5秒間隔ポーリング
[StatusController] /github-webhook/api/status/{repo_index}
    ↓ サービス経由で GitHub API を呼び出し
[WebhookTriggerService::getWorkflowRuns()]
    ↓ event=repository_dispatch でフィルタ
[GitHub API] /repos/{owner}/{repo}/actions/runs
```

管理者が登録した PAT をサーバーサイドで使って GitHub API を呼び出すため、一般ユーザーは GitHub のアカウントがなくてもステータスを確認できます。

ステータスはカラードットで視覚的に表示されます。

| ステータス | 色 | 表示 |
|-----------|-----|------|
| Queued | 黄色 | 静止 |
| In progress | 青 | パルスアニメーション |
| Success | 緑 | 静止 |
| Failed | 赤 | 静止 |
| Cancelled | グレー | 静止 |

管理者にはワークフロー実行の GitHub URL がリンクとして表示され、一般ユーザーにはテキストのみが表示されます。

### 注意点: トリガーしたランの特定

`repository_dispatch` API は HTTP 204 (No Content) を返すため、トリガーされたワークフローの Run ID を直接取得できません。そのため、`event=repository_dispatch` でフィルタした最近の実行一覧を表示するアプローチを取っています。

## 7. コンテンツ保存時の自動トリガー（新機能）

`hook_entity_insert` と `hook_entity_update` を実装し、ノードの保存時に自動的に Webhook をトリガーする機能を追加しました。

```php
// github_webhook.module（新規）
function _github_webhook_auto_trigger(EntityInterface $entity) {
  if ($entity->getEntityTypeId() !== 'node') {
    return;
  }

  $config = \Drupal::config('github_webhook.settings');
  if (!$config->get('auto_trigger_enabled')) {
    return;
  }

  // コンテンツタイプのチェック
  $content_types = $config->get('auto_trigger_content_types') ?? [];
  if (!in_array($entity->bundle(), $content_types)) {
    return;
  }

  // 対象リポジトリに対してトリガー実行
  $trigger_service = \Drupal::service('github_webhook.trigger');
  foreach ($auto_trigger_repos as $repo_index) {
    $trigger_service->triggerRepository($repositories[$repo_index]);
  }
}
```

管理画面の「Auto Trigger」タブから、トリガー対象のコンテンツタイプとリポジトリを選択できます。ビジネスロジックをサービスに切り出したことで、フォームのサブミットハンドラと Entity フックの両方から同じ処理を呼び出せています。

## 8. Drupal 11 対応

### Before

```yaml
core_version_requirement: ^10
```

### After

```yaml
core_version_requirement: ^10 || ^11
php: 8.3
```

コード面でも以下の改善を行いました。

| 改善前 | 改善後 | 理由 |
|--------|--------|------|
| `new \GuzzleHttp\Client()` | `\Drupal::httpClient()` | Drupal のサービスコンテナを通すべき |
| `use` 文なしで例外を catch | `use GuzzleHttp\Exception\...` を追加 | 名前空間の解決が不正だった |
| `\Drupal::messenger()->addMessage()` | `$this->messenger()->addMessage()` | `MessengerTrait` を使うべき |

`composer.json` も新規作成し、Drupal.org の標準に準拠しました。

```json
{
  "name": "drupal/github_webhook",
  "type": "drupal-module",
  "require": {
    "drupal/core": "^10 || ^11",
    "php": ">=8.3"
  },
  "suggest": {
    "drupal/key": "For secure token storage via environment variables, files, or external services."
  }
}
```

## 9. 多言語対応（新機能）

すべての UI 文字列を `$this->t()` / `Drupal.t()` でラップし、日本語翻訳ファイルを同梱しました。

```po
# translations/ja.po
msgid "Trigger Webhook"
msgstr "Webhook をトリガー"

msgid "Loading workflow status..."
msgstr "ワークフローの状態を読み込み中..."

msgid "GitHub webhook triggered successfully for @repository."
msgstr "@repository の GitHub Webhook を正常にトリガーしました。"
```

JavaScript 側の文字列も `Drupal.t()` を使用しているため、Drupal の翻訳システムで管理できます。

## 10. 開発環境（Docker）

ローカルでの検証用に Docker 環境を追加しました。

```dockerfile
# Dockerfile（新規）
FROM drupal:11-apache
RUN apt-get update && apt-get install -y unzip && rm -rf /var/lib/apt/lists/*
RUN composer require drush/drush --no-interaction --working-dir=/opt/drupal
```

モジュールのディレクトリをボリュームマウントし、ホスト側のファイル変更がコンテナに即座に反映されるようにしています。

## 付録: Fine-grained PAT の作成手順

このモジュールでは、GitHub の [Fine-grained Personal Access Token](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens#fine-grained-personal-access-tokens) を使用します。Classic PAT よりも細かく権限を制御でき、対象リポジトリも限定できます。

### 作成手順

1. GitHub の [Settings > Developer settings > Personal access tokens > Fine-grained tokens](https://github.com/settings/personal-access-tokens/new) にアクセス
2. **Token name** にわかりやすい名前を入力（例: `drupal-webhook`）
3. **Expiration** で有効期限を設定
4. **Repository access** で **Only select repositories** を選び、対象リポジトリを選択
5. **Repository permissions** で以下を設定:

| 権限 | 値 | 用途 |
|------|-----|------|
| **Contents** | Read and write | `repository_dispatch` イベントの送信（必須） |
| **Actions** | Read | ワークフロー実行ステータスの取得（任意） |

6. **Generate token** をクリックし、生成されたトークン（`github_pat_` で始まる）をコピー

### 注意事項

- `Actions: Read` を付与しない場合、ステータス表示機能は動作しません（トリガー自体は可能）
- Classic PAT の `repo` スコープは権限が広すぎるため、Fine-grained PAT を推奨します
- トークンの有効期限切れに注意してください。期限が近づいたら GitHub の設定画面から再生成が必要です

## 変更のまとめ

| 項目 | Before | After |
|------|--------|-------|
| 対応バージョン | Drupal 10 のみ | Drupal 10 / 11 |
| 画面構成 | 設定とトリガーが同一画面 | 3タブに分離 |
| 権限 | 1権限（設定もトリガーも共通） | 管理者 / 一般ユーザーの2段階 |
| トークン保管 | `#default_value` に設定（危険） | パスワードフィールド + Key モジュール連携 |
| HTTP クライアント | `new GuzzleHttp\Client()` | `\Drupal::httpClient()` |
| ビジネスロジック | フォームクラスに直接記述 | サービスクラスに分離 |
| ステータス表示 | なし | GitHub API ポーリング + JS レンダリング |
| ステータス絞り込み | なし | ワークフローファイル名で絞り込み可能 |
| 自動トリガー | なし | Entity フックでコンテンツ保存時に自動実行 |
| 多言語対応 | なし | `.po` ファイル（日本語対応） |
| 設定スキーマ | なし | `github_webhook.schema.yml` |
| composer.json | なし | Drupal.org 準拠 |
| Docker 環境 | なし | Dockerfile + docker-compose.yml |

GitHub にアクセスできない一般ユーザーでも、Drupal の管理画面からビルドのトリガーとステータス確認ができるようになり、ヘッドレス CMS 構成でのワークフロー自動化に活用しやすくなりました。
