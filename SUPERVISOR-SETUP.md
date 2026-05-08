# BoligMatch — Setup Instructions for Supervisor

**For:** Fati Tahiru
**From:** Md Fahim (P2837496)
**Project:** BSc (Hons) Computer Science Final Thesis
**Institution:** Niels Brock Copenhagen Business College

---

## What this document is

This is a step-by-step guide to running BoligMatch on your computer for thesis evaluation. The setup takes about 20–30 minutes.

If you'd prefer not to install anything and just see the platform working, please let me know — I'm happy to record a screen walkthrough or do a live demo during our supervision meeting.

---

## What you'll need

1. **XAMPP** — free software, ~150 MB download. Get it from https://www.apachefriends.org/
2. **A web browser** — Chrome, Firefox, or Edge

You do **not** need to create your own Gmail account. I have set up a dedicated Gmail account for this project and will share its credentials with you below.

---

## Step 1 — Install XAMPP

1. Download and install XAMPP using all default options
2. Open **XAMPP Control Panel**
3. Click **Start** next to:
   - **Apache** (light should turn green)
   - **MySQL** (light should turn green)

If a service fails to start, the most common cause is another program already using its port. Restarting your computer usually resolves this.

---

## Step 2 — Download the project from GitHub

I've shared a private GitHub repository with you.

**Easiest method (no Git knowledge needed):**

1. Open the repository link I sent you
2. Click the green **Code** button → **Download ZIP**
3. Extract the ZIP file
4. Rename the extracted folder to exactly **`boligmatch`**
5. Move it to:
   - **Windows:** `C:\xampp\htdocs\boligmatch\`
   - **Mac:** `/Applications/XAMPP/htdocs/boligmatch/`

Final structure should look like this:
```
htdocs/boligmatch/
├── backend/
├── frontend/
├── css/
├── boligmatch.sql
└── README.md
```

---

## Step 3 — Download PHPMailer

PHPMailer is a third-party email library. It's not in the repo because it has its own license and repository. You only need to download it once.

1. Go to **https://github.com/PHPMailer/PHPMailer**
2. Click the green **Code** button → **Download ZIP**
3. Extract the ZIP
4. Inside the extracted folder, find the **`src/`** folder
5. Copy all **7 PHP files** from `src/`
6. Create a new folder at: `C:\xampp\htdocs\boligmatch\backend\PHPMailer\`
7. Paste the 7 files into that folder

> The folder name must be exactly `PHPMailer` (capital P, capital M). Case matters.

---

## Step 4 — Add the Gmail credentials I provided

I have created a Gmail account specifically for this thesis project. Please use these credentials — they are not connected to my personal email and I will deactivate them after the project is graded.

> **The credentials will be sent to you in a separate, secure message** (in our supervision channel or in person at our next meeting). They are not included in this file because the GitHub repository, even though private, is still indexed by GitHub's infrastructure.

Once you have the credentials:

1. Open the file: `C:\xampp\htdocs\boligmatch\backend\mailer.php`
2. Near the top of the file, you'll see two empty fields:

```php
$GMAIL_ADDRESS      = '';
$GMAIL_APP_PASSWORD = '';
```

3. Paste the credentials I sent:

```php
$GMAIL_ADDRESS      = 'boligmatch.thesis@gmail.com';     // (or whatever I provided)
$GMAIL_APP_PASSWORD = 'xxxx xxxx xxxx xxxx';             // 16-character App Password
```

4. Save the file

---

## Step 5 — Import the database

1. Open your browser and go to **http://localhost/phpmyadmin/**
2. In the left sidebar, click **New**
3. Type the database name: `boligmatch` → click **Create**
4. Make sure `boligmatch` is selected in the left sidebar
5. Click the **Import** tab at the top
6. Click **Choose File** and select `boligmatch.sql` from the project folder
7. Scroll to the bottom and click **Import**

You should see "Import has been successfully finished" in green.

---

## Step 6 — Open the platform

Visit: **http://localhost/boligmatch/frontend/index.php**

You should see the BoligMatch homepage with sample listings.

---

## Step 7 — Log in with test accounts

I have created accounts you can use right away. **Password for all accounts:** `Test1234!`

| Role | Email | Notes |
|---|---|---|
| **Admin** | `admin@boligmatch.local` | Full admin access |
| **Owner** | `lars@example.dk` | Already verified |
| **Owner** | `mette@example.dk` | Already verified |
| **Student** | `amara@student.dk` | Already email-verified |
| **Student** | `carlos@student.dk` | Already email-verified |

**Login URLs:**
- Student/Owner login: http://localhost/boligmatch/frontend/login.html
- Admin login: http://localhost/boligmatch/backend/admin-login.php

**Admin two-factor access code:** `BM-ADMIN-2026`

The admin login asks for the access code first, then for the email and password.

---

## Recommended evaluation walkthrough (10 minutes)

Once everything is running, this is the most efficient way to evaluate the platform:

### 1. Browse as a guest (3 min)
- Visit the homepage
- Click **Browse Rooms** in the navbar
- Open one of the listings — view the photo gallery
- Try to send a message — note that it requires sign-in

### 2. Log in as a student (2 min)
- Sign in as `amara@student.dk` / `Test1234!`
- Look at the home dashboard with available rooms and saved listings
- Open a listing, save it (heart icon), and send a test message

### 3. Log in as an owner (2 min)
- Sign out, sign in as `lars@example.dk` / `Test1234!`
- View the owner dashboard
- Open the inbox and read the message you sent in step 2

### 4. Test the trust mechanisms (3 min)
- Sign out
- **Register a new owner account** using your own email (the one I gave credentials for in Step 4 will receive verification codes)
- Notice that you're required to upload an ownership document before any listing goes public
- Sign in to the admin panel (`admin@boligmatch.local` + access code `BM-ADMIN-2026`)
- Go to the **Ownership** tab — you'll see your test owner's document waiting for review
- Approve or reject the document

You can also test the **scam detection** by sending a message containing a phrase like "wire transfer" or "Western Union" — the message goes through silently to the recipient, but appears flagged in the admin's **Flagged Messages** tab. This is intentional: alerting the sender would just teach them to bypass the filter.

---

## What to skip if you only want a quick look

If you don't want to bother with the email setup:

- **Skip Step 4** entirely
- The platform will work for everything except sending real verification emails
- You can still log in with all 5 test accounts (they're already marked as verified in the database)
- You can browse, message, view photos, use the admin panel, and test the moderation features

The only features that need email are:
- Registering a brand new account (it sends a verification code)
- Forgot password (it sends a reset link)

---

## If something doesn't work

| Problem | Most likely cause | Fix |
|---|---|---|
| Homepage shows "Connection refused" | Apache or MySQL not running | XAMPP Control Panel → Start both |
| Email never arrives | Wrong App Password OR PHPMailer folder missing | Re-check Steps 3 and 4 |
| Login says "incorrect password" with `Test1234!` | Database imported partially | Re-import `boligmatch.sql` fresh |
| Photos don't show on listings | Upload folders don't exist | Create `backend/uploads/listings/` if missing |
| Admin login rejects access code | You typed it wrong | The code is exactly: `BM-ADMIN-2026` (uppercase, with the hyphen) |
| Page shows PHP code instead of website | You opened the file directly instead of via browser | Always use http://localhost/boligmatch/... not file:// |

If you encounter a problem not on this list, please send me a screenshot of the error and the URL you were on — I can usually pinpoint the cause within a few minutes.

---

## Mapping to thesis chapters

| Thesis Chapter | What to look at in the platform |
|---|---|
| **Ch. 1 — Introduction** | The README's problem statement |
| **Ch. 4 — System Analysis & Design** | The database schema (`boligmatch.sql`) and folder structure |
| **Ch. 5 — System Development** | Implementation in `backend/` and `frontend/` |
| **Ch. 6 — Testing & Results** | `TESTING-CHECKLIST.md` (194 tests across 20 sections) |
| **Ch. 7 — Critical Analysis** | `MITID-THESIS-SECTION.md` for the MitID limitation discussion |

For a deeper tour of the project's design, security implementation, and limitations, please see [`SUPERVISOR-README.md`](SUPERVISOR-README.md) in the same folder.

---

## Removing the project after evaluation

When you're finished:

1. Stop XAMPP services
2. Delete the folder `htdocs/boligmatch/`
3. In phpMyAdmin: select `boligmatch` → **Operations** → **Drop the database**

That's it. Nothing is installed system-wide.

---

## Contact

If you get stuck at any step, please contact me through our supervision channel and I will respond as quickly as I can. I'm also happy to do a live screen-share session if that would be faster.

Thank you for taking the time to set this up.

— **Md Fahim** (P2837496)
