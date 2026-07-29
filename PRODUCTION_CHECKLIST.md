# Flitops Production Readiness Checklist
### For hosting Bouclay reliably

This checklist covers what a deployment platform needs beyond basic provisioning, so that Flitops does not become the weak link under a billing system that depends on it running correctly every hour of every day.

---

## 1. Provisioning (foundation — likely already solid)
- [ ] Server creation (VPS spin-up)
- [ ] Website/site setup on a server
- [ ] Database creation
- [ ] SSL certificate provisioning

---

## 2. Deployment Mechanics
- [ ] Zero-downtime deploys via atomic symlink swap
- [ ] One-click **rollback** to the previous release if a deploy breaks something
- [ ] Deploy history/log (what was deployed, when, by whom)
- [ ] Ability to run pre/post-deploy hooks (migrations, cache clear, queue restart)

---

## 3. Process & Queue Management
- [ ] Supervisor-style process manager for queue workers
- [ ] Workers auto-restart on crash
- [ ] Dashboard visibility into worker status (running/stopped/crashed)
- [ ] Access to worker logs without SSH

*Why it matters: Bouclay's webhook processing and dunning retries run through Laravel queues continuously. A silently dead worker means silently failed billing.*

---

## 4. Environment & Secrets Management
- [ ] Per-site environment variable storage
- [ ] Secrets encrypted at rest
- [ ] Easy update of env vars without manual SSH + file editing
- [ ] Audit trail of who changed what env var and when

*Why it matters: Nomba/Paystack/Flutterwave API keys and DB credentials live here. This is the most sensitive layer of the whole stack.*

---

## 5. Scheduled Tasks (Cron)
- [ ] Laravel scheduler cron entry configured automatically per site
- [ ] **Monitoring that the scheduler is actually firing** (not just that the server is up)
- [ ] Alert if scheduled tasks stop running

*Why it matters: If the scheduler silently stops, dunning retries and trial expirations silently stop with it — and nobody notices until customers complain.*

---

## 6. Monitoring & Alerting
- [ ] Basic uptime checks (is the server/site reachable)
- [ ] Application-level health checks (is the queue backed up, is the DB reachable)
- [ ] Alerts to phone/Slack/email on failure — not just a dashboard you have to check
- [ ] Historical uptime/incident log

*Why it matters: A day of silent failure on Bouclay means a day of failed payments nobody caught.*

---

## 7. Backups
- [ ] Automated, scheduled database backups
- [ ] One-click restore
- [ ] Backup verification (confirm backups are actually restorable, not just "completed")
- [ ] Off-server backup storage (not just on the same VPS)

*Why it matters: Bouclay's database is customers' billing history and subscription state. This is not recoverable data if lost.*

---

## 8. SSL Renewal Reliability
- [ ] Initial provisioning (already have this)
- [ ] **Confirmed auto-renewal** actually fires months later, not just configured
- [ ] Alert before expiry if renewal fails

*Why it matters: A silently expired cert breaks every incoming webhook from Nomba/Stripe with no warning — this is a subtle, high-impact failure mode.*

---

## 9. Log Access
- [ ] Application logs visible from dashboard
- [ ] Server logs visible from dashboard
- [ ] Searchable/filterable logs (not just a raw tail)
- [ ] No SSH required for routine debugging

---

## 10. Multi-Environment Support
- [ ] Staging environment that mirrors production
- [ ] Ability to test billing logic changes safely before touching real subscriptions/money
- [ ] Easy promotion path from staging to production

---

## How to use this

Go through each item against Flitops as it exists today. Anything unchecked is a gap — prioritize roughly in the order above, since deployment mechanics and process/queue management are the most likely to cause an actual billing incident if missing, while multi-environment support is the most deferrable.
