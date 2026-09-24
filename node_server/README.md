# TTN Node Telemetry Agent

Feeds this node server's live AllStarLink status (connected nodes, keyed
state) to the TTN portal's Last Heard page and per-node status API.

## What this is (and isn't)

This installs two files -- `ttn-logger.php` (a cron job that polls your
node(s) over the Asterisk Manager Interface and POSTs a snapshot to the TTN
portal every 5 minutes) and `ttn-status.php` (a live-query API endpoint your
node's own status page can call for near-real-time data, backed by the
cron output as a fast path).

It does **not** set up AllStarLink, Asterisk, or AMI access -- that's your
node build already (see `TTN_Node_Build_Standard.md`). It also does not
include node credential-provisioning tools or the node-config/status-page
system -- those are separate, unrelated pieces of the TTN node-server
toolkit, intentionally not part of this package.

## Prerequisites

- A working TTN AllStarLink node.
- Allmon3 (or an equivalent `allmon3.ini`/`allmon.ini`) already installed
  and configured, with AMI host/port/user/pass for each node you want
  reported. This package only *reads* that file -- if you don't have one
  yet, set up Allmon3 first: https://github.com/AllStarLink/Allmon3
- PHP with the standard extensions your web server already needs for
  Allmon3/AllScan.

## Install

```bash
git clone https://github.com/bobwwj555/TTN.git
cd TTN/node_server
sudo ./install.sh
```

The installer will:
1. Confirm it can find your AMI credentials file -- won't proceed without one.
2. Copy `ttn-logger.php` and `ttn-status.php` into your web root.
3. Create `/etc/ttn-logger.conf` from the template (if one doesn't already
   exist) and generate a random shared secret.
4. Install a `www-data` cron job that runs the logger every 5 minutes.

After install, **give the generated secret to the TTN portal admin** -- it
has to match the portal's `telemetry_secret` site setting for your node's
reports to be accepted. Nothing will show up on Last Heard until that's set
on both ends. (Currently one shared secret covers all node servers, not a
separate one per node.)

## Verify

```bash
sudo -u www-data php /var/www/html/ttn-logger.php   # run once by hand
tail -f /var/log/ttn-telemetry.log                   # watch it log
```

Then check the portal's Last Heard page / your node's status page for this
node showing up.

## Config reference (`/etc/ttn-logger.conf`)

| Key | Meaning |
|---|---|
| `portal_url` | The portal's telemetry-receive endpoint. Confirm this is the right one for your deployment before relying on it. |
| `portal_secret` | Shared secret; must match the portal's `telemetry_secret` setting. |
| `log_local` | Local log file path. |

## Troubleshooting

- **"No ini file found"** — Allmon3 (or equivalent) isn't set up yet, or
  its ini file isn't in one of the standard paths this script checks.
- **Nothing shows up on the portal** — check `portal_secret` matches on
  both ends, and that this node server can reach `portal_url` outbound.
- **Cron isn't running** — `crontab -u www-data -l` to confirm the entry
  landed; check `/var/log/ttn-telemetry.log` for errors.
- **Re-running the installer** — safe. It won't overwrite an existing
  `/etc/ttn-logger.conf`, and re-installs the cron entry cleanly (removes
  any prior one first) rather than duplicating it.
