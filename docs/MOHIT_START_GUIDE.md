# 0. What you have and the goal

You have four things:

| Item | What it is |
| --- | --- |
| AmpliRail 2.0 Master Development Document V3.6 (Word) | The controlling document. It wins any conflict. |
| AmpliRail Repo Starter Pack V3.6 (zip) | Files for the repository root: AGENTS.md, CLAUDE.md, SECURITY.md, docs, prompts, backlog, GitHub templates, CI workflow, Claude Code settings |
| AmpliRail Platform Screens canvas | 54 screens; each screen name shows its ticket numbers |
| This guide | The exact steps, in order |

**Goal for the first 4 weeks:** Milestone #1 passing in sandbox. A merchant is created, a sandbox is provisioned automatically, a $100 payment runs through the simulator, the ledger balances, a signed webhook is sent, and the timeline shows in the admin console.

> **How to move fast safely:** you decide, AI builds, tests prove, you merge. Run two or three AI sessions in parallel on different tickets (Step 14). Never let speed skip a review gate.

# Part A: GitHub setup (Day 1, about 2 hours)

## Step 1. Create the company-owned organization

1. Sign in to GitHub with your own account.
2. Go to github.com, click your profile picture, then Your organizations, then New organization.
3. Choose a paid plan (Team plan) so you get protected branches, required reviewers and code owners on private repositories.
4. Organization name: amplirail (or the name Adam approves). Contact email: a company address.
5. When asked who owns it, choose that it belongs to a business and enter the legal entity Adam confirms (for example AmpliPay Group Inc.).
6. Invite Adam (adam.e@amplipay.ai) and make him an Owner. There must always be at least two owners: Adam and you.

> **IP GATE:** Do not put any code in a repository owned by a personal account. Every developer and contractor signs the IP assignment before getting access (master document Section 24).

## Step 2. Lock down organization security

In Organization settings:

1. Authentication security: turn on Require two-factor authentication for everyone.
2. Member privileges: set Base permissions to No permission. Turn off repository creation for members and turn off forking of private repositories.
3. Actions, General: allow only actions created by GitHub and verified creators, plus the ones listed in ci.yml. Set Workflow permissions to Read repository contents.
4. Code security: enable Dependabot alerts and Dependabot security updates for all new repositories. If your plan includes secret scanning and push protection, turn both on; if not, the gitleaks job in CI covers secret detection.

## Step 3. Create the teams

| Team | Members | Access |
| --- | --- | --- |
| rail-owners | Mohit, Adam | Admin on AmpliRail repositories |
| security-reviewers | Adam plus a named second reviewer | Write; required on sensitive paths through CODEOWNERS |
| rail-developers | Developers and contractors (after IP assignment) | Write |
| read-only | Anyone who only needs to look | Read |

## Step 4. Create the three private repositories

1. amplirail-unified-commerce (the rail, this project)
2. roboriskanalysis (merchant risk platform, runs separately; see master document Section 10.2)
3. mvpay (media and merchant growth)

All private. Add a README. Give each team its access from Step 3.

## Step 5. Protect the main branch with a ruleset

In amplirail-unified-commerce: Settings, Rules, Rulesets, New branch ruleset. Target: the default branch (main). Turn on:

1. Restrict deletions.
2. Block force pushes.
3. Require a pull request before merging: 1 approval, dismiss stale approvals when new commits are pushed, require review from Code Owners, require conversation resolution.
4. Require status checks to pass: add test and secrets (the jobs in ci.yml) after the first CI run.
5. Require linear history.
6. Bypass list: empty. Not even owners bypass.

## Step 6. Create environments

Settings, Environments: create sandbox, staging and production.

| Environment | Required reviewers | Secrets |
| --- | --- | --- |
| sandbox | None | Sandbox AWS role only |
| staging | Mohit | Staging AWS role only |
| production | Mohit and Adam | Production AWS role only; deploy only from main |

Use short-lived AWS credentials through GitHub OIDC roles rather than long-lived AWS keys stored as secrets.

## Step 7. Commit the starter pack

```
git clone git@github.com:amplirail/amplirail-unified-commerce.git
cd amplirail-unified-commerce
unzip ~/Downloads/AmpliRail_Repo_Starter_Pack_V3_6.zip -d .
git checkout -b ar-003-starter-pack
git add AGENTS.md CLAUDE.md SECURITY.md docs .github .claude
git commit -m "AR-003: add AmpliRail starter pack V3.6"
git push -u origin ar-003-starter-pack
```

Open the pull request, have Adam approve, and merge. From now on nobody pushes to main directly.

## Step 8. Load the backlog as GitHub Issues

1. Create labels: ticket, sprint-0 through sprint-7, pre-launch, security-relevant, plan-first.
2. Create one Issue per row of docs/SPRINT_0_TICKETS.md and docs/BACKLOG.md using the AmpliRail ticket template. Title format: "AR-004: Laravel 12 skeleton and module layout".
3. Create a GitHub Project board with columns Backlog, Ready, In progress, In review, Done.

Tip: ask Claude Code to draft the Issues for you from BACKLOG.md with the GitHub CLI (gh issue create), and review them before creating.

# Part B: Your machine (Day 1, about 1 hour)

## Step 9. Install the tools

| Tool | Version |
| --- | --- |
| Git and GitHub CLI (gh) | Latest |
| Docker Desktop | Latest |
| PHP and Composer | PHP 8.3+, Composer 2 |
| Node.js | Current LTS |
| gitleaks | Latest (also runs in CI) |

## Step 10. Install and sign in to Claude Code

macOS, Linux or WSL:

```
curl -fsSL https://claude.ai/install.sh | bash
claude --version
```

Windows PowerShell:

```
irm https://claude.ai/install.ps1 | iex
```

Then run claude inside the repository folder and sign in with the company Claude account Adam provides (a business plan, not a personal one). Official setup guide: code.claude.com/docs/en/setup.

## Step 11. Check the Claude Code safety settings

The starter pack includes .claude/settings.json. It stops the agent from reading .env files and secret folders, from force pushing, from pushing to main, and from running terraform apply or AWS commands. Keep this file in the repository so every session uses it. Change it only by pull request.

# Part C: The AI build loop (every ticket)

## Step 12. Run one ticket end to end

1. Pick the top Ready Issue. Move it to In progress.
2. Create a branch: git checkout -b ar-004-laravel-skeleton
3. Start Claude Code in the repository: claude
4. Paste prompt P0 (session bootstrap) from docs/prompts/PROMPT_LIBRARY.md. Check its summary of the hard rules.
5. Paste prompt P1 with the full Issue text. For plan-first tickets, read the plan and the file list, correct it, then say "approved, go ahead".
6. Let it write tests and code, and run: composer test, composer stan, composer arch, composer contract, composer audit, gitleaks.
7. Ask it to push the branch and open the pull request with the template filled in (gh pr create).
8. Open a new, separate Claude Code session and run prompt P6 (independent review) on the pull request. For security-relevant tickets also run P5 (security review).
9. Fix what the reviews find (back in the builder session), wait for CI to go green, then review the diff yourself and merge.
10. Move the Issue to Done and note it in your daily report.

> **Human gates you never skip:** reading the plan before code on plan-first tickets, your own review of the diff, a second human reviewer on Identity, Tenancy, Ledger, Payments, connectors, infra and migrations (CODEOWNERS enforces this), and approval of every production deployment.

## Step 13. Useful Claude Code habits

1. One ticket per session. Start fresh for the next ticket so the context stays clean.
2. Ask for the plan first on anything touching money, identity or the database.
3. If it drifts outside the allowed files, stop it and restate the ticket scope.
4. Never paste secrets, production data or real card numbers into the chat.
5. When a test fails, ask it to find the root cause, not to change the test.

## Step 14. Go faster with parallel sessions

Tickets in different modules can run at the same time using git worktrees, one Claude Code session per folder:

```
git worktree add ../ar-007 -b ar-007-tenancy
git worktree add ../ar-011 -b ar-011-connector-contracts
cd ../ar-007 && claude     # terminal 1
cd ../ar-011 && claude     # terminal 2
```

Good pairs: Tenancy with Connector contracts; Simulator with OpenAPI skeleton; UI screens with backend endpoints they depend on only after the endpoint is merged. Do not run two sessions on the same module or migration.

# Part D: The first four weeks

| When | Tickets | Done when |
| --- | --- | --- |
| Day 1 | AR-001 organization, AR-003 starter pack, Steps 1 to 11 | Main protected, starter pack merged, Claude Code working |
| Day 1 (parallel) | AR-002 CRM card-data audit | Report sent to Adam |
| Days 2 to 3 | AR-004 skeleton, AR-005 CI | CI green on a pull request |
| Days 3 to 5 | AR-007 tenancy, AR-008 identity with emailed code, AR-009 audit and outbox | Cross-tenant tests pass |
| Week 2 | AR-010 schemas and OpenAPI, AR-011 contracts, AR-012 simulator, AR-006 Terraform plan, AR-013 backup plan, AR-014 PCI data flow, AR-015 Elavon SE package | Sprint 0 exit criteria met |
| Weeks 3 to 4 | Sprint 1: AR-016 to AR-025 | Milestone #1 test suite passes in CI and in sandbox |

Terraform: the agent may write and run terraform plan only. You or Jass run terraform apply after review.

# Part E: Reporting to Adam

Send the daily report (docs/DAILY_REPORT_TEMPLATE.md) at the end of each working day. Keep it to the facts: merged, in review, in progress, blocked, CI status, decisions needed, tomorrow.

Escalate to Adam the same day if any of these happen:

1. A secret was exposed or committed (rotate it first, then report).
2. Real card, bank or customer data shows up anywhere in development.
3. A ticket conflicts with the master document.
4. A processor, bank or partner asks for something that changes the PCI boundary.
5. A decision in Section 25 of the master document is blocking work.

# Part F: Files in the starter pack

| File | Purpose |
| --- | --- |
| AGENTS.md, CLAUDE.md | Rules every AI session follows |
| SECURITY.md | Security requirements |
| docs/MASTER_DEVELOPMENT_DOCUMENT_V3.md | The controlling document (version 3.6) |
| docs/prompts/PROMPT_LIBRARY.md | Prompts P0 to P15 |
| docs/SPRINT_0_TICKETS.md, docs/BACKLOG.md | AR-001 to AR-167 |
| docs/TASK_TEMPLATE.md, .github/ISSUE_TEMPLATE/ticket.md | Ticket format |
| .github/pull_request_template.md | Pull request checklist |
| .github/CODEOWNERS | Second reviewer on sensitive folders |
| .github/workflows/ci.yml | Starter CI: tests, PHPStan, architecture and contract tests, audit, gitleaks |
| .claude/settings.json | Claude Code safety settings |
| docs/DAILY_REPORT_TEMPLATE.md | End-of-day report |

The CI file is a starting point: AR-005 finishes it (Semgrep, OpenAPI diff, npm audit, pinned action SHAs).


## Two-week launch

Follow docs/LAUNCH_SLICE_2_WEEKS.md day by day. Use the daily prompt and send the daily status every evening.

Tested starter code for the launch slice is in code-starter/ (run `php tests/run.php`, expect 20/20 passed). Move each file into the module listed in code-starter/README.md.
