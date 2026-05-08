# BoligMatch — Setup Guide

Complete installation instructions for running BoligMatch locally with XAMPP.

> **Time required:** ~30 minutes
> **For supervisor-specific instructions, see [SUPERVISOR-SETUP.md](SUPERVISOR-SETUP.md) instead.**

---

## Prerequisites

1. **XAMPP** with PHP 8.1+ — https://www.apachefriends.org/
2. **A Gmail account** (for sending verification and reset emails)
3. **A web browser** — Chrome, Firefox, or Edge

---

## Step 1 — Install XAMPP

1. Install XAMPP with default options
2. Open **XAMPP Control Panel**
3. Start **Apache** and **MySQL** (both lights should turn green)

If a service fails to start, another program is using port 80 (Apache) or 3306 (MySQL). Stop that program or change ports in XAMPP config.

---

## Step 2 — Place project files

Copy the project into your XAMPP `htdocs` folder:

- **Windows:** `C:\xampp\htdocs\boligmatch\`
- **Mac:** `/Applications/XAMPP/htdocs/boligmatch/`

The folder must be named exactly `boligmatch`.

---

## Step 3 — Download PHPMailer

PHPMailer is a third-party library not bundled with this repo (it has its own license).

1. Go to https://github.com/PHPMailer/PHPMailer
2. Click **Code** → **Download ZIP**
3. Extract the ZIP
4. Copy the 7 PHP files from the `src/` folder
5. Paste them into `boligmatch/backend/PHPMailer/` (create the folder)

Final structure:
```
backend/PHPMailer/
├── PHPMailer.php
├── SMTP.php
├── Exception.php
├── DSNConfigurator.php
├── OAuth.php
├── OAuthTokenProvider.php
└── POP3.php
```

The folder must be named exactly `PHPMailer` with capital P and M.

---

## Step 4 — Set up Gmail App Password

1. Go to https://myaccount.google.com/security
2. Turn ON **2-Step Verification**
3. Go to https://myaccount.google.com/apppasswords
4. App name: `BoligMatch` → click **Create**
5. Copy the 16-character password (shown only once)

> **Security note:** Treat this password like a credit card number. Never commit it to a public Git repo. The `.gitignore` excludes `mailer.php` for this reason.

---

## Step 5 — Configure mailer.php

1. Open `backend/mailer.php`
2. Find these two lines near the top:

```php
$GMAIL_ADDRESS      = '';
$GMAIL_APP_PASSWORD = '';
```

3. Fill in your credentials:

```php
$GMAIL_ADDRESS      = 'your-email@gmail.com';
$GMAIL_APP_PASSWORD = 'abcd efgh ijkl mnop';
```

4. Save the file

---

## Step 6 — Import the database

1. Open http://localhost/phpmyadmin/
2. Click **New** in the left sidebar
3. Database name: `boligmatch` → click **Create**
4. With `boligmatch` selected, click **Import**
5. Select `boligmatch.sql` from the project folder
6. Click **Import** at the bottom

You should see "Import has been successfully finished" in green.

---

## Step 7 — Verify upload folders

These folders must exist:
- `backend/uploads/listings/`
- `backend/uploads/documents/`

The `documents` folder must contain a `.htaccess` file with this single line:
```
Deny from all
```

This prevents direct public access to ownership documents. Both folders are included in the repo with placeholder `.gitkeep` files.

---

## Step 8 — Open the platform

Visit http://localhost/boligmatch/frontend/index.php

You should see the BoligMatch homepage.

### Test the email system

Email is the most fragile part of the setup. Test it before anything else:

1. Click **Sign Up Free**
2. Choose **Student**
3. Register with your real email address
4. Check your inbox for a 6-digit verification code (within 60 seconds)

If no email arrives, see [Troubleshooting](#troubleshooting) below.

---

## Test Accounts


| Role | Email |
|---|---|
| Admin | `admin@boligmatch.local` |
| Owner (verified) | `lars@example.dk` |
| Owner (verified) | `mette@example.dk` |
| Student | `amara@student.dk` |
| Student | `carlos@student.dk` |


Login URLs:
- Regular login: http://localhost/boligmatch/frontend/login.html
- Admin login: http://localhost/boligmatch/backend/admin-login.php

---

## Troubleshooting

| Problem | Cause | Fix |
|---|---|---|
| Apache won't start | Port 80 in use (Skype, IIS) | Close the conflicting program or change Apache to port 8080 |
| MySQL won't start | Port 3306 in use | Same as above |
| Email never arrives | Wrong App Password | Generate a new one at https://myaccount.google.com/apppasswords |
| Email never arrives | 2-Step Verification not enabled | Enable it before creating the App Password |
| Email never arrives | `PHPMailer/` folder missing or wrong location | Must be `backend/PHPMailer/` exactly |
| Email never arrives | `mailer.php` credentials empty | Edit `mailer.php` and fill in `$GMAIL_ADDRESS` and `$GMAIL_APP_PASSWORD` |
| Photos don't show | Upload folders don't exist | Create `backend/uploads/listings/` and `backend/uploads/documents/` |
| Reset link says "expired" immediately | Old version of `forgot-password.php` | Pull latest from repo (expiry is 24 hours) |

To see the actual PHP error, check the XAMPP error log at `C:\xampp\php\logs\php_error_log`.

---

## Removing the project

To completely remove BoligMatch:

1. Stop XAMPP services
2. Delete the folder `htdocs/boligmatch/`
3. In phpMyAdmin: select `boligmatch` → **Operations** → **Drop the database**

No system-level files are installed anywhere outside the project folder.
