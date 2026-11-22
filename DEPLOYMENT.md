# Production deployment

The `Deploy production` GitHub Actions workflow deploys commits pushed to the
`php8` branch. It connects to the server, saves tracked local edits in a Git
stash, fetches the latest `origin/php8`, resets the server checkout to that
branch, then runs `php artisan nexus:update`. Any server-only commits are kept
on a `pre-deploy/<timestamp>` branch. The workflow stops if non-ignored
untracked files remain, rather than risk overwriting them.

Configure these secrets in the GitHub `production` environment:

- `DEPLOY_SSH_HOST`: production server hostname or IP
- `DEPLOY_SSH_USER`: restricted SSH deployment account
- `DEPLOY_SSH_PRIVATE_KEY`: private key for that account; never commit or share it
- `DEPLOY_SSH_KNOWN_HOSTS`: verified SSH host-key line for the server
- `DEPLOY_WEB_ROOT`: absolute path to the server checkout, such as `/var/www/nexusphp`

The server checkout must already be on branch `php8`, have `origin` pointing to
this repository, and allow the deployment account to run the updater. GitHub is
the source of truth for tracked application code; keep server-specific
configuration in ignored files or environment variables. Stashes and
`pre-deploy` branches are retained on the server as recovery points. The
workflow can also be started manually from the Actions tab with **Run
workflow**.
