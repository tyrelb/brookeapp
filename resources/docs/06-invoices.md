Recording a payment is for money that has already arrived. When you want to *ask* for money, send an invoice. BrookeApp numbers it (INV-0001, INV-0002 and so on), emails it to the client with your payment details, keeps track of what has come in against it, and can chase it for you.

## Request payment

Open the client and click **Request payment**.

![The Request payment dialog](/images/docs/client-request-payment.png)
*The amount is suggested from the client's plan and can be changed before sending.*

1. **Amount (before GST).** A suggestion is shown underneath, worked out from the plan, with the GST and total it comes to:
    - A **monthly member** is asked for the monthly fee plus GST, whatever their balance.
    - A **pay-as-you-go** or **family** client is asked for a package: the plan's **package size** times its single-person rate, plus GST. Set the package size on the plan (Pricing → Plans). With no package size the amount starts empty and you type it.
2. **Issued** and **Due** dates; due is a week away by default.
3. A **Message** to the client, such as "This covers your next 20 sessions." It goes in the email as its own paragraph.
4. Click **Send request**. The invoice is emailed and appears under **Payment requests** on the client's page with the status **Sent**.

![The Payment requests table on a client page](/images/docs/client-payment-requests.png)
*Every invoice for this client, with what has been received against it.*

The email shows the breakdown with GST, your accepted payment methods and e-Transfer address, and a link to the client's wallet page, which lists anything still outstanding. If you changed the suggested amount, the breakdown collapses to a single line so the client never sees arithmetic that no longer adds up.

## How an invoice gets paid

An invoice is never "marked" paid behind the scenes. Whether it is paid is worked out from the payments linked to it, so it always agrees with the ledger:

- When you **Record payment** for a client with an open invoice, the dialog offers the oldest open one under **Against a request**. Leave it selected and the invoice settles itself. The ledger row names the invoice.
- A **part payment** leaves the invoice partly paid; the row shows what has been received.
- An **overpayment** settles it, and the surplus stays as wallet credit.
- If you later **edit** or **void** that payment, the invoice follows: it reopens if the money is no longer there.

## The Invoices page

**Invoices** in the sidebar, under Pricing, lists every invoice you have sent, open ones first with the soonest due at the top.

![The Invoices page](/images/docs/invoices.png)
*The three cards show what is outstanding, what is overdue, and what has been received against invoices this month.*

- **Search** by number or client, and filter to **Open**, **Overdue**, **Paid**, **Void** or **All**.
- **Remind** emails the invoice again, worded as a reminder, with anything already received and what is left. The row shows when it was last chased. Use it for a lost email too: a reminder carries everything the original did.
- **Mark paid** records a real payment against the invoice. The amount is pre-filled with what is outstanding; enter less to record a part payment. Unless you untick it, the client gets a thank-you email with their new balance, and their wallet page shows the invoice as **Paid** with the date.

![The Mark paid dialog](/images/docs/invoice-mark-paid.png)
*Mark paid is Record payment with the invoice already chosen.*

- **Void** cancels the ask while something is still owed. Money already received stays on the ledger. Void is not offered on a paid invoice, because that would tell the client a settled bill was cancelled.
- **Delete** is for mistakes, and only while nothing has been received. The number is retired rather than reused, so a number that is already in a client's inbox is never handed to someone else.

The same Remind, Mark paid, Void and Delete actions appear beside each invoice on the client's page.

> **Tip:** Chase from the Invoices page once a week. Sort is already by due date, so the top of the list is the person to nudge first.

> **Note:** Invoices are for asking; the ledger is for what happened. A voided invoice does not touch the client's balance, and a payment recorded without an invoice is still just a payment.
