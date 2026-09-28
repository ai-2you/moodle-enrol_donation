# enrol_donation

Moodle enrolment plugin that lets a student enrol in a course by paying a donation of any amount
they choose, subject to a per-course minimum and maximum, through Moodle's core payment
subsystem (`core_payment`). Supports any payment gateway plugin compatible with `core_payment`,
including the core `paygw_paypal` and the third-party `paygw_stripe`.

Status: in development, not yet published to the Moodle Plugins directory. See
[`CHANGES.md`](CHANGES.md) for the current state per release.

## Requirements

- Moodle 4.5 LTS (`requires = 2024100100`).
- A configured `core_payment` account with at least one payment gateway enabled.

## License

GPL v3 or later. See [`LICENSE`](LICENSE).

## Development

This plugin is developed inside a full Moodle 4.5 checkout, under `enrol/donation/`, using the
Docker stack already present at the repository root. For local development, bind-mount this
directory into the running container so changes are picked up without rebuilding the image:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

After changing `db/install.xml` on a fresh (never-deployed) install, reinstall the plugin rather
than writing an upgrade step: `db/install.xml` is only frozen once a version has been deployed to
a persistent environment (staging/production). From that point on, schema changes go through
`db/upgrade.php`.
