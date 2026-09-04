# Consider a FULFILLMENTS_UPDATE webhook instead of polling for voids

Status: needs-triage

Repo: `polybag`

## Current behavior

`packages:sync-shopify-voids` runs every 15 minutes, reads the fulfillment behind each
live Shopify package, and un-ships it via `clearShipping()` when Shopify reports
`LABEL_VOIDED` or `CANCELLED`. It works and is tested.

Polling was chosen deliberately: a webhook needs a publicly reachable callback URL, and
an on-prem install may not have one.

## What a webhook would buy

Latency, and nothing else. `FULFILLMENTS_UPDATE` exists as a webhook topic (there is no
label-specific topic), so a voided label would reach PolyBag in seconds instead of
within 15 minutes.

## The decision

Whether that latency matters. A voided label is not an emergency — the package sits in
the shipped queue with a dead tracking number until the next poll, and a packer who
voided it in Shopify knows what they did. Fifteen minutes is very likely fine.

Against it:

- Needs a public callback URL, so it can only ever be a hosted-tenant feature. On-prem
  keeps polling regardless, which means **both** paths exist and both need maintaining.
- Needs HMAC verification, webhook registration and re-registration, and replay
  handling.
- Shopify webhooks are at-least-once and can be dropped, so the poll stays as a backstop
  anyway. The webhook is an optimisation on top, never a replacement.

Recommendation: leave it until somebody complains about the 15-minute window. The cost
work in `05` is worth more, since it is the only route to real postage numbers.

## Comments
