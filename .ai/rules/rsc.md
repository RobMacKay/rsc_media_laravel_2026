---
paths:
  - 'resources/views/components/rsc/**'
---

# Rsc

## Pending states on buttons must be targeted, and there are two kinds
`<x-rsc.button>` shows a spinner and disables itself while it works. It picks one of two mechanisms:

- **Livewire** — when the button has `wire:click` (target derived automatically, arguments stripped) or is given `target="method"`. A submit button inside a `wire:submit` form **must** pass `target`, because the component cannot see the form it sits in.
- **Plain form** — a `type="submit"` with no target gets a small Alpine handler that disables on the form's submit event. Correct only because the page then navigates away; nothing has to undo it.

**Never use an untargeted `wire:loading`.** Verified in a browser: a `wire:poll` request fires untargeted loading states, so on the seven polling screens (queue, health, proposals, tickets, projects, enquiries) every button would flicker on its own every 15–60 seconds. Targeted indicators stayed dark through the same polls.

The two mechanisms must not be mixed on one button. A Livewire form given the Alpine handler would disable on submit and stay disabled for ever, because only a page navigation clears it.

`busy-label` swaps the text as well ("Sending…"); leave it off and the label stays put, which avoids the button changing width mid-request.
