# AmpliRail Unified Commerce

Laravel application for the AmpliRail payments platform.

This repository was initialised from the "Rounds 1-6" delivery package. Each feature round
was added as its own commit, in order:

| Round | Feature | Install guide |
| --- | --- | --- |
| 1 | Disputes and chargebacks | [INSTALL_ROUND1.md](docs/install/INSTALL_ROUND1.md) |
| 2 | Reconciliation, reserves, statement chargebacks, emailed approval codes | [INSTALL_ROUND2.md](docs/install/INSTALL_ROUND2.md) |
| 3 | Private label (partner branding) | [INSTALL_PRIVATE_LABEL.md](docs/install/INSTALL_PRIVATE_LABEL.md) |
| 4 | VAMP / TC40 network monitoring | [INSTALL_RISK_MONITORING.md](docs/install/INSTALL_RISK_MONITORING.md) |
| 5 | Velocity limits, Ethoca/Verifi alerts, evidence uploads, chargeback outcomes | [INSTALL_ROUND5.md](docs/install/INSTALL_ROUND5.md) |
| 6 | MID management, BIN routing, platform fees | [INSTALL_ROUND6.md](docs/install/INSTALL_ROUND6.md) |

Start with the [master install guide](docs/install/MASTER_INSTALL.md).

## Notes

- Files live at the same paths as in the Laravel project (`app/`, `modules/`, `database/migrations/`,
  `resources/views/`, `tests/Unit/`).
- `.env`, `vendor/` and `node_modules/` are never committed (see `.gitignore`).
