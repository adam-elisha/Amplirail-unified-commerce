# ADR-0003: Portal UI stack for the launch slice

- Status: **Proposed** (Lead eDataPay PHP Developer to confirm, master document 4.3)
- Date: October 5, 2026

## Decision

Launch portals are server-rendered Laravel Blade views with one hand-written stylesheet (`public/css/amplirail.css`) and
two small scripts. No JavaScript build step.

## Why

- Every action is a form post to a controller that calls the owning module service, so UI code cannot bypass business
  rules (V3.7.1 rule "build APIs first; UI never bypasses services").
- A strict Content Security Policy with per-request nonces (PCI DSS 6.4.3 for payment pages) is simple to keep with no
  inline scripts or styles and no third-party bundles.
- Nothing to build on the server; the Docker image and the Ubuntu installer stay simple.

## Consequences and path forward

Inertia with Vue 3 or React (the V3.7 recommendation) can replace screens one at a time later: controllers already
gather view data from module services, so they can return Inertia responses instead of Blade views without touching
modules. Keep the CSP nonce approach (Vite supports `Vite::useCspNonce()`).
