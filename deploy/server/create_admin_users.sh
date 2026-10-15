#!/bin/bash
# Invite the AmpliRail admin-console team (amplipay.online mailboxes) on one site.
# Run in the CWP Terminal as root:   bash create_admin_users.sh dev    (getpayments101.amplipay.online)
#                                    bash create_admin_users.sh p101   (payments101.amplirail.com)
# Each person receives an invitation email (valid 72 hours) to set a password, then enrolls an authenticator app.
# Safe to re-run: re-inviting someone sends a fresh link; accepting it resets that person's password.
# Invitation links are NOT printed here: they go only to each mailbox.
set -u
case "${1:-}" in
  dev)  U=amplidev; APP=/home/amplidev/amplirail/amplirail-unified-commerce ;;
  p101) U=amplirai; APP=/home/amplirai/amplirail/amplirail-unified-commerce ;;
  *) echo "Usage: bash create_admin_users.sh dev|p101"; exit 1 ;;
esac
PHP=/opt/alt/php83/usr/bin/php

# email | display name | role   (roles: owner operations finance underwriter support_agent developer read_only)
while IFS='|' read -r email name role; do
  [ -z "$email" ] && continue
  out=$(sudo -u "$U" -H bash -c "cd '$APP' && $PHP artisan amplirail:admin:create '$email' '$name' --role=$role --no-ansi" 2>&1 | grep -E '^Invited|rror|Unknown|not found')
  printf '%-36s %-14s %s\n' "$email" "$role" "${out:-check manually}"
done <<'LIST'
owner@amplipay.online|Owner|owner
management@amplipay.online|Management|owner
operations@amplipay.online|Operations|operations
support_level2@amplipay.online|Support Level 2|operations
finance@amplipay.online|Finance|finance
underwriter@amplipay.online|Underwriting|underwriter
support@amplipay.online|Support|support_agent
support_agent@amplipay.online|Support Agent|support_agent
gateway@amplipay.online|Gateway Technical|developer
security@amplipay.online|Security|developer
legal@amplipay.online|Legal|read_only
privacy@amplipay.online|Privacy|read_only
sales@amplipay.online|Sales|read_only
info@amplipay.online|Info|read_only
media@amplipay.online|Media|read_only
read_only@amplipay.online|Read Only|read_only
LIST

# Deliberately NOT given a login: system mailboxes with no person behind them.
#   abuse@, dmarc@, postmaster@, no-reply@, mail@ amplipay.online
# Test-Merchant@ and Test-merchant1..3@ are merchant-portal users: create them as Test merchants in
# Admin > Merchants > Add merchant (primary user email), never as admins.
