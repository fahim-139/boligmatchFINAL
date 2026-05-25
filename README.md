# BoligMatch

A peer-to-peer web platform connecting international students with affordable, low-deposit room rentals in Denmark — with built-in scam prevention.

> Final-year BSc (Hons) Computer Science thesis project at De Montfort University.
> **Student:** Md Fahim · **Supervisor:** Fati Tahiru

---

## Problem

International students arriving in Denmark face two problems when finding a room:

1. **High deposits** — most landlords ask for 3 months of rent upfront, plus first month's rent (around 24,000–32,000 DKK before they've even moved in).
2. **Rental scams** — fake listings on unmoderated marketplaces target students who can't visit a property in person before paying.

BoligMatch addresses both problems with a moderated peer-to-peer marketplace where students see only **low-deposit listings** and owners must pass an **identity-verification step** before their listings go live.

---

## Key Features

### For Students
- Browse listings filtered by city, deposit, rent, room type, and amenities
- Save favourites and view photo galleries
- Message owners directly through the platform
- Report suspicious listings

### For Owners
- Create unlimited listings with up to 5 photos each
- Email verification + ownership document review (admin-approved)
- Inbox for student enquiries

### For Admins
- 7-tab moderation panel (Reports, Pending Listings, Ownership, Users, Flagged Messages, etc.)
- Two-factor admin login (access code + credentials)
- Protected document viewer for ownership PDFs

### Trust & Safety Built-In
- Bcrypt password hashing (cost 12)
- PDO prepared statements
- CSRF tokens on all forms
- Brute-force protection (5 attempts → 15-min lockout)
- Math CAPTCHA on login
- Rate limiting on messaging and reports
- Scam keyword detection (24 phrases) — silently flags suspicious messages
- Disposable email blocking (15 known temp-mail providers)
- MIME validation on all file uploads
- Server-level access control on uploaded documents

---

## Tech Stack

| Layer | Technology |
|---|---|
| Server | XAMPP (Apache + MySQL + PHP 8.1+) |
| Backend | Vanilla PHP (no frameworks — chosen for thesis simplicity and defensibility) |
| Database | MySQL with PDO |
| Frontend | HTML5, CSS3, JavaScript (no build step) |
| Email | Gmail SMTP via PHPMailer |
| Design | Custom palette: Navy `#0d1b2a`, Teal `#1a7f6e`, Cream `#f7f3ee`, Gold `#c9a84c` |

---

## Installation

For step-by-step setup instructions, see **[SETUP.md](SETUP.md)**.

If you are the project supervisor, see **[SUPERVISOR-SETUP.md](SUPERVISOR-SETUP.md)** for a tailored quick-start.

---

## Project Structure

```
boligmatch/
├── backend/                Server-side PHP files
│   ├── PHPMailer/         Email library (download separately)
│   ├── uploads/           User-uploaded photos and documents
│   ├── admin.php          Admin moderation panel
│   ├── db.php             Database connection + helpers
│   ├── login.php          Login with CAPTCHA
│   ├── mailer.php         Email helper (configure with your Gmail)
│   └── ...
├── frontend/              Public pages (HTML and PHP)
├── css/                   Stylesheets
├── boligmatch.sql         Full schema + sample data
└── *.md                   Documentation
```

---

## Documentation

| File | For Whom |
|---|---|
| [`README.md`](README.md) | Anyone visiting the repo |
| [`SETUP.md`](SETUP.md) | Anyone installing the project locally |
| [`SUPERVISOR-SETUP.md`](SUPERVISOR-SETUP.md) | Project supervisor — quick install |
| [`SUPERVISOR-README.md`](SUPERVISOR-README.md) | Project supervisor — thesis evaluation |
| [`TESTING-CHECKLIST.md`](TESTING-CHECKLIST.md) | 194-test manual test plan |
| [`LICENSE.md`](LICENSE.md) | Academic-use license |

---

## Limitations

This is an academic prototype, not a production deployment. The thesis Chapter 7 (Critical Analysis) discusses:

- **No MitID integration** — Danish national digital ID requires a CVR business registration and a certified broker contract. Out of scope for an undergraduate thesis. Recommended as the top future enhancement.
- **Localhost only** — runs on XAMPP, not a public server.
- **Keyword-based scam detection** — transparent and explainable. A future version could use machine learning.
- **No payment integration** — BoligMatch is a discovery and contact platform, not a rental management system.

---

## License

Released for academic and educational use only. See [`LICENSE.md`](LICENSE.md).

---

## Acknowledgements

- **Fati Tahiru** — project supervisor
- **De Montfort University** — institution
- **PHPMailer** team — for the SMTP library
