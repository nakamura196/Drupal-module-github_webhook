# Deploy Trigger

Trigger deployments on GitHub Actions and Vercel from the Drupal admin UI.

This module allows site administrators to configure GitHub repositories and
Vercel projects, then trigger deployments with a single click. It supports
real-time status monitoring and cancellation of running deployments.

## Requirements

- Drupal 11
- PHP 8.3 or higher
- **For GitHub:** A [fine-grained personal access token](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens#fine-grained-personal-access-tokens)
  with the following repository permissions:
  - **Contents: Read and write** (required — for triggering `repository_dispatch`)
  - **Actions: Read and write** (optional — for status monitoring and cancellation)
- **For Vercel:** A [Deploy Hook URL](https://vercel.com/docs/deployments/deploy-hooks)
  and optionally a [Vercel API token](https://vercel.com/docs/rest-api#creating-an-access-token)
  for status monitoring
- **Optional:** [Key](https://www.drupal.org/project/key) module for secure
  token storage

## Installation

Install as you would normally install a contributed Drupal module.
See [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules)
for further information.

## Configuration

Navigate to **Configuration > Web services > Deploy Trigger**
(`/admin/config/services/deploy-trigger`).

The "administer deploy trigger" permission is required to access the settings.
Assign this permission at **People > Permissions**.

### GitHub Repositories

1. Go to the **GitHub Repositories** tab.
2. Click **Add repository** and fill in the fields:
   - **Owner** (required) — The GitHub user or organization name (e.g., `my-org`).
   - **Repo** (required) — The repository name (e.g., `my-repo`).
   - **GitHub Token** (required) — A fine-grained personal access token.
     The saved token is never displayed back in the form.
   - **Event Type** (required) — The `event_type` string sent in the dispatch payload
     (default: `webhook`).
   - **Label** — Display name for the repository.
   - **Workflow File** — Filter status display to a specific workflow
     (e.g., `deploy.yml`).
3. Click **Submit** to save.

### Vercel Projects

1. Go to the **Vercel Projects** tab.
2. Click **Add project** and fill in the fields:
   - **Deploy Hook URL** (required) — The Vercel Deploy Hook URL. This is a
     secret and will be stored securely.
   - **Label** — Display name for the project.
   - **Project ID** — Vercel project ID for status monitoring.
   - **Vercel Token** — API token for status monitoring and cancellation.
     Not required if you only need to trigger deploys.
3. Click **Submit** to save.

### Secure token storage with Key module

If the [Key](https://www.drupal.org/project/key) module is installed, each
repository or project can use a key instead of a manually entered token:

1. Install and enable the Key module (`composer require drupal/key && drush en key`).
2. Add a key at **Configuration > Keys** (`/admin/config/system/keys`) with
   your token as the value.
3. In the settings, set **Token Source** to **Key module** and select the key
   from the dropdown.

This prevents the token from being stored in the Drupal database and keeps it
out of configuration exports (`drush config:export`).

### Auto Trigger

1. Go to the **Auto Trigger** tab.
2. Enable **Enable Auto Trigger**.
3. Select which content types should trigger deployments on save.
4. Select which GitHub repositories and/or Vercel projects to trigger.

## Usage

1. Go to the **Trigger** tab (`/admin/config/services/deploy-trigger`).
2. Select a deploy target from the dropdown (GitHub repositories and Vercel
   projects are combined in one list).
3. Click **Trigger Deploy**.
4. The status of recent runs is displayed below with real-time polling.
5. Active runs can be cancelled with the **Cancel** button.

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
      - run: echo "Triggered by Drupal"
```

### Vercel Deploy Hook example

Create a Deploy Hook in your Vercel project settings:

1. Go to your Vercel project **Settings > Git > Deploy Hooks**.
2. Create a hook with a name and branch.
3. Copy the generated URL into the Deploy Trigger settings.

## Permissions

| Permission | Description |
|---|---|
| Administer Deploy Trigger | Configure repositories, projects, tokens, and auto-trigger settings |
| Trigger Deploy | Trigger deployments on GitHub Actions and Vercel |

## License

This project is licensed under the Apache License 2.0. See the [LICENSE](LICENSE)
file for details.

---

# Deploy Trigger (日本語)

Drupal の管理画面から GitHub Actions と Vercel のデプロイをトリガーするモジュールです。

サイト管理者が GitHub リポジトリや Vercel プロジェクトを設定し、ボタン一つでデプロイを
実行できます。リアルタイムのステータス監視と実行中デプロイのキャンセルにも対応しています。

## 要件

- Drupal 11
- PHP 8.3 以上
- **GitHub 用:** [Fine-grained パーソナルアクセストークン](https://docs.github.com/ja/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens#fine-grained-personal-access-tokens)
  （以下のリポジトリ権限が必要）:
  - **Contents: Read and write**（必須 — `repository_dispatch` のトリガーに必要）
  - **Actions: Read and write**（任意 — ステータス監視とキャンセルに必要）
- **Vercel 用:** [Deploy Hook URL](https://vercel.com/docs/deployments/deploy-hooks)
  および、ステータス監視用に [Vercel API トークン](https://vercel.com/docs/rest-api#creating-an-access-token)（任意）
- **任意:** トークンを安全に保管するための [Key](https://www.drupal.org/project/key) モジュール

## インストール

通常の Drupal モジュールと同じ手順でインストールしてください。
詳しくは [Drupal モジュールのインストール](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules) を参照してください。

## 設定

**環境設定 > Web services > Deploy Trigger**
(`/admin/config/services/deploy-trigger`) に移動します。

設定ページへのアクセスには「administer deploy trigger」権限が必要です。
**ユーザー > 権限** から権限を付与してください。

### GitHub リポジトリ

1. **GitHub Repositories** タブに移動します。
2. **Add repository** をクリックし、以下の項目を入力します:
   - **Owner**（必須）— GitHub ユーザー名または組織名（例: `my-org`）
   - **Repo**（必須）— リポジトリ名（例: `my-repo`）
   - **GitHub Token**（必須）— Fine-grained パーソナルアクセストークン。
     保存済みトークンはフォームに表示されません。
   - **Event Type**（必須）— ディスパッチペイロードの `event_type` 文字列（デフォルト: `webhook`）
   - **Label** — リポジトリの表示名
   - **Workflow File** — ステータス表示を絞り込むワークフローファイル名（例: `deploy.yml`）
3. **Submit** をクリックして保存します。

### Vercel プロジェクト

1. **Vercel Projects** タブに移動します。
2. **Add project** をクリックし、以下の項目を入力します:
   - **Deploy Hook URL**（必須）— Vercel のデプロイフック URL。秘密情報として安全に保存されます。
   - **Label** — プロジェクトの表示名
   - **Project ID** — ステータス監視用の Vercel プロジェクト ID
   - **Vercel Token** — ステータス監視・キャンセル用の API トークン。
     デプロイのトリガーのみの場合は不要です。
3. **Submit** をクリックして保存します。

### Key モジュールによる安全なトークン保管

[Key](https://www.drupal.org/project/key) モジュールがインストールされている場合、
手動入力の代わりにキーを使用してトークンを管理できます。

1. Key モジュールをインストールして有効化します
   (`composer require drupal/key && drush en key`)。
2. **環境設定 > Keys** (`/admin/config/system/keys`) でキーを追加し、
   トークンを値として設定します。
3. 設定画面で **Token Source** を **Key module** に変更し、
   ドロップダウンからキーを選択します。

これにより、トークンが Drupal のデータベースに保存されなくなり、
設定エクスポート (`drush config:export`) にも含まれなくなります。

### 自動トリガー

1. **Auto Trigger** タブに移動します。
2. **Enable Auto Trigger** を有効にします。
3. 保存時にデプロイをトリガーするコンテンツタイプを選択します。
4. トリガー対象の GitHub リポジトリおよび Vercel プロジェクトを選択します。

## 使い方

1. **Trigger** タブ (`/admin/config/services/deploy-trigger`) にアクセスします。
2. ドロップダウンからデプロイターゲットを選択します（GitHub リポジトリと Vercel
   プロジェクトが統合されたリストです）。
3. **Trigger Deploy** をクリックします。
4. 最近の実行ステータスがリアルタイムポーリングで表示されます。
5. 実行中のデプロイは **Cancel** ボタンでキャンセルできます。

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
      - run: echo "Triggered by Drupal"
```

### Vercel Deploy Hook の設定例

Vercel プロジェクトの設定で Deploy Hook を作成します:

1. Vercel プロジェクトの **Settings > Git > Deploy Hooks** に移動します。
2. 名前とブランチを指定してフックを作成します。
3. 生成された URL を Deploy Trigger の設定に入力します。

## 権限

| 権限 | 説明 |
|---|---|
| Administer Deploy Trigger | リポジトリ、プロジェクト、トークン、自動トリガーの設定 |
| Trigger Deploy | GitHub Actions と Vercel のデプロイをトリガー |

## ライセンス

このプロジェクトは Apache License 2.0 の下で公開されています。詳細は [LICENSE](LICENSE) ファイルを参照してください。
