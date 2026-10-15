# Full backlog (Sprints 0 to 7 and pre-launch)

Every ticket follows docs/TASK_TEMPLATE.md and the definition of done. Sprint 0 tickets AR-001 to AR-015 are in docs/SPRINT_0_TICKETS.md. Screen names match the AmpliRail Platform Screens V3 prototype.

| Ticket | Sprint | Epic | Title | Screen | Key acceptance |
| --- | --- | --- | --- | --- | --- |
| AR-016 | 1 | Onboarding | Merchant create API and state machine | Add merchant | Draft to test_mode transitions; crm_deal_id immutable; audit events |
| AR-017 | 1 | Onboarding | Automatic sandbox provisioning | Add merchant | Test MID, API key pair and webhook secret created on approval |
| AR-018 | 1 | Payments | Transaction and PaymentAttempt with idempotency | Transactions | Repeat key returns stored response; pending attempt written first |
| AR-019 | 1 | Routing | Routing pipeline skeleton with single route | Routing | Route list stored on intent; eligibility filter tested |
| AR-020 | 1 | Ledger | Postings and entries with invariants | Reconciliation | Unbalanced posting rejected; no UPDATE or DELETE grant |
| AR-021 | 1 | Webhooks | Signed outbound webhooks with retry and replay | Developers | HMAC with timestamp; 72-hour retry; replay audited |
| AR-022 | 1 | Identity | Emailed 8-digit code sign-in | Sign in, Verify | Hash stored; 10 minute expiry; single use; lockout after 5 |
| AR-023 | 1 | Portal | Admin shell, navigation, footer with Terms and Privacy | All admin | Role-based menu; Sandbox badge; footer on every page |
| AR-024 | 1 | Portal | Transactions screen with event timeline | Transactions | Milestone #1 visible end to end |
| AR-025 | 1 | Milestone | Milestone #1 acceptance test suite | n/a | All 10 checks in Section 20 automated in CI |
| AR-026 | 2 | Connectors | NMI card connector with Collect.js tokens | Rails and connectors | Passes ContractTests; no PAN reaches our servers |
| AR-027 | 2 | Connectors | NMI ACH and eCheck | Virtual terminal | SEC codes; authorization stored; R-code returns mapped |
| AR-028 | 2 | Settlement | NMI settlement ingestion and archive | Reconciliation | Raw files archived with object lock; records normalized |
| AR-029 | 2 | Reconciliation | Three-way match and exception queue | Reconciliation | Reason codes; resolution audited |
| AR-030 | 2 | Portal | Merchant portal shell and navigation | All merchant | 13-item menu; footer; role checks |
| AR-031 | 2 | Portal | Merchant developers and API keys | Developers and API keys | Scoped keys; secret shown once; IP allowlist; roll and revoke |
| AR-032 | 2 | Portal | Hosted payment page (pay.amplirail.com) | Payment flow map | Card and ACH; branding; 3DS hook; custom subdomain by CNAME |
| AR-033 | 3 | Connectors | Elavon connector per Solution Engineer path | Rails and connectors | Certification plan executed; no Converge XML without approval |
| AR-034 | 3 | Devices | Device registry, pairing and status | Devices | Certified devices only; pairing code 10 minutes |
| AR-035 | 4 | Routing | Eligibility, rules, scoring, failover | Routing | Hard declines never rerouted; policy versions |
| AR-036 | 4 | Approvals | Dual-control approvals service and screen | Approvals | Requester cannot approve; step-up code; required note |
| AR-037 | 4 | Routing | Routing policy editor, dry run and publish via approval | Routing | Publish only through Approvals |
| AR-038 | 4 | Risk | RoboRiskAnalysis risk connector: gate status, pre-authorization context, fail modes | RoboRiskAnalysis | D7 behavior configurable per ISO; gate blocks go-live |
| AR-039 | 4 | Payments | Recurring charges on tokens with stored-credential flags | Subscriptions and memberships | Initial and subsequent indicators set |
| AR-040 | 5 | Reporting | Transaction search with all fields in Section 9.1 | Reports and search | 36 months; background export; CSV and Excel |
| AR-041 | 5 | Reporting | Settled batches, chargebacks and returns, snapshot tabs | Reports and search | All rails in one list |
| AR-042 | 5 | Reporting | Batch integrity checks and alerts | Reports and search | Six checks in Section 9.1 running nightly |
| AR-043 | 5 | Reporting | Merchant transactions, reports and transaction detail | Transactions and reports, Transaction detail | Receipt, subscription, SCS and refund actions |
| AR-044 | 5 | Disputes | Dispute ingestion, evidence and ratio watch | Disputes | Deadlines; ledger hold posted |
| AR-045 | 5 | Residuals | Residual plans, statements and payout approval | Residuals | Reads ledger only; dual control |
| AR-046 | 5 | Merchants | Merchants list, Merchant 360 and merchant profile | Merchants, Merchant 360, Merchant profile | View as merchant is read only and logged |
| AR-047 | 5 | Management | Users, roles, sign-in log, audit trail, security policy | Management | CSV export; filters |
| AR-048 | 5 | Support | Support desk and merchant help | Support desk, Help and support | SLA timers; AI drafts never auto-sent |
| AR-049 | 5 | Platform | System and backups screen and restore drill | System and backups | Shows real backup and restore data |
| AR-050 | 5 | Platform | Connected apps SSO launcher and event health | Connected apps | SSO to CRM, Partner CRM, RoboRiskAnalysis |
| AR-051 | 5 | Onboarding | Add merchant screen (types, CRM import, manual) | Add merchant | Fields in Section 10.1 |
| AR-052 | 6 | Merchant products | Virtual terminal: card, ACH and eCheck, stablecoin tabs | Virtual terminal | Hosted fields; all card actions |
| AR-053 | 6 | Merchant products | Payment requests and manual invoices | Payment requests and invoices | Email, SMS, link; allowed methods; reminders |
| AR-054 | 6 | Merchant products | Plans, subscriptions and memberships | Subscriptions and memberships | Automatic retries; member number charge-to-account |
| AR-055 | 6 | Merchant products | Merchant wallet and payouts | Wallet and payouts | Balances from ledger; ACH and instant payout |
| AR-056 | 6 | Connectors | Visa Direct instant payouts | Wallet and payouts | Dual control above threshold |
| AR-057 | 6 | Merchant products | Traffic sources, tracked links and performance | Traffic and performance | CPA and CPS per Section 12.4; alerts |
| AR-058 | 6 | Merchant products | Apps and integrations marketplace; WooCommerce plugin | Apps and integrations | Install flow; plugin uses hosted fields |
| AR-059 | 6 | Merchant products | Merchant settings | Settings | All options in Section 12.5 |
| AR-060 | 6 | Merchant products | Merchant dashboard with quick actions | Dashboard | Figures across rails |
| AR-061 | 7 | Risk | Merchant risk profile, reserves and velocity limits | Merchant risk and limits | Changes via Approvals; limit.hit events |
| AR-062 | 7 | Risk | Blocking rules (BIN, ICA, bank, country, state, email, IP) | Merchant risk and limits | Evaluated before routing; audited |
| AR-063 | 7 | Risk | Dynamic descriptors | Merchant risk and limits | Locked prefix; flag D16 |
| AR-064 | 7 | Connectors | Stablecoin connector (BVNK or equivalent) | Virtual terminal, Wallet and payouts | No custody (D17); rate lock; confirmations |
| AR-065 | 7 | Merchant products | Rewards and loyalty | Rewards, gift and debit cards | Earn and redeem at terminal |
| AR-066 | 7 | Merchant products | Gift cards and debit card program (flagged) | Rewards, gift and debit cards | Off until partner contract (D16) |
| AR-067 | 7 | Connectors | 3D Secure provider connector | Payment flow map | Automatic when required |
| AR-068 | 7 | Platform | Payment flow map and orchestration catalog screens | Payment flow map, Rails and connectors | Reflect live connector status |
| AR-069 | Pre-launch | Pre-launch | Penetration test and fixes | n/a | No open Critical or High |
| AR-070 | Pre-launch | Pre-launch | PCI scope confirmation with QSA | n/a | Written confirmation |
| AR-071 | Pre-launch | Pre-launch | Pilot merchant go-live | n/a | Pilot plan success criteria met |
| AR-072 | 1 | PCI | Network rules as code and 6-month review record | System and backups, PCI compliance | Req 1: deny by default; review logged |
| AR-073 | 1 | PCI | Hardened images, image and upload malware scanning, DMARC/SPF/DKIM | PCI compliance | Req 2 and 5 evidence produced |
| AR-074 | 1 | PCI | PAN and CVV scanner over database, logs, files and tickets | PCI compliance | Req 3 and 12.10.7: daily run; finding opens incident |
| AR-075 | 1 | PCI | TLS policy and certificate and key inventory | PCI compliance | Req 4: TLS 1.2+ only; expiry alerts |
| AR-076 | 0 | PCI | Secure-coding training log and code-review evidence | PCI compliance | Req 6.2: yearly training recorded per developer |
| AR-077 | 1 | PCI | SBOM in CI and patch due-date tracking | PCI compliance | Req 6.3: critical patches within one month |
| AR-078 | 1 | PCI | Web application firewall on all public endpoints | System and backups | Req 6.4.2: blocking mode, logs kept |
| AR-079 | 2 | PCI | Payment-page script controls and tamper detection | PCI compliance | Req 6.4.3 and 11.6.1: CSP, SRI, script registry, 15-minute checks |
| AR-080 | 5 | PCI | Six-monthly user and system account access reviews | Management | Req 7.2.4 and 7.2.5.1: sign-off recorded |
| AR-081 | 0 | PCI | Admin MFA by authenticator or security key, 15-minute idle timeout | Sign in, Verify, Management | Req 8: emailed code not accepted for admins |
| AR-082 | 1 | PCI | Central immutable logging, daily automated review, control-failure alerts | System and backups | Req 10: 12 months kept, 3 months searchable |
| AR-083 | 2 | PCI | Quarterly internal and ASV scans; pentest and segmentation test | PCI compliance | Req 11.3 and 11.4: passing reports stored |
| AR-084 | 1 | PCI | Intrusion detection and change detection on critical configuration | System and backups | Req 11.5 alerts routed to a person |
| AR-085 | 2 | PCI | PCI compliance screen and evidence calendar; vendor AOC tracker | PCI compliance | Req 12: every recurring task has owner and due date |
| AR-086 | 2 | PCI | Policy set, risk analyses, incident response test, responsibility matrix | PCI compliance | Req 12 documents reviewed by QSA |
| AR-087 | 5 | RRA | Merchant PCI status from RoboRiskAnalysis in Merchant 360; card acceptance block | Merchant 360, PCI compliance | Blocks card acceptance when validation missing, unless approved exception |
| AR-088 | 6b | Fees | Fee types catalog and fee schedule model with versions and inheritance | Fees and billing | New fee type added by configuration only |
| AR-089 | 6b | Fees | Fee rules engine (bases, conditions, tiers, minimums, caps) | Fees and billing | Deterministic; every fee links to its rule |
| AR-090 | 6b | Fees | Fee agreements with e-signature; fees inactive until signed | Fees and billing, Fees and statements | Signed copy and hash stored |
| AR-091 | 6b | Fees | Fee assessment postings and monthly statements | Fees and statements | Pass-through costs shown separately |
| AR-092 | 6b | Fees | Collection by net settlement, ACH debit, card or wallet | Fees and billing | Failed collection retried and alerted |
| AR-093 | 6b | Currency | Currency configuration and per-currency decimals | Currencies and FX | JPY 0 decimals; stablecoins 6 |
| AR-094 | 6b | Currency | Multi-currency MIDs, ledger accounts and reports | Reports and search, Currencies and FX | Never sums across currencies |
| AR-095 | 6b | Currency | FX rate provider connector, quotes and conversions (flagged) | Currencies and FX | Quote expiry enforced; markup from fee rule |
| AR-096 | 6b | Currency | Refund conversion policy per merchant | Currencies and FX | Policy follows signed agreement |
| AR-097 | 6b | Payouts | Payout accounts, verification and sanctions screening | Payouts | Verified before first payout |
| AR-098 | 6b | Payouts | Model B payout runs by currency and rail (flagged) | Payouts | Dual control above threshold |
| AR-099 | 6b | Payouts | Safeguarding account daily reconciliation (flagged) | Payouts, Reconciliation | Bank, ledger and processor three-way match |
| AR-100 | 7 | Payouts | Stablecoin payouts with conversion and fees shown first | Payouts, Wallet and payouts | Provider custody only (D17) |
| AR-101 | 5 | Onboarding | Merchant types: eCommerce online, POS and terminals, mobile, test | Add merchant | Type drives allowed channels |
| AR-102 | 5 | Onboarding | Complete merchant setup fields and processing setup (acquirer MID, TIDs, MCC, descriptor, batch, funding) | Add merchant, Merchant profile | All fields in Section 10.1 stored and audited |
| AR-103 | 5 | Onboarding | Card brands and card types accepted; prepaid blocking per merchant, MID or source | Add merchant, Merchant risk and limits | Blocked card types declined before routing |
| AR-104 | 5 | Connectors | BIN data provider connector (brand, card type, issuer country) | Reports and search | Every transaction tagged |
| AR-105 | 5 | Reporting | Portfolios: global and by acquirer, ISO, agent; TPV, refund %, CB count and % by MID and brand | Portfolios | Per currency; matches ledger totals |
| AR-106 | 5 | Reporting | Card brand, card type and decline reason reports (admin and merchant) | Reports and search, Transactions and reports | Soft and hard declines separated |
| AR-107 | 5 | Disputes | DisputeProvider connectors: Verifi, Ethoca, Chargebacks911, other | Disputes and chargebacks | Alerts ingested and matched to transactions |
| AR-108 | 5 | Disputes | Deflection rules (alert to refund) | Disputes and chargebacks | Refund issued before chargeback; audited |
| AR-109 | 5 | Disputes | Evidence upload and representment to processor | Disputes and chargebacks | Submission and outcome recorded |
| AR-110 | 6b | Fees | Cost (buy) and sell rates with margin on every fee line | Fees and billing | Margin feeds residuals |
| AR-111 | 5 | Residuals | Processing residual file import, PayFac billing margins, gateway technology residuals | Residuals | Three residual types on agent statements |
| AR-112 | 3 | Connectors | Fiserv and TSYS connector interfaces and certification plans | Rails and connectors | Pass ContractTests in staging |
| AR-113 | 5 | Processing controls | Duplicate transaction check per MID | Merchant risk and limits | Window and override honored |
| AR-114 | 5 | Processing controls | Required fields per MID including CVV on first use | Merchant profile | Missing field rejected before routing |
| AR-115 | 5 | Processing controls | AVS and CVV pre-check method per MID | Merchant profile | Void on decline and pre-auth both tested |
| AR-116 | 5 | Merchants | Merchant status: active, restricted, closed, deleted | Merchant profile | Restricted cannot process; closed cannot sign in |
| AR-117 | 5 | Connectors | Refund reroute when a MID or processor is disabled | Rails and connectors | Refund goes to named active MID; audited |
| AR-118 | 3 | Connectors | Typed connector settings schemas (NMI, TSYS, Fiserv, Elavon) | Add merchant, Merchant profile | Validated per connector; no generic slots |
| AR-119 | 6 | Payments | Level III data on supported connectors | Virtual terminal | Line items and tax sent; tested in staging |
| AR-120 | 6b | Payouts | Split funding by percent or fixed amount | Payouts, Fees and billing | Splits post balanced ledger entries |
| AR-121 | 6 | Merchant products | Pay buttons with signed parameters and CAPTCHA option | Payment requests and invoices | Altered amount rejected |
| AR-122 | 7 | Devices | Device SDK and Cloud POS on certified semi-integrated kits | Devices | Pairing, sale, refund on test devices |
| AR-123 | 0 | Governance | Clean-room rule and provider docs folder (docs/providers/) | n/a | Rule 13 in AGENTS.md; NMI docs only in connector folder |
| AR-124 | Pre-launch | Phase 3 | AmpliRail Switch design for direct ISO 8583 (Tier B) | Payment flow map | Design approved; not built until prerequisites met |
| AR-125 | 3 | Connectors | Gattera bridge connector (hosted fields or tokens only) | Rails and connectors | Passes ContractTests; no raw card data to AmpliRail |
| AR-126 | 4 | Card-present | Elavon terminal connector (semi-integrated or cloud) with full EMV data | Point of sale, Devices | Card-present qualification confirmed in staging |
| AR-127 | 4 | Card-present | Talech POS optional plugin | Apps and integrations | Talech sales reported into AmpliRail |
| AR-128 | 6 | Card-present | NMI cloud devices through the bridge | Devices | Card-present data intact |
| AR-129 | 6 | Card-present | Tap to Pay on iPhone add-on (NMI SDK, iOS app) | Point of sale | Flagged; Apple entitlement in place |
| AR-130 | 5 | Card-present | Downgrade review and interchange qualification report | Conversion and interchange insights | Reasons and estimated cost per merchant |
| AR-131 | 4 | Payments | Apple Pay and Google Pay through processors | Virtual terminal, Hosted pay page | Wallet payments in sandbox |
| AR-132 | 4 | Payments | Stored credential flags (customer- and merchant-initiated) with original reference | Transactions | Every card-on-file charge flagged |
| AR-133 | 4 | Payments | Account updater and network tokens through processors | Subscriptions and memberships | Updated cards applied automatically |
| AR-134 | 6 | Merchant products | Point of sale app: register, tabs, tips, split checks, offline queue | Point of sale | Sale, tip adjust and refund on test devices |
| AR-135 | 6 | Verticals | Member accounts, charge to member with signature, house accounts, statements | Members and charges | Signature and terminal ID stored with charge |
| AR-136 | 6 | Verticals | Hotel folios: estimated and incremental authorization, checkout capture, delayed charges | Hotel folios | Lodging rules followed |
| AR-137 | 6 | Verticals | Outlets with own MIDs and MCCs under one merchant | Merchant profile | Reports by outlet |
| AR-138 | 7 | Commerce CRM | Orders, offers, subscriptions and rebills, dunning | Commerce CRM | Rebill retries soft declines only |
| AR-139 | 7 | Commerce CRM | Compliant MID routing rules with laundering block | Commerce CRM | Routing to another business's MID rejected and alerted |
| AR-140 | 5 | Reporting | Conversion and interchange insights screen | Conversion and interchange insights | Approval and downgrade views by connector |
| AR-141 | Pre-launch | Compliance | Sponsor acquirer registration with Visa and Mastercard; registry listings | PCI compliance | Listed after AOC |
| AR-142 | 0 | Governance | AI toolchain policy in AGENTS.md and SECURITY.md; vendor list | n/a | Vendors recorded for 12.8 |
| AR-143 | Expansion | Rails | Open banking pay by bank connector | Rails and connectors | Simulator first |
| AR-144 | Expansion | Payouts | Global multi-currency receiving accounts (Model B) | Currencies and FX | Flagged until license |
| AR-145 | Stage 3 | Switch | AmpliRail card environment: token service, HSM, key management, first direct host | Payment flow map | Level 1 assessment of card environment |
| AR-146 | 6b | Payouts | Payout statement engine: cycle, arrears, sections per MID, provider and currency | Payout statements, Payout statements and reserve | Totals equal ledger; immutable once issued |
| AR-147 | 6b | Payouts | Monthly and one-time fee proration rules on statements | Payout statements | Monthly fee once per month |
| AR-148 | 6b | Payouts | Reserve ledger per holder with rolling tiers and closure release schedule | Payout statements and reserve | Release schedule from agreement |
| AR-149 | 6b | Payouts | PSP and processor payout import with expected versus received reconciliation | Payout statements and reserve, Reconciliation | Variances flagged and ticketed |
| AR-150 | 6b | Payouts | Statement downloads PDF, CSV, Excel and /v1/payout_statements API | Payout statements and reserve | Formats match on-screen totals |
| AR-151 | 5 | Risk | Chargeback ratio by brand program definition with configurable thresholds | Payout statements, Merchant risk and limits | Settled sales basis; not declines |
| AR-152 | 6b | Payouts | Statement adjustments with reason on next statement | Payout statements | No edits to issued statements |
| AR-153 | Expansion | Rewards | Prepaid reward cards through issuing partner (flagged) | Rewards, gift and debit cards | Partner contract and counsel review |
| AR-154 | 7 | Rewards | Digital vouchers with campaigns, limits and redemption audit | Rewards, gift and debit cards | Single-use codes cannot be reused |
| AR-155 | 7 | Rewards | Offer builder: gift cards, package and discount vouchers, occasions, limits, terms | Rewards, vouchers and gift cards | Paid and promotional value tracked separately |
| AR-156 | 7 | Rewards | Hosted offers landing page, widget, QR and wallet passes | Public offers page (customer view) | Purchase to delivery in sandbox |
| AR-157 | 7 | Rewards | Redemption at POS, checkout, member charge and booking | Point of sale, Members and charges | Single-use codes enforced |
| AR-158 | 7 | Rewards | Voucher liability ledger, expiry, breakage and unclaimed property reporting | Rewards network | Liability per merchant and currency |
| AR-159 | 7 | Rewards | Rewards network admin: programs, controls, vertical review in Approvals | Rewards network | Casino and travel offers held for review |
| AR-160 | Expansion | Rewards | Network vouchers across merchants with cross-merchant settlement (flagged) | Rewards network | License or issuing partner in place first |
| AR-161 | 1 | Scale | Partitioned tables (transactions, attempts, ledger, events, audit) and PgBouncer | n/a | Monthly partitions created automatically |
| AR-162 | 1 | Scale | Octane workers and autoscaling on CPU and queue depth | n/a | Scales out under load test |
| AR-163 | 2 | Scale | Connector timeouts, circuit breakers and per-provider concurrency limits | Rails and connectors | Slow provider does not affect others |
| AR-164 | 3 | Scale | Rollup tables and analytics store for reports | Reports and search | Reports never query live payment tables |
| AR-165 | 3 | Scale | Load test suite: 200, 500, 1,000 per second gates with evidence | System and backups | No lost or duplicated transactions; ledger balanced |
| AR-166 | Pre-launch | Scale | Event bus migration plan to managed streaming at threshold | n/a | Switch behind EventPublisher without code changes in modules |
| AR-167 | 1 | Observability | Tracing, SLO dashboards and alerts per connector | System and backups | Alert on SLO breach |
