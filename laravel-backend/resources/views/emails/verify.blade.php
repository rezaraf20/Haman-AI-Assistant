{{-- Plain-text mailable (Content(text: ...), see VerifyEmail) — {{ }} would
     HTML-escape $body, turning the signed link's "&" between expires= and
     signature= into "&amp;" with nothing to ever unescape it back, since
     this is never parsed as HTML. That literal "&amp;" broke the signature
     query param on every real verification link (confirmed 2026-10-09: a
     real signup's emailed link 403'd on click). $body is fully
     system-composed (name + a URL this app just signed itself), nothing
     user-controlled, so raw output is correct here, not a risk. --}}
{!! $body !!}
