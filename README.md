# PHP IMAP dashboard that reports incoming email volume by day, today’s total, and today’s per-minute average over a
  selectable UTC date range

Simple PHP page that:

- accepts IMAP credentials (`server`, `port`, `username`, `password`, `SSL/STARTTLS`, mailbox),
- loads mailbox emails for the last 7 days,
- shows:
  - total incoming mails for selected date range,
  - total incoming mails per day,
  - today's only incoming mails,
  - today's average incoming mails per minute.

## Requirements

- PHP 8.0+ recommended
- PHP IMAP extension enabled (`imap`)


## Run locally
`http://localhost:8080/index.php`

- The report uses UTC dates.
- Custom range supports up to 180 days per request.
- For best accuracy, use mailbox `INBOX` (incoming messages).
