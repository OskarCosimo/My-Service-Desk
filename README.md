# My Service Desk 🎟️

**My Service Desk** is a lightweight, modern, and highly configurable open-source help desk system built from scratch using PHP 8, MySQL/MariaDB, and Bootstrap 5. Designed for simplicity, speed, and granular control, it features a multi-tenant hierarchy (Admins, Agencies, Agents, Users) that allows both registered accounts and unauthenticated guests to submit and track support requests effortlessly.

---

## ✨ Key Features

* **Multi-Tenant Hierarchy (Admins, Agencies & Agents)**:
  * **Admin**: Complete system control, user demotion/promotion, global settings, agency management, API key revocation/restoration, and manual ticket routing to any agency or agent.
  * **Agency**: Dedicated agency portal to invite, view, manage, and ban assigned agents, view individual agent performance analytics, and manage agency-level tickets.
  * **Agent**: Support staff portal to respond to assigned tickets or agency-wide incoming support requests, with REST API key generation capability.
* **REST API Ticket Ingestion**: Dedicated RESTful endpoint (`/api/v1/tickets.php`) to insert tickets programmatically via Agent API keys with automatic ticket attribution.
* **Automatic Ticket Routing & Auto-Assignment**:
  * Agencies and Agents can enable **Auto-Assign** from their profile settings (`profile.php`).
  * Incoming tickets are automatically assigned upon creation to active agencies/agents with auto-assign enabled.
  * Tickets assigned to an Agency become instantly visible and accessible to all agents under that agency.
* **Granular Ban Management & Cascading Bans**:
  * Individual agents can be banned/unbanned by their agency or an Admin.
  * Admin-level agency bans automatically cascade to ban all agents associated with that agency.
* **Live Agent Analytics & Audit Logs**:
  * Real-time metrics per agent: total assigned tickets, replies posted, and resolved/closed count.
  * Chronological activity feed modal displaying exact timestamps, ticket references, and reply previews for performance auditing.
* **Custom Layout Branding & Dynamic Colors**:
  * Customize Header and Sidebar background and font colors via color pickers in the Admin panel.
  * Replace default text titles with a custom brand Logo URL (recommended size: `180 x 40 px`, max height `40px`).
* **Guest & Registered Ticket Creation**: Guests can submit tickets with just their name and email, receiving a unique tracking code and a secure access token via email.
* **Web Installation Wizard**: Easy setup via `install.php` with automatic environment checks, database creation, and initial admin account setup.
* **1-Click Automatic Updates**: Built-in updater that checks GitHub Releases for new code, applies incremental database schema migrations (`migrate.php`), and preserves existing config files.
* **Two-Factor Authentication (2FA)**: TOTP-based 2FA support (Google Authenticator, Authy) for local accounts and SSO logins.
* **AI Assistance & Queue System**: Asynchronous background queue integration for AI-powered ticket summary and response assistance (supporting local Ollama and Google Gemini API).
* **SSO & OAuth Integration**: Single Sign-On integration for MYETV, Google, Microsoft, and Facebook accounts.
* **Rich Text Editing**: Integrated with **My-WYSIWYG** for rich-text formatting directly on submit and reply textareas.
* **Automated Translations (i18n)**: JSON-based internationalization featuring an automated translator tool powered by **LibreTranslate**.
* **Event Hook Plugin Engine**: Modular architecture allowing custom extensions (e.g., Discord webhook notifications) without modifying core source files.
* **Cloudflare Turnstile Captcha**: Built-in protection against spam and automated bots on forms.
* **Custom SMTP Mailing**: Support for PHPMailer or native PHP `mail()` for notification dispatches.

---

## 🛠️ System Requirements

* **PHP**: `^8.0` or higher with the following extensions enabled:
  * `pdo_mysql`
  * `curl`
  * `zip` (required for automatic updates)
  * `json`
* **Database**: MySQL `^8.0` or MariaDB `^10.3`.
* **Web Server**: Apache (`mod_rewrite` recommended) or Nginx.
* **File Permissions**: Write access for the web server user (`www-data` or `apache`) on the root directory for automated updates and configuration generation.

---

## 🚀 Installation Guide

### Step 1: Clone the Repository
```bash
git clone https://github.com/OskarCosimo/My-Service-Desk.git
cd My-Service-Desk
```

### Step 2: Set Directory Permissions

Assign ownership to the web server user so the web installer can write `includes/config.php` and the updater can manage release extractions:

```bash
sudo chown -R www-data:www-data /var/www/html/My-Service-Desk
sudo chmod -R 755 /var/www/html/My-Service-Desk
```

### Step 3: Run the Web Installer

Open your browser and navigate to the installation wizard:

```text
http://your-domain.com/install.php
```

The web installer will automatically:

1. Verify system requirements and write permissions.
2. Prompt for database credentials and site settings.
3. Import the database schema (`database.sql`).
4. Create the initial Administrator account.
5. Generate the `includes/config.php` configuration file.

---

## 🔑 REST API Keys & Ticket Ingestion

Support staff (Agents, Agencies, Admins) can generate a personal REST API Key to programmatically create tickets from external applications, CRM systems, webhooks, or third-party platforms.

### 1. Generating & Managing API Keys

* **Creation**: Log into your account as an **Agent** (or higher) and navigate to **Account Settings** (`/profile.php`). In the **REST API Key** card, enter a description and click **Generate New API Key**.
* **Limit**: Each user account is limited to **one active API key** at a time.
* **Regeneration & Revocation**: Agents can regenerate or revoke their own API key directly from their profile.
* **Admin Control**: System Administrators can view, suspend, revoke, or restore any user's API key at any time via **User & Agency Management** (`admin/users.php`).

---

### 2. API Endpoint: Create a Ticket

* **Endpoint**: `POST /api/v1/tickets.php`
* **Content-Type**: `application/json` (or `application/x-www-form-urlencoded`)

#### Authentication Headers

The API supports either standard **Bearer Token** authentication or a custom **X-API-Key** header:

```http
Authorization: Bearer tmk_your_api_key_here
```
*or*
```http
X-API-Key: tmk_your_api_key_here
```

#### Request Parameters

| Parameter | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `subject` | string | **Yes** | Ticket title / subject. |
| `message` | string | **Yes** | Ticket description or HTML body. |
| `email` | string | **Yes** | Customer email address (`guest_email` is also accepted). |
| `name` | string | No | Customer name (`guest_name` is also accepted; defaults to "Customer"). |
| `category_id`| integer| No | Category ID to assign the ticket to. |

> **Note**: Incoming tickets created via an Agent API Key are automatically assigned directly to the owning agent.

---

#### cURL Request Example (JSON)

```bash
curl -X POST "https://your-domain.com/api/v1/tickets.php" \
     -H "Authorization: Bearer tmk_xxxxxxxxxxxxxxxxxxxxxxxx" \
     -H "Content-Type: application/json" \
     -d '{
       "subject": "Server connection failure",
       "message": "Encountered a 504 Gateway Timeout on checkout.",
       "email": "customer@example.com",
       "name": "Jane Doe",
       "category_id": 2
     }'
```

#### Success Response (`HTTP 201 Created`)

```json
{
  "success": true,
  "message": "Ticket created successfully.",
  "ticket_id": 42,
  "tracking_code": "A1B-C2D-E3F",
  "tracking_url": "https://your-domain.com/track.php?code=A1B-C2D-E3F&token=3fa85f64...",
  "created_by": {
    "agent_id": 5,
    "agent_name": "support_agent_1"
  }
}
```

#### Error Response (`HTTP 401 / 403 / 422`)

```json
{
  "success": false,
  "error": "Forbidden. The provided API Key is invalid or has been revoked."
}
```

---

## 🏢 Agency & Agent Registration Workflow

1. **Agency Invitation**: Admins can generate and share the agency invite link (`/register.php?role=agency`) directly from the Admin Dashboard or manage them under **Manage Agencies** (`admin/agencies.php`).
2. **Agent Invitation**: Agencies can share their referral registration link with agents (`/register.php?agency=AGENCY_ID`) to automatically link new agents to their network.
3. **Admin User Management**: Admins can reassign any agent to an existing agency or set them as independent staff via **User & Agency Management** (`admin/users.php`).

---

## ⚙️ Pre-filled Form Links & Programmatic Form Submission

You can pre-populate ticket submission fields using GET or POST parameters:

```text
https://your-domain.com/submit.php?name=Mario+Rossi&email=mario@domain.com&category=2&subject=Login+Issue&message=I+cannot+login
```

### ⚠️ Important Note for Automatic Ticket Submission (Programmatic / Embeds)

To prevent accidental ticket creation caused solely by URL autofills or browser preloads, **`submit.php` requires an explicit submit trigger**.

If you are sending requests via HTML forms, cURL, or AJAX to submit a ticket automatically, you **MUST include one of the following parameters** set in your POST payload:

* `sendticket=true` OR
* `submit_ticket=1`

#### Example HTML Form Integration:

```html
<form method="POST" action="https://your-domain.com/submit.php">
    <!-- Mandatory flag for automatic execution -->
    <input type="hidden" name="sendticket" value="true">
    
    <input type="hidden" name="name" value="Mario Rossi">
    <input type="hidden" name="email" value="mario@domain.com">
    <input type="hidden" name="category_id" value="2">
    <input type="hidden" name="subject" value="Abuse Report: Content #1234">
    <textarea name="message">Detailed report description...</textarea>
    
    <button type="submit">Submit Ticket</button>
</form>
```

---

## 🔄 Automatic System Updates

**My Service Desk** includes a built-in update mechanism powered by GitHub Releases.

1. Navigate to **Admin Panel -> System Updates** (`/admin/update.php`).
2. The system queries GitHub Releases to check if a newer release is available.
3. Clicking **Update System Now**:
   * Verifies file write permissions across the codebase.
   * Downloads the latest release archive from GitHub.
   * Extracts new files while preserving sensitive local files (`includes/config.php`, `.htaccess`, custom assets).
   * Runs incremental database schema migrations automatically (`migrate.php`).

---

## 🤖 AI Background Queue Integration

The system features an asynchronous AI queue system (`ai_queue` table) for automated ticket processing (e.g., auto-summarization or agent reply suggestions).

* Configure your AI provider settings (Endpoint, API Key, Model) in **Admin Panel -> Settings**.
* The queue system is built with MySQL and executes asynchronously without requiring server-level cron jobs.
* Optionally, you can trigger pending AI queue jobs via CLI or server cron:

```bash
php api/process_ai_queue.php
```

---

## 🔒 Two-Factor Authentication (2FA)

Users and administrators can enable TOTP 2FA (Google Authenticator, Authy, 1Password) from their **Account Settings** profile.

* Works across both regular email/password logins and OAuth/SSO flows.
* Requires entering a valid 6-digit verification code (`/login_2fa.php`) before granting access.

---

## 🌍 Internationalization & Translations (i18n)

UI strings are managed via JSON files stored inside `/translations/`:

* **Base Language File**: `translations/lang-en.json`

### Automated Translations via LibreTranslate

1. Go to **Admin Panel -> Settings** and set your **LibreTranslate Endpoint URL** (e.g., `https://libretranslate.com`).
2. Go to **Admin Panel -> Translations**.
3. Enter the target language code (e.g., `it`, `es`, `fr`) and click **Generate Translation JSON**. The system will read `lang-en.json`, translate all strings via LibreTranslate, and output `lang-[code].json`.

---

## 🔌 Plugin System (Hooks Architecture)

**My Service Desk** features a zero-core-modification event engine.

### How to Create a Plugin

1. Create a subdirectory inside `/plugins/` (e.g., `/plugins/my_custom_plugin/`).
2. Create a PHP file inside it (e.g., `/plugins/my_custom_plugin/plugin.php`).
3. Attach listener callbacks using `add_hook()`:

```php
<?php
// Plugin Name: My Custom Plugin

add_hook('on_ticket_created', function($data) {
    $ticket = $data['ticket'];
    // Custom logic (API dispatch, SMS alert, etc.)
});

add_hook('on_ticket_replied', function($data) {
    $ticket = $data['ticket'];
    $reply = $data['reply'];
    // Custom logic
});
```

### Included Plugins

* **Discord Notifications (`plugins/discord_notifier/discord_plugin.php`)**: Sends instant rich embeds to a Discord channel via Webhook when a ticket is created or replied to. Configure the `discord_webhook_url` setting to enable it.

---

## 📄 License

This project is open-source software licensed under the [MIT License](https://github.com/OskarCosimo/My-Service-Desk?tab=MIT-1-ov-file).
