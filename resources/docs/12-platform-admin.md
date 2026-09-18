BrookeApp is multi-tenant: each trainer has a private workspace. The **Admin** area, which appears in the sidebar only for administrator accounts, is for the platform owner to support trainers and watch sign-ups. It shows account status and activity counts only. Trainers' clients, balances, payments and reports are never visible to an administrator.

## Overview

**Admin** in the sidebar opens the overview.

![The platform administration overview](/images/docs/admin-dashboard.png)
*Counts only: trainers, sign-ups, activity and sessions, never dollar amounts.*

- **The cards**: trainers signed up (and suspended), new this month versus last, active in the last 30 days with verified and unverified counts, and sessions completed this month with all-time totals.
- **Trainer sign-ups, last 12 months** is a simple bar chart.
- **Needs a hand** lists accounts that registered more than a day ago and never verified their email, so you can reach out.
- **Newest trainers** links to the most recent accounts.

## Trainers

The **Trainers** tab lists every account.

![The trainers list](/images/docs/admin-trainers.png)
*Search by name, email or business, and filter to unverified, suspended, or administrator accounts.*

Click **Open** on a trainer for their support page.

![A trainer's support page](/images/docs/admin-trainer.png)
*Account details, activity counts, and the support actions on the right.*

## Support actions

- **Send password reset email** when a trainer is locked out.
- **Resend verification email**, or **Activate account** to mark the email verified by hand if their mail is not arriving.
- **Suspend account** logs the trainer out, blocks logins, and turns off their clients' wallet links. **Reinstate account** reverses it.
- **Make administrator** or **Revoke administrator access**. You cannot suspend or change the administrator flag on your own account.

## Granting the first administrator

The first administrator is created from the command line on the server:

```
php artisan admin:grant you@example.com
```

After that, administrators can be added and removed from the Trainers page.
