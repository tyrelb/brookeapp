Logging is for sessions that already happened. **Booking** is for the future: it puts a session on your calendar, optionally emails each client a calendar invite, and later you complete it with one click.

## The calendar

Open **Calendar** in the sidebar. It opens on today in Day view; switch with the **Day / Week / Month** buttons and move with the arrows or **Today**.

![Month view for September](/images/docs/calendar-month.png)
*Month view. Green is completed, purple is scheduled, grey is cancelled. The repeat symbol marks sessions from a repeating booking.*

![Week view](/images/docs/calendar-week.png)
*Week view shows times and who is coming.*

![Day view for one day](/images/docs/calendar-day.png)
*Day view lists each session in full.*

- Click a **session** to open it.
- Click a **date number** or a week column's heading to see that whole day.
- Click the **+** on a day to book a session on that date.

## Book a session

Click **Book session** on the dashboard or the calendar.

![The Book session form](/images/docs/book-session.png)
*The booking form is the logging form without the charging, plus repeat and invite options.*

1. Set the **Service**, **Date**, **Start time** and **Duration**, and the **Gym** if you have more than one.
2. **Add clients**. The Attendees table shows the expected charge for each person so you can quote it, but nothing is charged until the session is completed.
3. Leave **Email attendees a calendar invite** ticked to send each client a message with an `.ics` invite they can accept into their phone's calendar. Clients without an email address are skipped.
4. Click **Book session**.

## Repeat a booking

For a standing appointment, tick **Repeat this booking** before you click Book.

![The repeat section of the booking form](/images/docs/book-session-repeat.png)
*Every week on Tuesday, Thursday and Saturday until December 18. The form counts the sessions it will create.*

1. Choose how often: **Every week**, **Every 2 weeks** or **Every 4 weeks**.
2. Tick the weekdays.
3. Set **Until**, the last possible date. A repeat cannot run for more than 12 months or 100 sessions.
4. The button changes to **Book N sessions**. Each date becomes a real session with the same clients, and each client gets one invite whose calendar file holds every date.

## A scheduled session

Open any scheduled session from the calendar or the Sessions list.

![A scheduled session that belongs to a repeat](/images/docs/session-scheduled.png)
*A scheduled session. The purple badge shows it belongs to a repeating booking with 12 sessions still to come.*

- **Complete & charge** when the session has happened. Tick who attended first, exactly as on the logging form.
- **Reschedule** opens a dialog to change the time, length, service or gym.
- **Send invites** emails the calendar invite; once sent, the button reads **Resend invites**, which is useful after adding a client.
- **Cancel session** keeps the record but charges no one. Clients who received an invite get a cancellation that removes it from their calendar.
- **Delete** removes the session entirely.
- **Apply attendees to following sessions** copies the current attendee list to the rest of the repeat.

## Reschedule, and "this" versus "this and following"

![The Reschedule dialog with the Apply to choice](/images/docs/reschedule-dialog.png)
*For a session in a repeat, choose whether the change applies to just this date or to every later session too.*

For a session that belongs to a repeat, Reschedule and Cancel both ask **Apply to**:

- **Only this session** changes this date and nothing else.
- **This and the following sessions** shifts every later session in the repeat by the same number of days and gives them the new time, length, service and gym. Earlier sessions, completed sessions and cancelled sessions are never touched.

To cancel the rest of a repeat, use **Cancel this & following**. Invited clients get a single cancellation email covering every affected date.

> **Tip:** Clients cannot change bookings themselves. Invites and the wallet page tell them to contact you, using the booking instructions from Settings → Business.
