Reports turn the ledger into numbers you can act on: what you earned, what you received, how much GST is involved, and what you owe each gym. Every report is built from the same ledger the client pages show, so they always agree.

## Monthly report

**Reports → Monthly report.** Use the arrows or the month picker to move between months.

![The monthly report for August](/images/docs/report-monthly.png)
*August for the demo trainer.*

- **The four cards**: sessions completed (with attendances), revenue before GST split into sessions and monthly fees, GST charged, and payments received net of refunds. Attendances count people, so a family of three that trained together counts as three.
- **Sessions by service** groups completed sessions by service and tier. Revenue here counts pay-as-you-go charges only; members' sessions are included in their fee.
- **Money received** is the cash basis: payments dated in this month, by method, with the GST portion of that money worked out at 5/105.
- **Gym cover fees** count as revenue and as GST you charged, on the day you trained the gym's clients. They never show under *Money received*: the gym settles by crediting the amount against its own invoice rather than paying you cash, so the accrual and cash figures differ by that amount until you reconcile the gym's statement.
- **GST summary** shows both bases side by side. *Accrual* is the GST on what you charged. *Cash* is the GST embedded in what you actually received. Prepaid wallet deposits make the two differ, because money arrives before the sessions it pays for. Ask your accountant which basis to remit on; both are tracked.
- **Balances at month end**: the prepaid credit you are holding for clients (a liability) and what clients owe you.

## Annual report

**Reports → Annual report.** Pick the tax year with the arrows.

![The annual report for 2026](/images/docs/report-annual.png)
*One row per month with a total line. Every column is in the CSV.*

The table has sessions, session revenue, monthly fees, revenue, GST charged, money received by each payment method, refunds, net received, prepaid credit held and amounts owing. **Export CSV** downloads the whole year for your accountant.

## Gym usage report

**Reports → Gym usage** shows what you owe a gym for a month, laid out to match the invoice the gym sends you. Pick the gym (if you have more than one) and the month.

![The gym usage report for August, in draft](/images/docs/report-gym-usage.png)
*A draft month. Every session counts until you untick it.*

- **The cards**: sessions counted, people trained, usage charges (with the monthly rate underneath) and the total owed including GST. Family members count individually here too, because the gym charges by how many bodies were in the room.
- **Summary by group size** shows how many sessions of each size, the hours logged and the hourly rate for each, using the gym's rate card from Settings.
- **Amount owed** adds the usage charges, monthly rate and GST.
- **Detail** lists every completed session at that gym that had someone in the room, with its date, time, group size, length, hourly rate and charge. Sessions are billed pro-rata by length, so two sessions of the same size can cost different amounts. **Untick** a row to exclude a session the gym should not charge for; the totals update immediately.

Group size counts the people who were **in the room**. A client marked **Missed / late cancel** pays you, but the gym doesn't charge for them. A session nobody turned up to isn't listed at all, because the gym has nothing to charge for. Sessions logged with **Charge *gym* for this session** unticked arrive already unticked here.

> **Note:** Months you already finalized keep their snapshot. Reopening and re-finalizing an old month recalculates it with these rules, so an old all-no-show session that was billed at the one-person rate drops out.

If some completed sessions have no gym, a notice offers to assign them to the selected gym in one click.

### Covering the gym's clients

When the gym owner is away and asks you to train **their** clients, the money goes the other way: the gym pays you, and you owe it nothing for the space. Log those as **cover sessions** (see Sessions), and they appear here in their own section, **Covering *gym*'s clients**, below the usage detail.

The section lists each cover session with who you trained, how many people, and what you charge. Its total is subtracted from what you owe, so the headline figure is the **net**. If you covered more than you trained your own clients that month, the total goes negative and reads *the gym owes you*.

Unticking a cover session takes it off the statement **and** out of your revenue — it was never invoiced, so it is not income.

> **Watch the two GST lines.** The GST on the usage charges is tax the *gym charges you*, which you claim back as an input tax credit. The GST on the cover fees is tax *you charge the gym*, which you collect and remit. They can even be different percentages. That is why the report never nets the before-tax amounts together — each side keeps its own tax, and only the totals are subtracted.

### Finalize a month

When the report matches the gym's invoice, click **Finalize**. The month is locked and a snapshot is stored, so later edits to sessions do not change what you agreed to pay.

![The same month after Finalize](/images/docs/report-gym-usage-finalized.png)
*A finalized month shows when it was locked. The checkboxes are disabled.*

**Reopen** unlocks it if something needs to change. **CSV** downloads the detail table and **Print** gives a clean printable layout, useful to keep with the invoice.

> **Tip:** GST you pay to the gym is an input tax credit on your own GST return. The report shows it separately for that reason.
