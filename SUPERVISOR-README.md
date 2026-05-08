# BoligMatch — Project Tour for Thesis Evaluation

**For:** Fati Tahiru, Project Supervisor
**From:** Md Fahim (P2837496)

---

## Purpose of this document

This is a guided tour of the BoligMatch platform written specifically for thesis evaluation. It does **not** cover installation — for that, see [`SUPERVISOR-SETUP.md`](SUPERVISOR-SETUP.md). This document focuses on the project's purpose, design, and contribution to academic discussion.

---

## What BoligMatch does

BoligMatch is a peer-to-peer web platform that helps **international students** find affordable, low-deposit room rentals in Denmark. It addresses two specific problems:

### Problem 1 — Affordability

Most Danish landlords ask for **3 months' deposit + first month's rent** before move-in. For a student renting a room at 5,000 DKK/month, that's 20,000 DKK upfront — money international students rarely have before they arrive in the country.

BoligMatch solves this by surfacing **only** listings with deposits of 1–2 months. Owners who want to charge higher deposits can still join the platform but their listings are filtered out of the default search.

### Problem 2 — Trust and scams

Fake rental listings on Facebook Groups and unmoderated marketplaces target international students because they often cannot visit a property in person before committing. There is no central platform that combines low deposits *with* moderated, scam-prevented listings.

BoligMatch's contribution is a **moderated peer-to-peer marketplace** with three trust mechanisms built into the platform:

1. **Ownership verification** — owners must upload a property document (PDF) that an admin reviews before any of their listings become publicly visible
2. **Scam keyword filtering** — all messages are scanned for 24 known scam phrases ("wire transfer", "Western Union", "send money before viewing", etc.) and silently flagged for admin review
3. **Disposable email blocking** — registrations from 15 known temporary-email providers are rejected

---

## How the platform works

### Student journey
1. Browse listings filtered by city, deposit, rent, room type, and amenities
2. Save favourites and view photo galleries
3. Register an account (email verification required)
4. Message owners directly through the platform
5. Report suspicious listings to admin

### Owner journey
1. Register as a Room Owner
2. Verify email address (6-digit code via Gmail SMTP)
3. **Upload ownership document** (PDF, max 5 MB) — required step
4. Wait for admin review (banner shows status: pending / approved / rejected)
5. Once approved, all the owner's pending listings activate automatically
6. Manage listings and inbox from a dashboard

### Admin journey
1. Login at separate URL with two-factor authentication (access code + credentials)
2. Use the 7-tab moderation panel:
   - **Overview** — platform statistics
   - **Reports** — student-submitted reports of suspicious listings
   - **Pending Listings** — listings flagged for review (low rent, unverified owner)
   - **Ownership** — pending document reviews (the central feature)
   - **Users** — manage accounts (ban / unban / verify / delete)
   - **Flagged Messages** — messages caught by the scam keyword filter
   - **All Listings** — global moderation view
3. Approve or reject ownership documents through a protected viewer (direct file access is blocked at the server level)

---

## Mapping to thesis chapters

| Thesis Chapter | Where to look in the project |
|---|---|
| **Ch. 1 — Introduction** | The "What BoligMatch does" section above |
| **Ch. 2 — Literature Review** | See thesis bibliography |
| **Ch. 3 — Methodology** | Literature-based and platform-analysis study (no primary research) |
| **Ch. 4 — System Analysis & Design** | Database schema in `boligmatch.sql`; folder structure in [`README.md`](README.md) |
| **Ch. 5 — System Development** | Implementation in `backend/` and `frontend/`; key files listed below |
| **Ch. 6 — Testing & Results** | [`TESTING-CHECKLIST.md`](TESTING-CHECKLIST.md) — 178 manual test cases across 19 sections |
| **Ch. 7 — Critical Analysis** |   The MitID limitation discussion |

---

## Security features implemented

Each of the following can be tested using the testing checklist:

- **Bcrypt password hashing** (cost 12) — `register.php`, line ~155
- **PDO prepared statements with `ATTR_EMULATE_PREPARES => false`** — `db.php`, line ~25
- **CSRF tokens on all POST forms** — `db.php` `csrfToken()` helper
- **Brute-force protection** (5 attempts → 15-minute lockout) — `login.php`, line ~95
- **Math CAPTCHA** — `captcha.php` and `login.html`
- **Session fixation prevention** — `session_regenerate_id()` called on login
- **Timing-safe token comparison** — `hash_equals()` for password reset tokens
- **Rate-limited messaging** (5 per 10 min) and **reporting** (3 per hour)
- **MIME type validation on uploads** — `create-listing.php` and `upload-document.php`
- **Scam keyword detection** — 24 phrases in `send-message.php`, silent flagging
- **Disposable email blocking** — 15 known temp-mail domains in `register.php`
- **Server-side document access control** — `view-document.php` checks `role = admin`
- **Apache-level access denial** — `.htaccess` in `uploads/documents/` blocks direct downloads

---

## Files of interest for code review

If you'd like to read specific code, these are the most thesis-relevant files:

| File | Why it matters |
|---|---|
| `backend/db.php` | Database connection and shared helper functions (CSRF, escape, etc.) |
| `backend/register.php` | Registration with disposable-email blocking and email verification |
| `backend/login.php` | Login with CAPTCHA and brute-force protection |
| `backend/upload-document.php` | Owner ownership PDF upload with MIME validation |
| `backend/admin.php` | Admin moderation panel (7 tabs) |
| `backend/send-message.php` | Messaging with scam keyword filter |
| `backend/view-document.php` | Protected document viewer for admin only |
| `boligmatch.sql` | Full database schema (5 tables) and sample data |

---

## Limitations and future work (Chapter 7 highlights)

### MitID is not implemented

Danish national digital ID (MitID) cannot be implemented in an undergraduate thesis prototype because:

1. Integration requires a Danish business CVR registration
2. A certified broker contract (Criipto, Signaturgruppen, or Scrive) is required — these are commercial paid services
3. NSIS Substantial compliance audits would be needed
4. GDPR sensitive personal data (CPR numbers) cannot legally be processed by an academic prototype
5. Localhost development is incompatible with MitID's OAuth callback requirements (HTTPS-only, registered domains)

This is documented in detail in  discussed in Chapter 7. **MitID integration is recommended as the top priority future enhancement.**

### Scam detection is keyword-based, not AI

The current implementation flags messages containing any of 24 known scam phrases. This is **transparent, explainable, and sufficient for an undergraduate thesis prototype** — every flag can be traced to a specific rule. A production system would benefit from machine-learning-based detection trained on real scam datasets, but ML adds opacity that is undesirable in a thesis defense context.

### No payment processing

BoligMatch is a **discovery and contact platform**, not a rental management or payment system. Students still need to handle deposits and rent payments through trusted methods outside the platform. This is intentional — adding payment processing would require a banking license or a Stripe Connect account, both well out of scope for academic work.

### Localhost only

The platform runs on XAMPP (Apache + MySQL + PHP) on a developer's machine. It is not deployed to a public server. A real deployment would need:
- A registered domain
- HTTPS via Let's Encrypt or similar
- A production-grade transactional email service (SendGrid, AWS SES)
- Database backups and monitoring
- DDoS and bot protection (Cloudflare)

These deployment concerns are noted in Chapter 7 but considered out of scope for the thesis prototype.

---

## Why I chose vanilla PHP instead of a framework

This project deliberately uses **vanilla PHP and MySQL** instead of a framework like Laravel or Symfony. The reason — discussed in Chapter 5 — is that an undergraduate thesis prototype benefits from being **simple and explainable** at defense rather than impressive but opaque.

Every piece of functionality in this project is implementable in pure PHP and can be defended by the student without needing to explain a framework's internal magic. This trades developer convenience for academic defensibility, which is the right trade-off for a thesis project.

---



## Status of the project as of submission

### Completed
- Full platform implementation across 29 PHP files
- 178-test manual testing checklist (173 pass, 5 not yet tested)
- Email verification via Gmail SMTP
- Password reset via email with 24-hour expiry
- Owner ownership document upload + admin review workflow
- 7-tab admin panel
- All security features listed above


## Contact

For any questions about the platform's behavior or code, please reply through the agreed supervision channel.

Thank you for your supervision and feedback throughout this project.

— **Md Fahim** (P2837496@my365.dmu.ac.uk) 
