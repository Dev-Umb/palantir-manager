# AI Harness V2 deployment

## Environment

Keep the Ark and Reverb secrets only in `/var/www/palantir/.env`:

```dotenv
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database
AI_HARNESS_V2=true
AI_REQUEST_TIMEOUT=180

REVERB_APP_ID=xyc-manager
REVERB_APP_KEY=xyc-palantir
REVERB_APP_SECRET=<random-secret>
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_HOST=palantir.umb.ink
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=palantir.umb.ink
```

Rotate `ARK_API_KEY` before production release because an earlier value appeared in chat history.

## Nginx and services

Install `deploy/nginx/palantir-reverb.conf` as `/etc/nginx/snippets/palantir-reverb.conf` and add this line inside the HTTPS `server` block:

```nginx
include /etc/nginx/snippets/palantir-reverb.conf;
```

`deploy.sh` installs and enables:

- `palantir-ai-worker@1.service`
- `palantir-ai-worker@2.service`
- `palantir-reverb.service`

Inspect them with:

```bash
systemctl status palantir-ai-worker@1 palantir-ai-worker@2 palantir-reverb
journalctl -u 'palantir-ai-worker@*' -u palantir-reverb --since today
php artisan ai:harness-health
```

The health command reports queue backlog, failure counts and runs exceeding 180 seconds. Alert when backlog is nonzero for five minutes, failures exceed 5% in 24 hours, or stalled runs are present.
