# Cloud subscriptions and subdomain registration

Run `upgrade.php` as the platform administrator. In **Admin → Packages**, set the total price for each interval you want to offer. Monthly bills every month, quarterly bills every three months, and annual bills every year. A blank price disables that interval. A zero price uses the existing manual request flow. Self-hosted packages use manual approval.

## Enable Stripe

1. Add the `stripe` block from `foodmonster-private/config.example.php` to the existing private configuration. Keep the API key and signing secret private. In production use `mode => 'live'`, the matching live secret key, and HTTPS. Local test installations use `mode => 'test'` with test credentials.
2. In the same Stripe account, configure a snapshot webhook endpoint at `https://YOUR-FOOD-MONSTER-HOST/api/stripe-webhook.php` with API version `2025-06-30.basil`. Subscribe to `checkout.session.expired`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, and `invoice.payment_failed`. Copy that endpoint's signing secret into `webhook_secret`.
3. Enable Stripe's billing portal for invoice access, payment method updates, and cancellation. Configure cancellation at the end of the paid period. Leave plan switches in the Stripe portal disabled so its price and cadence continue to match the Food Monster package.
4. Set `'enabled' => true` and `'checkout_enabled' => true`. Open **Plans** and choose a paid Cloud package. Checkout displays the recurring total and sends the customer back to **Subscription**. Food Monster activates access after it verifies the matching paid Stripe invoice. **Manage billing** opens the customer's Stripe portal after the subscription is linked.

To pause new purchases, set `'checkout_enabled' => false` and keep `'enabled' => true`. Existing customers can manage billing, verified webhooks still apply, and previously opened sessions can finish. Existing configurations accept new checkout when the new setting is omitted.

On **Subscription**, use **Recover checkout** when a previous attempt is stuck. Food Monster checks Stripe for the order, expires a matching open unpaid session, or checks payment on a completed session. If Stripe confirms the order is clear, the customer can choose a plan again. A restricted Stripe key must allow reading invoices, subscriptions, and Checkout sessions, and creating or expiring Checkout sessions as well as opening the billing portal.

Food Monster keeps the most recent verified paid-through date if a renewal fails. A cancelled subscription retains access through its verified paid period, including when its payment event arrives after cancellation. Handle refunds, disputes, and exceptional account adjustments in Stripe and review the associated Food Monster account. Existing manual subscriptions can continue through the admin workflow.

See the [Stripe Checkout API](https://docs.stripe.com/api/checkout/sessions/create?api-version=2025-06-30.basil) and [Stripe subscription webhooks](https://docs.stripe.com/billing/subscriptions/webhooks).

## One-year registration

A Cloud plan includes a one-year subdomain registration per available installation slot. The registration calendar is separate from subscription billing. New registrations run from the reservation date. Existing registrations receive their first tracked year from the date the database upgrade runs.

The renewal button opens 30 days before expiry. Renewing early adds a year to the existing expiry. Renewing after expiry starts a year from the renewal date. Renewals require an eligible active Cloud plan and have no separate charge. If billing ends while the registration is current, use **Link active Cloud plan** to move it to another eligible plan on the same account and keep its expiry. An expired registration stays claimed and requires renewal before new hosting setup. Live restaurant services remain online until the operator manages their hosting separately.

## Transfer to another user

From **My restaurant** or **Admin → Subdomains**, select **Transfer subdomain** on an unused reservation and enter the receiving Food Monster account's verified email. The recipient sees the offer in **My restaurant**, where they can accept or decline. They need a verified email and an active Cloud plan with an available installation. Configure email verification so recipients can verify their accounts. Existing pending offers also check the recipient's verification at acceptance. Offers last up to seven days. The current owner can cancel a pending offer.

Acceptance moves the hostname to the recipient's account and keeps the original registration expiry. It frees the sender's installation slot, creates a new verification challenge, and records the transfer in the audit log. Reservations with a license or provisioning history require an operator-assisted handoff of the Gelato site, ownership, and restaurant data.
