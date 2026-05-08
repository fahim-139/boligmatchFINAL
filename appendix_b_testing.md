# Appendix B — Testing Checklist

| Tester | Md Fahim | Date | 24.04.26 |
|--------|----------|------|----------|
| Browser | Chrome | Device | Desktop |

---

## Test Accounts

> ⚠️ Passwords and admin access code are withheld from this file for security.  
> Test credentials are available in the private project documentation submitted with the thesis.

| Role | Email | Password | Email Verified | Ownership |
|------|-------|----------|----------------|-----------|
| Admin | admin@boligmatch.local | [withheld] | Yes | N/A |
| Owner | lars@example.dk | [withheld] | Yes | Yes (auto) |
| Owner | mette@example.dk | [withheld] | Yes | Yes (auto) |
| Student | amara@student.dk | [withheld] | Yes | N/A |
| Student | carlos@student.dk | [withheld] | Yes | N/A |

*Mark each test: Pass | Fail | Partial*

---

## 0. Setup Verification

| # | Test | Status |
|---|------|--------|
| 0.1 | XAMPP Apache is running | Pass |
| 0.2 | XAMPP MySQL is running | Pass |
| 0.3 | boligmatch database exists in phpMyAdmin | Pass |
| 0.4 | migrate-ownership.sql has been run | Pass |
| 0.5 | PHPMailer folder exists in backend/PHPMailer/ | Pass |
| 0.6 | Email credentials configured in backend/mailer.php | Pass |
| 0.7 | uploads/documents/ folder exists with .htaccess blocking access | Pass |
| 0.8 | uploads/listings/ folder exists | Pass |
| 0.9 | http://localhost/boligmatch/frontend/index.html loads without errors | Pass |

---

## 1. Email Configuration Test

| # | Test | Status |
|---|------|--------|
| 1.1 | Register a new account using a real email address | Pass |
| 1.2 | Email arrives in inbox within 1 minute | Pass |
| 1.3 | Email contains a 6-digit verification code | Pass |
| 1.4 | Email is from BoligMatch with correct subject line | Pass |

---

## 2. Landing Page (frontend/index.html)

| # | Test | Status |
|---|------|--------|
| 2.1 | Page loads with CSS styling | Pass |
| 2.2 | Navbar links display correctly | Pass |
| 2.3 | Hero section displays with search box | Pass |
| 2.4 | Listing cards load from database | Pass |
| 2.5 | Search box: city + deposit filter works | Pass |
| 2.6 | Footer links work | Pass |

---

## 3. Student Registration + Email Verification

| # | Test | Status |
|---|------|--------|
| 3.1 | Open register.html and select Student | Pass |
| 3.2 | Fill all fields with valid data | Pass |
| 3.3 | Submit redirects to verify-otp.php | Pass |
| 3.4 | Page shows 'Check your email' message with masked address | Pass |
| 3.5 | Email arrives with 6-digit code | Pass |
| 3.6 | Wrong code shows 'Incorrect code' error | Pass |
| 3.7 | Correct code shows 'Email Verified!' success page | Pass |
| 3.8 | Continue redirects to home-student.php | Pass |
| 3.9 | Database: email_verified = 1 for that user | Pass |
| 3.10 | Resend Code link sends a new email | Pass |
| 3.11 | Old code no longer works after resending | Pass |

---

## 4. Owner Registration + Document Upload

| # | Test | Status |
|---|------|--------|
| 4.1 | Open register.html and select Room Owner | Pass |
| 4.2 | Fill all fields with valid data | Pass |
| 4.3 | Submit redirects to upload-document.php | Pass |
| 4.4 | Page shows 'Verify Property Ownership' with PDF upload area | Pass |
| 4.5 | Verification code email arrives in inbox | Pass |
| 4.6 | JPG/PNG upload rejected with error | Pass |
| 4.7 | File over 5 MB rejected with error | Pass |
| 4.8 | Click upload zone opens file picker | Pass |
| 4.9 | Valid PDF shows filename + size preview | Pass |
| 4.10 | Submit button enables only after file selected | Pass |
| 4.11 | Drag-and-drop PDF works | Pass |
| 4.12 | Upload shows success message | Pass |
| 4.13 | Status changes to 'Pending Review' badge | Pass |
| 4.14 | Database: ownership_document filled, ownership_verified = 0 | Pass |
| 4.15 | File saved in uploads/documents/ with randomised name | Pass |
| 4.16 | Direct URL to PDF returns 403 Forbidden | Pass |
| 4.17 | 'Continue to Dashboard' goes to home-owner.php | Pass |
| 4.18 | 'Skip for now' link works | Pass |

---

## 5. Owner Dashboard Banners

| # | Test | Status |
|---|------|--------|
| 5.1 | New owner (no document): yellow upload banner | Pass |
| 5.2 | Document uploaded, not reviewed: teal banner | Pass |
| 5.3 | Document rejected: red banner | Pass |
| 5.4 | Verified owner: no ownership banner | Pass |
| 5.5 | Email-unverified owner: yellow email banner also shown | Pass |
| 5.6 | 'Upload now' link goes to upload-document.php | Pass |

---

## 6. Unverified Owner Creating Listing

| # | Test | Status |
|---|------|--------|
| 6.1 | Unverified owner creates a new listing | Pass |
| 6.2 | Listing saved with status = pending | Pass |
| 6.3 | Listing does not appear on public listings.php | Pass |
| 6.4 | Listing does not appear on homepage | Pass |
| 6.5 | Owner can see listing in their own dashboard | Pass |
| 6.6 | Listing detail accessible via direct URL but shows pending status | Pass |

---

## 7. Admin Ownership Review

| # | Test | Status |
|---|------|--------|
| 7.1 | Login to admin panel | Pass |
| 7.2 | Sidebar shows 'Ownership' tab | Pass |
| 7.3 | Green badge with count shown if pending docs exist | Pass |
| 7.4 | Ownership tab shows pending owners table | Pass |
| 7.5 | Table columns display correctly | Pass |
| 7.6 | 'View PDF' opens document in new tab | Pass |
| 7.7 | 'Approve' shows confirmation dialog | Pass |
| 7.8 | Confirm approval shows success message | Pass |
| 7.9 | Database: ownership_verified = 1 | Pass |
| 7.10 | Owner's pending listings set to active | Pass |
| 7.11 | Listings appear publicly on listings.php | Pass |
| 7.12 | Owner no longer sees pending review banner | Pass |
| 7.13 | 'Reject' shows confirmation dialog | Pass |
| 7.14 | Confirm rejection shows success message | Pass |
| 7.15 | Database: ownership_document = NULL, ownership_rejected = 1 | Pass |
| 7.16 | PDF deleted from uploads/documents/ | Pass |
| 7.17 | Owner sees red rejected banner on next login | Pass |
| 7.18 | Owner can upload new document via banner link | Pass |

---

## 8. Login + CAPTCHA

| # | Test | Status |
|---|------|--------|
| 8.1 | Login page loads with CSS styling | Pass |
| 8.2 | Math CAPTCHA question appears | Pass |
| 8.3 | Wrong CAPTCHA answer shows error and new question | Pass |
| 8.4 | Wrong password (correct CAPTCHA) shows error | Pass |
| 8.5 | Student login redirects to home-student.php | Pass |
| 8.6 | Owner login redirects to home-owner.php | Pass |
| 8.7 | Wrong role tab selected shows role mismatch error | Pass |
| 8.8 | 5 failed attempts locks account for 15 minutes | Pass |

---

## 9. Forgot Password

| # | Test | Status |
|---|------|--------|
| 9.1 | 'Forgot password?' modal opens | Pass |
| 9.2 | Enter email, click 'Send Reset Link' | Pass |
| 9.3 | Modal shows 'Email sent!' message | Pass |
| 9.4 | Reset email arrives in inbox | Pass |
| 9.5 | Reset link opens reset form | Pass |
| 9.6 | New password accepted, success shown | Pass |
| 9.7 | Login with new password works | Pass |
| 9.8 | Old password no longer works | Pass |
| 9.9 | Reset link expires after 1 hour | Pass |

---

## 10. Student Home (home-student.php)

| # | Test | Status |
|---|------|--------|
| 10.1 | Page loads after student login | Pass |
| 10.2 | Welcome message shows student's first name | Pass |
| 10.3 | Unverified email shows yellow banner with resend link | Pass |
| 10.4 | Stats cards display correctly | Pass |
| 10.5 | Search bar filters work | Pass |
| 10.6 | City pills filter rooms | Pass |
| 10.7 | Room card click goes to listing-detail.php | Pass |
| 10.8 | Saved rooms section shows bookmarked listings | Pass |
| 10.9 | Quick links and nav buttons work | Pass |

---

## 11. Browse Listings (listings.php)

| # | Test | Status |
|---|------|--------|
| 11.1 | Only active listings shown | Pass |
| 11.2 | Search bar filters by keyword | Pass |
| 11.3 | City, deposit, rent, room type filters work | Pass |
| 11.4 | Amenity checkboxes work | Pass |
| 11.5 | Sort dropdown works | Pass |
| 11.6 | Pagination works | Pass |
| 11.7 | Save button works for logged-in students | Pass |

---

## 12. Listing Detail + Photo Gallery

| # | Test | Status |
|---|------|--------|
| 12.1 | Single photo listing shows photo, no arrows | Pass |
| 12.2 | Multi-photo listing shows navigation arrows | Pass |
| 12.3 | Arrow buttons cycle photos | Pass |
| 12.4 | Photo counter displays correctly | Pass |
| 12.5 | Keyboard arrow keys navigate photos | Pass |
| 12.6 | Owner info, amenities, rent details display | Pass |
| 12.7 | Send message form works | Pass |
| 12.8 | Save/unsave button toggles | Pass |
| 12.9 | Report button opens modal and submits report | Pass |

---

## 13. Create Listing (verified owner)

| # | Test | Status |
|---|------|--------|
| 13.1 | Verified owner can access page | Pass |
| 13.2 | All form fields display | Pass |
| 13.3 | Title under 10 chars shows error | Pass |
| 13.4 | Description under 50 chars shows error | Pass |
| 13.5 | Rent outside 1,000–30,000 DKK shows error | Pass |
| 13.6 | Photo upload (JPG/PNG/WebP) works | Pass |
| 13.7 | Multiple photo upload works | Pass |
| 13.8 | Suspiciously low rent sets status = pending | Pass |
| 13.9 | Normal rent from verified owner sets status = active | Pass |

---

## 14. Edit Listing

| # | Test | Status |
|---|------|--------|
| 14.1 | Page loads with pre-filled data | Pass |
| 14.2 | Edit title, save, updated in DB | Pass |
| 14.3 | Toggle active/expired works | Pass |
| 14.4 | Delete listing removes from DB | Pass |
| 14.5 | Cannot edit another owner's listing | Pass |

---

## 15. Messaging

| # | Test | Status |
|---|------|--------|
| 15.1 | Inbox loads with conversation threads | Pass |
| 15.2 | Click thread loads messages | Pass |
| 15.3 | Send reply appears immediately | Pass |
| 15.4 | New messages auto-appear (polling) | Pass |
| 15.5 | Unread badge shows in nav | Pass |
| 15.6 | Cannot message yourself | Pass |
| 15.7 | Scam keyword in message flagged in DB (sender not notified) | Not yet tested |

---

## 16. Dashboard (dashboard.php)

| # | Test | Status |
|---|------|--------|
| 16.1 | All tabs load (Overview, Listings, Inbox, Profile) | Pass |
| 16.2 | Edit profile and save works | Pass |
| 16.3 | Change password works | Pass |
| 16.4 | Wrong current password shows error | Pass |
| 16.5 | Mismatched new passwords show error | Pass |
| 16.6 | Delete account opens confirmation modal | Pass |
| 16.7 | Delete account with password verification removes account | Pass |
| 16.8 | After deletion, logged out and redirected to homepage | Pass |

---

## 17. Admin Panel — All Tabs

| # | Test | Status |
|---|------|--------|
| 17.1 | Wrong access code shows error with attempts remaining | Pass |
| 17.2 | Correct access code proceeds to step 2 | Pass |
| 17.3 | Correct credentials load admin panel | Pass |
| 17.4 | Overview stats display | Pass |
| 17.5 | Activity feed shows recent events | Pass |
| 17.6 | Reports tab shows open reports | Pass |
| 17.7 | Report actions (resolve, dismiss, ban) work | Pass |
| 17.8 | Pending listings tab shows pending listings | Pass |
| 17.9 | Approve listing sets status to active | Pass |
| 17.10 | Reject listing sets status to expired | Pass |
| 17.11 | Ownership tab shows pending documents | Pass |
| 17.12 | 'View PDF' opens document in new tab | Pass |
| 17.13 | Approve ownership verifies owner and activates listings | Pass |
| 17.14 | Reject ownership deletes document and notifies owner | Pass |
| 17.15 | Users tab: list, search, filter work | Pass |
| 17.16 | Ban / unban / verify / delete user actions work | Pass |
| 17.17 | Cannot delete own admin account | Not yet tested |
| 17.18 | Flagged messages display in Messages tab | Not yet tested |
| 17.19 | Dismiss / delete flagged message actions work | Not yet tested |
| 17.20 | All listings display with status filter | Pass |

---

## 18. Security

| # | Test | Status |
|---|------|--------|
| 18.1 | Unauthenticated users redirected to login | Pass |
| 18.2 | Students cannot access owner pages | Pass |
| 18.3 | Non-admins cannot access admin.php | Pass |
| 18.4 | Banned users cannot login | Pass |
| 18.5 | SQL injection in login blocked | Not yet tested |
| 18.6 | Direct PDF access in uploads/documents/ returns 403 | Pass |

---

## 19. Logout

| # | Test | Status |
|---|------|--------|
| 19.1 | Sign Out redirects to homepage | Pass |
| 19.2 | Protected pages after logout redirect to login | Pass |
| 19.3 | Browser back button does not show protected pages | Pass |

---

## Test Results Summary

| Section | Total | Passed | Not Tested |
|---------|-------|--------|------------|
| 0. Setup Verification | 9 | 9 | 0 |
| 1. Email Configuration | 4 | 4 | 0 |
| 2. Landing Page | 6 | 6 | 0 |
| 3. Student Registration | 11 | 11 | 0 |
| 4. Owner Registration | 18 | 18 | 0 |
| 5. Owner Dashboard Banners | 6 | 6 | 0 |
| 6. Unverified Owner Listings | 6 | 6 | 0 |
| 7. Admin Ownership Review | 18 | 18 | 0 |
| 8. Login + CAPTCHA | 8 | 8 | 0 |
| 9. Forgot Password | 9 | 9 | 0 |
| 10. Student Home | 9 | 9 | 0 |
| 11. Browse Listings | 7 | 7 | 0 |
| 12. Listing Detail + Gallery | 9 | 9 | 0 |
| 13. Create Listing | 9 | 9 | 0 |
| 14. Edit Listing | 5 | 5 | 0 |
| 15. Messaging | 7 | 6 | 1 |
| 16. Dashboard | 8 | 8 | 0 |
| 17. Admin Panel | 20 | 17 | 3 |
| 18. Security | 6 | 5 | 1 |
| 19. Logout | 3 | 3 | 0 |
| **TOTAL** | **178** | **173** | **5** |

---

*Tested by: Md Fahim — 24 April 2026*
