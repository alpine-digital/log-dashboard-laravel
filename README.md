# Log Dashboard Laravel

In-project log viewer for **local development**, built as a Laravel package.
Install it into a Laravel project and open `/log-dashboard` to browse that
project's own `storage/logs` — no SSH, no separate app. It runs inside the host
project and reads the log files straight off the local disk.

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12 or 13
- A `local`/`development` environment (see [Security](#security))
- The project runs in DDEV (the examples use `ddev`; plain Composer works too)

## Install (private GitLab repo)

This is a **private** repo, so Composer needs access to GitLab — and because the
app runs in DDEV, the **container** needs that access, not just your host.

**1. Add the repository** as a **git** repository:

```bash
ddev composer config repositories.log-dashboard git git@gitlab.com:alpinedigital/log-dashboard.git
```

> Note the type is **`git`**, not `vcs`. With `vcs` on a `gitlab.com` URL Composer
> uses its GitLab driver, which calls the GitLab API (`api/v4/...`) first and
> returns `404` on a private repo — even though your SSH key works. `type: git`
> uses the plain git driver: it clones over SSH, no API and no token involved.
> (`no-api: true` does *not* help here — that option only applies to GitHub.)

<details>
<summary>Or add it by hand in <code>composer.json</code></summary>

Same result, next to the other top-level keys:

```json
"repositories": [
    {
        "type": "git",
        "url": "git@gitlab.com:alpinedigital/log-dashboard.git"
    }
]
```

Use this if you prefer editing the file — but the command above is easier and
avoids the JSON-quoting issues `ddev composer config --json …` hits on
Windows/PowerShell.

</details>

**2. Give the container your SSH key and install:**

```bash
ddev auth ssh
ddev composer require --dev alpinedigital/log-dashboard:@dev
```

`ddev auth ssh` forwards your host SSH agent into the container (once per `ddev`
session). You need an SSH key registered on your GitLab account — verify with
`ssh -T git@gitlab.com` on the host. The service provider is auto-discovered.
Update later with `ddev composer update alpinedigital/log-dashboard`.

<details>
<summary>Alternative: HTTPS + deploy token (no SSH)</summary>

1. In GitLab: **Settings → Repository → Deploy tokens**, create one with
   `read_repository` scope.
2. Hand the token to Composer **inside the container**, then install:

   ```bash
   ddev composer config --global gitlab-token.gitlab.com <token-username> <token>
   ddev composer config repositories.log-dashboard vcs https://gitlab.com/alpinedigital/log-dashboard.git
   ddev composer require --dev alpinedigital/log-dashboard:@dev
   ```

</details>

## Usage

With the project served locally, open:

```
https://<project>.ddev.site/log-dashboard
```

You land on the log-file list; click a file to view its entries (level filter,
search, live refresh). Or open it anytime with:

```bash
ddev launch log-dashboard
```

> **Seeing the host app's error page instead of the dashboard?** If the API
> works (`ddev exec curl http://localhost/log-dashboard/api/logs` returns JSON)
> but the browser shows your app's own error/offline screen, a leftover service
> worker on that origin is intercepting the route. In DevTools → Application →
> **Clear site data**, then hard-refresh.

### Auto-open on `ddev start` (optional)

Copy the bundled stub into your project's `.ddev/` to open the dashboard in a
browser tab automatically after every `ddev start`:

```bash
cp vendor/alpinedigital/log-dashboard/stubs/config.logdashboard.yaml .ddev/
ddev restart
```

DDEV merges every `.ddev/config.*.yaml`, so this only adds a `post-start` hook.

## Configuration

Publish the config to override defaults:

```bash
ddev artisan vendor:publish --tag=log-dashboard-config
```

`config/log-dashboard.php`:

| Key            | Default                                       | Env                          |
| -------------- | --------------------------------------------- | ---------------------------- |
| `enabled`      | `true` in local/development; opt-in elsewhere | `LOG_DASHBOARD_ENABLED`      |
| `route_prefix` | `log-dashboard`                               | `LOG_DASHBOARD_ROUTE_PREFIX` |
| `path`         | `storage_path('logs')`                        | `LOG_DASHBOARD_PATH`         |

Point `path` elsewhere via `LOG_DASHBOARD_PATH`.

## Security

The dashboard exposes log contents over HTTP, so:

- Install it as `--dev` only.
- Routes mount automatically only in `local`/`development`. Anywhere else it is
  **opt-in** (`LOG_DASHBOARD_ENABLED=true`), and the service provider
  **hard-blocks `production`/`prod`** regardless of config.
- The route has no auth middleware — anyone who can reach the URL can read the
  logs. That's fine on local DDEV; don't expose it on a shared environment.

**Never enable this in production.**

## Notes

- The compiled dashboard UI ships pre-built under `resources/dist`. Its Angular
  source lives in the main Log-dashboard project; this repo carries the built
  assets so consumers never run a frontend build.
- The log parser auto-detects both Laravel's bracketed log format
  (`[%datetime%] %channel%.%level%: %message%`) and the plain Monolog line
  format, so it reads standard `storage/logs/*.log` files out of the box.
- **Craft CMS** is not supported yet — Craft runs on Yii, so the Laravel service
  provider does not boot there. The log-reading logic is framework-agnostic and
  reusable when a Craft module is added.
