# Changes

## 0.1.0 (unreleased)

- Initial scaffold: `enrol_donation_intent` table, capabilities, plugin skeleton.
- Donation intent domain logic: locale-aware amount parsing, session-scoped intent reuse, atomic
  stale-intent cleanup.
- Delivery domain logic: idempotent enrolment on payment, self-healing on page visit, scheduled
  redelivery with throttled admin alerts.
- `core_payment` integration (`service_provider`) and student-facing donation amount form.
- Privacy API: `null_provider` for the plugin's own data plus `core_payment` `consumer_provider`
  for `{payments}` rows.
