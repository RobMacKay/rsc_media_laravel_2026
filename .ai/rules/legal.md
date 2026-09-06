---
paths:
  - 'resources/views/pages/legal/**'
---

# Legal

## There is no cookie banner because nothing here needs consent
Measured in a browser, not assumed: the public site sets only the session cookie and `XSRF-TOKEN`, both strictly necessary; `remember_web_*` only when someone ticks "keep me signed in"; `flux.appearance` in localStorage only when they use the theme switch. No analytics, no pixels, no third-party requests at all. Under PECR none of that needs consent, and the ICO's guidance is not to ask where it is not needed — so the site has notices at `/privacy` and `/cookies` instead of a banner, linked from the marketing footer, the portal footer and the auth screens.

**The day anything non-essential is added — analytics especially — that stops being true and a consent mechanism has to come with it, in the same commit.** The cookie table on `pages::legal.cookies` is a `#[Computed]` list for exactly that reason: anything new that stores anything belongs in it immediately.

`StudioSetting::welcomeVideoEmbedUrl()` is what keeps the onboarding video consent-free. A bare Vimeo or YouTube iframe sets tracking cookies on load, which would have been the only thing on the site requiring consent; it rewrites YouTube to the no-cookie domain and adds `dnt=1` for Vimeo. Never embed a video with the raw `welcome_video_url`.

The notices read their company details, number, address and email from `StudioSetting`, so they cannot drift from the invoices.
