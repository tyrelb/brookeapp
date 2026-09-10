Logging a session is the moment money moves. You say who came, the app picks the right rate for that group size, and each person's share plus GST is deducted from their Fitness Wallet. Monthly members are recorded too, so their history is complete, but they are not charged per session.

## Log a session that already happened

Click **Log session** on the dashboard, the Sessions page, or a client's page.

![The Log session form](/images/docs/log-session.png)
*The empty form. Service, date and time are pre-filled with sensible defaults.*

1. Check the **Service**, **Date**, **Start time** and **Duration**. Changing the service updates the duration to that service's default.
2. If you train at more than one gym, a **Gym** field appears above these; with a single gym it is filled in for you. The gym is needed so the session can be reconciled against your gym invoice.
3. **Add clients** by clicking names in the list, or search first. As you add people, the **Attendees** table on the right fills in.

![The form with two attendees and the charge preview](/images/docs/log-session-partner.png)
*Two attendees make it a Partner session. Each person is charged the partner rate from their own plan, plus GST.*

4. The badge above the table shows the **tier** ("Partner session · 2 people"). Groups of five or more are named by their size and billed at the Quad rate. Each attendee row shows what they will be charged, worked out from *their* plan, so two people on different plans pay different amounts for the same hour.
5. Untick **Attended** for a no-show. They stay on the record but are not charged, and the tier drops to match the people who did come.
6. **A family client** adds one row with its members listed underneath. Tick the ones who came; **select all** ticks the lot. The tier counts people, not rows, so two Barnes members and one other client make it a Triple session for all three, and the family's wallet is charged twice at the triple rate. A family with nobody ticked is not charged, and the form asks you to pick someone before it will complete.
7. Use **Price override** to charge someone a different before-GST amount just this once, for example a trial rate. Family members have their own override each, so one child can be priced differently without touching the rest. The preview updates as you type.

![The form with a price override on one attendee](/images/docs/log-session-override.png)
*A one-off override for Ava. Ben is still on his plan rate.*

8. Tick **Email attendees a receipt** if you want each person to get a summary and their remaining balance. The default comes from Settings → Business. A family gets one email, listing who trained and what each of them cost.
9. Click **Complete & charge**. The charges are posted and you are taken to the session page.

> **Tip:** **Save as scheduled** stores the session without charging anyone. Use it for a session that is still in the future, or if you want to finish the attendance list later.

> **Important:** If a client's plan has no rate for the service, the form says so instead of guessing, and will not charge until you add the rate to the plan or use an override.

## Covering a gym's own clients

When the gym owner goes on vacation and asks you to train **their** clients, the money runs backwards: the gym pays you, and you owe it nothing for the space.

Set up what the gym pays you first, in **Settings → Gyms** (see Settings). Once that is saved, a tick box appears on **Log session** and **Book session**:

1. Tick **I'm covering the gym's own clients.**
2. Pick the gym you are covering for. This is required even if you only have one gym, because the credit has to land on that gym's statement.
3. Type the names of the people you trained, one per line, using **Add another person** for more. **How many names you type is what sets the rate** — one person might be $50, two might be $70.
4. The green line shows what the gym will owe you, GST included, before you save.

These names are plain text. They are not clients, they get no wallet, no ledger entry and no email, and they never appear in your client list — which is the point, since the gym's clients change every time.

The fee is worked out and stored when the session is completed, so changing the rate card later never restates a month you have already reported on. Reopening a cover session clears the fee and keeps the names; completing it again re-prices at the current rate.

Cover sessions show up in three places: on that gym's usage report as a credit that reduces what you owe, in **Reports → Monthly** and **Annual** as revenue and GST you charged, and on your dashboard's revenue figure.

> **Note:** A cover session cannot also include your own clients. If you trained a mix, log two sessions — otherwise it is ambiguous whether you owe the gym usage for your own client that hour.

## The Sessions list

**Sessions** in the sidebar lists everything you have logged or booked, newest first.

![The Sessions list](/images/docs/sessions.png)
*Scheduled, Completed and Cancelled sessions with their tier and what was charged. The repeat icon marks sessions that belong to a repeating booking.*

Filter by **status** or pick a **month**. Click a date to open the session.

## A completed session

![A completed session page](/images/docs/session-completed.png)
*A completed session: who came, what each person was charged, and the gym it counts toward.*

- **Email receipts** sends (or resends) the receipt to everyone who attended and has an email address.
- **Reopen** is how you fix a mistake. It voids every charge from this session so you can correct the attendance, then charge again.
- The **Gym** panel lets you change which gym the session counts toward, or untick **Counts toward gym usage** if the gym should not charge you for it.

## Fix a session with Reopen

1. Open the session and click **Reopen**. Confirm the prompt. The charges are voided and the session goes back to Scheduled.

![The same session after Reopen, with attendance editable again](/images/docs/session-reopen.png)
*After Reopen you can tick and untick attendees, add people, set overrides, and complete again.*

2. Correct the attendance: untick a no-show, add someone you forgot with **Add clients**, or set an override.
3. Click **Complete & charge**. New charges are posted at the corrected tier. The voided charges stay visible in each client's ledger, struck through, so the trail is clear.
