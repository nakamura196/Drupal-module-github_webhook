# GitHub Webhook

Trigger GitHub `repository_dispatch` events from the Drupal admin UI.

This module allows site administrators to configure GitHub repositories and send
`repository_dispatch` webhook events with a single click, enabling integration
with GitHub Actions workflows.

## Requirements

- Drupal 10 or 11
- PHP 8.3 or higher
- A GitHub [fine-grained personal access token](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens#fine-grained-personal-access-tokens)
  with the following repository permissions:
  - **Contents: Read and write** (required — for triggering `repository_dispatch`)
  - **Actions: Read** (optional — for displaying workflow run status)
- **Optional:** [Key](https://www.drupal.org/project/key) module for secure
  token storage

## Installation

Install as you would normally install a contributed Drupal module.
See [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules)
for further information.

## Configuration

1. Navigate to **Configuration > GitHub Webhook Settings**
   (`/github-webhook/settings`).
2. The "administer github webhook" permission is required to access the settings
   page. Assign this permission at **People > Permissions**.
3. Click **Add repository** and fill in the following fields:
   - **Owner** — The GitHub user or organization name (e.g., `my-org`).
   - **Repo** — The repository name (e.g., `my-repo`).
   - **GitHub Token** — A fine-grained personal access token (see Requirements).
     The saved token is never displayed back in the form.
   - **Event Type** — The `event_type` string sent in the dispatch payload
     (default: `webhook`).
4. Click **Submit** to save the configuration.

### Secure token storage with Key module

If the [Key](https://www.drupal.org/project/key) module is installed, each
repository can use a key instead of a manually entered token:

1. Install and enable the Key module (`composer require drupal/key && drush en key`).
2. Add a key at **Configuration > Keys** (`/admin/config/system/keys`) with
   your GitHub token as the value. You can store the token in an environment
   variable, a file outside the web root, or an external service like
   HashiCorp Vault.
3. In the GitHub Webhook settings, set **Token Source** to **Key module** and
   select the key from the dropdown.

This prevents the token from being stored in the Drupal database and keeps it
out of configuration exports (`drush config:export`).

## Usage

1. Go to the settings page (`/github-webhook/settings`).
2. In the **Trigger Webhook** section, select a repository from the dropdown.
3. Click **Trigger Webhook**.
4. A success or error message will be displayed.

### GitHub Actions example

To receive the dispatched event in a GitHub Actions workflow:

```yaml
on:
  repository_dispatch:
    types: [webhook]

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: echo "Triggered by Drupal webhook"
```

## License

This project is licensed under the Apache License 2.0. See the [LICENSE](LICENSE)
file for details.

---

# GitHub Webhook (日本語)

Drupal の管理画面から GitHub の `repository_dispatch` イベントを送信するモジュールです。

サイト管理者が GitHub リポジトリを設定し、ボタン一つで `repository_dispatch` Webhook
イベントを送信できます。GitHub Actions ワークフローとの連携に利用できます。

## 要件

- Drupal 10 または 11
- PHP 8.3 以上
- GitHub [Fine-grained パーソナルアクセストークン](https://docs.github.com/ja/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens#fine-grained-personal-access-tokens)
  （以下のリポジトリ権限が必要）:
  - **Contents: Read and write**（必須 — `repository_dispatch` のトリガーに必要）
  - **Actions: Read**（任意 — ワークフロー実行ステータスの表示に必要）
- **任意:** トークンを安全に保管するための [Key](https://www.drupal.org/project/key) モジュール

## インストール

通常の Drupal モジュールと同じ手順でインストールしてください。
詳しくは [Drupal モジュールのインストール](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules) を参照してください。

## 設定

1. **環境設定 > GitHub Webhook Settings** (`/github-webhook/settings`) に移動します。
2. 設定ページへのアクセスには「administer github webhook」権限が必要です。
   **ユーザー > 権限** から権限を付与してください。
3. **Add repository** をクリックし、以下の項目を入力します:
   - **Owner** — GitHub ユーザー名または組織名（例: `my-org`）
   - **Repo** — リポジトリ名（例: `my-repo`）
   - **GitHub Token** — Fine-grained パーソナルアクセストークン（要件を参照）。
     保存済みトークンはフォームに表示されません。
   - **Event Type** — ディスパッチペイロードに含まれる `event_type` 文字列（デフォルト: `webhook`）
4. **Submit** をクリックして設定を保存します。

### Key モジュールによる安全なトークン保管

[Key](https://www.drupal.org/project/key) モジュールがインストールされている場合、
手動入力の代わりにキーを使用してトークンを管理できます。

1. Key モジュールをインストールして有効化します
   (`composer require drupal/key && drush en key`)。
2. **環境設定 > Keys** (`/admin/config/system/keys`) でキーを追加し、
   GitHub トークンを値として設定します。環境変数、ウェブルート外のファイル、
   HashiCorp Vault などの外部サービスに保存できます。
3. GitHub Webhook の設定画面で **Token Source** を **Key module** に変更し、
   ドロップダウンからキーを選択します。

これにより、トークンが Drupal のデータベースに保存されなくなり、
設定エクスポート (`drush config:export`) にも含まれなくなります。

## 使い方

1. 設定ページ (`/github-webhook/settings`) にアクセスします。
2. **Trigger Webhook** セクションで、ドロップダウンからリポジトリを選択します。
3. **Trigger Webhook** をクリックします。
4. 成功またはエラーのメッセージが表示されます。

### GitHub Actions の連携例

GitHub Actions ワークフローでディスパッチイベントを受信するには:

```yaml
on:
  repository_dispatch:
    types: [webhook]

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: echo "Triggered by Drupal webhook"
```

## ライセンス

このプロジェクトは Apache License 2.0 の下で公開されています。詳細は [LICENSE](LICENSE) ファイルを参照してください。
