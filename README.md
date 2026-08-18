# WhatsApp Bulk Sender Pro

WhatsApp Bulk Sender Pro is a professional, self-contained, and lightweight bulk messaging system built with pure **PHP 8.2** (compatible with 8.0+), **MySQL**, **Bootstrap 5**, and vanilla **JavaScript**. It leverages the official **Meta WhatsApp Cloud API** to dispatch bulk template messages with full queue management, pause/resume mechanisms, automated retries, diagnostic logs, and real-time delivery tracking webhooks.

## 🚀 Key Features

*   **Interactive Admin Dashboard**: Key performance indicators, delivery success rates, and live campaign progress metrics visualized with Chart.js.
*   **Contact Import & Directory**: Direct CSV and native Excel (`.xlsx`) uploading (using our zero-dependency parser), phone number sanitizer, and duplicate detection.
*   **WhatsApp Template Registry**: Setup template structures, customize languages, and use the **Dynamic Live Preview Mockup Bubble** to test message formats before campaigns.
*   **Smart Variable Mapping**: Automatically maps `{{1}}` to Contact Name, `{{2}}` to Contact City, and `{{3}}` to Contact Course.
*   **Meta Settings & Connectivity**: Store tokens, verify URLs, and send instant Sandbox `hello_world` checks with the **cURL Test Connection** utility.
*   **Double Queue Processors**:
    *   *Interactive Web Sender*: Responsive AJAX loop processing batches of 5 records sequentially with a 2-second sleep to bypass execution timeouts.
    *   *CLI Background Worker*: Command Line script for cron jobs (`cron/send-queue.php`) featuring batches of 50, a 2-second delay, and up to 3 auto-retries on API dispatches.
*   **Webhook Listener**: API endpoints to verify subscriptions and ingest Meta delivery events (`Sent`, `Delivered`, `Read`, `Failed`).

---

## 🛠️ Technology Stack

*   **Frontend**: HTML5, CSS3, Bootstrap 5, Javascript, AJAX, Chart.js, DataTables, SweetAlert2
*   **Backend**: PHP 8.2 (Compatible with 8.0+), MySQL (PDO), cURL
*   **External API**: Meta WhatsApp Cloud API (v23.0+)

---

## 📁 Project Structure

```text
whatsapp-bulk-sender/
├── config/
│   └── database.php         # PDO connection configs
├── controllers/             # Backend operations controllers
├── models/                  # OO Models (Admin, Contact, Campaign, Setting, Log)
│   ├── Admin.php
│   ├── Campaign.php
│   ├── Contact.php
│   ├── Log.php
│   ├── Setting.php
│   └── Template.php
├── views/                   # Interface panels
│   ├── layout/
│   │   ├── footer.php
│   │   ├── header.php
│   │   └── sidebar.php      # Admin sidebar navigation
│   ├── login.php            # Secure admin login form
│   ├── dashboard.php        # Core metrics and analytics
│   ├── contacts.php         # Directory list & CSV/Excel importers
│   ├── templates.php        # Meta template configurations & Live preview
│   ├── campaigns.php        # Campaign runner, progress, and controls
│   ├── reports.php          # Detailed reports & CSV spreadsheet exports
│   └── logs.php             # Raw JSON communication logs viewer
├── assets/
│   ├── css/
│   │   └── custom.css       # Layout styles & WhatsApp speech bubble css
│   └── js/
│       └── app.js           # Real-time AJAX loops & Preview compilers
├── uploads/                 # Temporary import file storage
├── api/
│   ├── campaign-actions.php # Campaign execution API (Pause, send batch, test connection)
│   └── webhook.php          # Meta Verification & Event reports endpoint
├── cron/
│   └── send-queue.php       # CLI background queue sender
├── database.sql             # SQL DB installation script
├── README.md                # System documentation
├── sample.csv               # CSV import template
└── sample.xlsx              # Excel import template
```

---

## 💻 Installation & Setup

### 1. Database Configuration
1. Open your MySQL client (e.g., **phpMyAdmin** in XAMPP).
2. Import the schema script inside `database.sql` to generate tables and seed initial records.
3. Open `config/database.php` and verify database host, name, username, and password:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'whatsapp_bulk_sender');
   define('DB_USER', 'root');
   define('DB_PASS', ''); // Set your MySQL password here if not empty
   ```

### 2. Run Local Web Server
If you are running XAMPP:
1. Move the `whatsapp-bulk-sender/` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** services in the XAMPP Control Panel.
3. Navigate to: `http://localhost/whatsapp-bulk-sender/`

Alternatively, you can run PHP's built-in CLI web server:
1. Open PowerShell / Command Prompt inside the project folder.
2. Execute:
   ```powershell
   php -S localhost:8000
   ```
3. Navigate to: `http://localhost:8000`

### 3. Log In to Dashboard
*   **Default Username**: `admin@example.com`
*   **Default Password**: `admin123`

---

## ⚙️ Meta WhatsApp Cloud API Setup

1. Go to the [Meta developers dashboard](https://developers.facebook.com/).
2. Setup/Select your Meta App and add **WhatsApp** to the product listings.
3. Under **WhatsApp -> Getting Started**:
    *   Copy the **Phone Number ID**.
    *   Copy the **WhatsApp Business Account ID**.
4. Go to **WhatsApp -> Configuration**:
    *   Copy the **Webhook Callback URL** and **Verify Token** from your app's **Meta Settings** page.
    *   Paste them into Meta's dashboard.
    *   Subscribe to the `messages` webhook field.
5. In your Business Manager, generate a **System User Permanent Access Token** with `whatsapp_business_messaging` permissions.
6. Open your **WhatsApp Bulk Sender Pro** dashboard, go to **Meta Settings**, input all credentials, and click **Save**.
7. Click **Test Connection**, enter a destination number, and verify that the pre-installed `hello_world` sandbox template dispatches successfully.

---

## 📈 Processing Campaigns

### Option A: Web-based Interactive Dispatch (AJAX Loop)
1. Go to the **Campaigns** tab, name your campaign, select your template, filter contacts, and click **Launch Campaign**.
2. Locate the new campaign in the table and click the green **Play/Start** button.
3. The dashboard executes a continuous AJAX loop calling `api/campaign-actions.php` in the background, updating progress bars and counters live (Sent, Delivered, Read, Failed) with a built-in safety delay of 2 seconds.
4. Keep the tab open to watch the progress. If closed, you can return and click **Resume** or **Pause** at any time.

### Option B: CLI Queue Worker (For Cron Jobs)
To process the campaign headlessly in the background:
1. Open a terminal on your host or setup a cron tab scheduler.
2. Execute the CLI worker script:
   ```powershell
   # Standard Windows command prompt execution
   C:\xampp\php\php.exe -f C:\path\to\whatsapp-bulk-sender\cron\send-queue.php
   ```
3. The worker queries active campaigns, dispatches messages in batches of 50, sleeps 2 seconds between dispatches, and handles up to 3 automatic retries for failed records.

---

## 🔒 Security Measures

*   **SQL Injection Protection**: Built entirely with PDO Prepared Statements to parameters-bind user input.
*   **CSRF Protection**: Form token validations for state-changing CRUD and settings updates.
*   **XSS Protection**: HTML escaping on name inputs, text bodies, and logs views.
*   **Auth Checks**: Router-level session authentication preventing unauthorized endpoint visits.
