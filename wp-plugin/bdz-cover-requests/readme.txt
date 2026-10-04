=== Cover Requests ===
Requires PHP: 7.4
Requires at least: 6.0
License: GPL-2.0-or-later

A form where customers send a picture of, or a link to, a book cover they would
like bedazzled. Requests land in the dashboard under **Cover Requests**, where
they can be answered by email, tracked, and deleted.

== Install ==

It is a single file. Copy `bdz-cover-requests.php` straight into
`wp-content/plugins/` (no folder needed) and activate **Cover Requests**.

Then add the shortcode to any page:

    [bdz_cover_request]

Check **Cover Requests -> Settings** for the email address that new requests go
to (defaults to the site admin email) and the thank-you message.

== What a customer fills in ==

Name, email, book title, author (optional), notes (optional), and at least one
of: up to 5 pictures (JPG/PNG/GIF/WebP, 10 MB each) or up to 5 links.

== What happens next ==

* The request appears under **Cover Requests** with a pink count badge for new
  ones, and an email arrives at the notify address. Replying to that email goes
  straight to the customer.
* **Open & reply** shows the pictures, links and notes, and a reply box. Sending
  emails the customer (their reply comes back to the notify address) and logs
  the message in the request's conversation. "Just save it as a note" logs
  without emailing — handy for pasting in what they said back.
* Status is New -> Replied (automatic on first email) -> Done.
* **Move to Trash** hides a request; emptying it from the trash also deletes its
  uploaded pictures.

== Spam ==

No CAPTCHA. A hidden honeypot field, a minimum fill time, and a limit of 5
requests per hour from one address. There is deliberately no nonce on the
public form: page caching would serve stale ones and reject real customers.

== Email ==

Uses wp_mail(). If the site's mail does not arrive reliably, install an SMTP
plugin; a reply that fails to send is kept as a note and the screen says so.
