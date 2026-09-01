---
paths:
  - 'resources/views/vendor/mail/**'
---

# Mail

## Email uses the site's light theme, published under vendor/mail
Laravel's markdown mail views are published to `resources/views/vendor/mail`, with the palette in `html/themes/rsc.css` and `config/mail.php` set to `'markdown' => ['theme' => 'rsc']`. Every `MailMessage` picks it up, Fortify's password reset and verification included — write notifications normally, do not hand-roll HTML.

The theme is the site's **light** palette only, as literal hex: the CSS is inlined onto the markup before sending, so custom properties do not survive, and email clients have no dark mode to switch to.

Two traps in there, both commented in the files. `.inner-body a` is two classes deep and out-specifies `.button`, which paints the button label the same green as the pill — the theme carries `.inner-body a.button` rules to win it back. And the header wordmark is text, not the site's SVG logo, because Outlook and most webmail either refuse inline SVG or block remote images.

The footer reads `StudioSetting::current()` for company name, number, address, email and website, and drops whatever is blank rather than leaving stray separators. Both `html/message.blade.php` and `text/message.blade.php` do this, so the plain-text part stays in step.

To eyeball a change: render with `app(Illuminate\Mail\Markdown::class)->render(...)` or a notification's `->toMail(...)->render()`, write the HTML somewhere servable, and look at it.
