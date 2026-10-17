# JobHunter

A self-hosted single-user job search service: job postings from platforms → keyword filter → LLM matching against criteria derived from your CV → review and apply in a web interface.

Stack: PHP 8.5, Symfony 7.4, PostgreSQL, FrankenPHP, Docker. The LLM provider is Google Gemini.

## Requirements

- Docker with Compose v2
- `make` and `git`
- A Gemini API key

Everything else runs inside the containers: no PHP or Composer on the host.

## Local installation

```bash
git clone <repository URL> jobhunter
cd jobhunter
make init
```

`make init` creates `.env.local` with your UID and a generated `LLM_KEY_ENCRYPTION_KEY`, builds the image, installs the dependencies, starts the stack, runs the migrations and prepares the test database. Run it as a regular user, not root.

The app is at `http://localhost:8080` (change `APP_PORT` in `.env.local` if the port is taken). Afterwards, start and stop the stack with `make upd` and `make down`. Always use the `make` targets: a bare `docker compose up` doesn't read `.env.local`.

## Server installation

The server runs the prod configuration (`compose.prod.yaml`): no debug or profiler, the app published on `127.0.0.1` only, the database not published at all. A reverse proxy on the same host (for example a Cloudflare Tunnel) makes it reachable from outside.

1. Clone the repository and create `.env.local` with your UID and the encryption key:

   ```bash
   git clone <repository URL> jobhunter
   cd jobhunter
   make encryption-key
   ```

2. Add the server's settings to `.env.local`:

   ```dotenv
   COMPOSE_FILE=compose.yaml:compose.prod.yaml
   APP_SECRET=<output of: openssl rand -hex 32>
   POSTGRES_PASSWORD=<a strong password without @ : / ? # & %>
   SYMFONY_TRUSTED_PROXIES=10.213.0.1
   ```

   - `COMPOSE_FILE` makes every `make` target use the prod configuration.
   - `APP_SECRET` must be set: `.env` leaves it empty.
   - `SYMFONY_TRUSTED_PROXIES` is the first address of `APP_SUBNET` (`10.213.0.0/24` by default): a reverse proxy on the host reaches the app from that address. Without it the app sees every visitor as the proxy, and the login limiter would lock everyone out at once. If `APP_SUBNET` overlaps a network on the server, set another one in `.env.local` and change this address with it.
   - Set `APP_PORT` if `8080` is taken.

3. Build and start:

   ```bash
   make deploy
   ```

   `make deploy` builds the image, installs the dependencies without the dev ones, starts the stack, runs the migrations, compiles the assets and restarts the app.

Don't run `make init` on the server: it installs the dev dependencies and creates a test database.

### Updating

```bash
git pull
make deploy
```

### Backups

Back up the database (the `postgres_data` volume) and `.env.local`. `LLM_KEY_ENCRYPTION_KEY` in `.env.local` is the only way to decrypt the LLM keys stored in the database: if it is lost or changed, the keys have to be entered again.

### External access

The app speaks plain HTTP on `127.0.0.1:APP_PORT`; HTTPS and the public address come from a reverse proxy on the same host. It is not part of this compose setup: use the one you already run. An example with a Cloudflare Tunnel, `cloudflared` installed on the host (not in a container), in its config:

```yaml
ingress:
  - hostname: jobhunter.example.com
    service: http://localhost:8080
  - service: http_status:404
```

Use your `APP_PORT` in place of `8080`. Cloudflare terminates HTTPS and sends `X-Forwarded-For` and `X-Forwarded-Proto`, which the app trusts from `SYMFONY_TRUSTED_PROXIES` only.

Any other reverse proxy (nginx, Caddy) works the same way: on the host, connecting to `127.0.0.1:APP_PORT`, setting `X-Forwarded-For` to the client's address and `X-Forwarded-Proto` to `https`, replacing whatever the client sent. A proxy in another container connects from a different address than the one in `SYMFONY_TRUSTED_PROXIES`, so the app would ignore those headers.

## First run

1. Create your account (there is no sign-up page):

   ```bash
   make in
   bin/console app:user:create
   ```

2. Log in and open **Settings**:
   - **LLM keys**: add your Gemini API key.
   - **LLM models**: choose a model for each task (CV analysis, prompt generation, matching, cover letter).
   - **Intake API key**: generate the key that job postings are sent with, and copy it: it is shown only once.

   Check that a model answers: `bin/console app:llm:check cv_analysis` (inside `make in`).

3. **CV**: upload your CV as a PDF. The analysis runs in the background; the page updates when it is done.
4. **Prompts**: generate the matching prompt for each platform from the analysed CV, and edit it if needed.
5. **Scan instructions** (in **Settings**): write what to search on each platform.
6. Copy the **bootstrap prompt** from the **Intake API key** page into Claude: it fetches the scan instructions and sends the postings to `POST /api/v1/job-postings` with the intake key as a Bearer token. The API documentation is at `/api/v1/docs`.
7. **Job postings**: review the matched postings and generate a cover letter for the ones you apply to.
