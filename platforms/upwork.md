# Upwork

Context for collecting and matching Upwork job postings. Decisions are recorded here before the code for this platform is written, and updated along the way.

## Access

- **Now: browser** (`PlatformAccessType::Browser`). Claude Code opens the owner's logged-in Upwork session, runs the saved searches and posts each job to our intake API. Job details (budget, client history) are visible only when logged in.
- **API: possible later, not now.** Upwork has a GraphQL API (`marketplaceJobPostingsSearch`, scope "Read marketplace Job Postings") that requires an API key application with a verified account; approval is not guaranteed and takes time. Worth applying for once automatic collection is planned: it would be the only platform of the four with an official read API. The adapter would go behind `PlatformClientInterface`.
- **RSS: gone.** Upwork turned off its job search RSS feeds in 2024.
- Scraping without the owner's session (a headless browser on the server) is the hardest option here: Upwork sits behind Cloudflare bot protection and requires login for the useful fields.
- The owner's searches (keywords, filters) live in the Upwork scan instruction in the DB, served to Claude by the API (see NOTES, "Job sources and platforms").

## What to collect per posting

- Title, description, URL (canonical `https://www.upwork.com/jobs/~<id>`; the `~<id>` is the stable job id, useful for deduplication later).
- Payment: hourly (min–max rate per hour) or fixed price (one amount), and the currency (USD).
- Experience level (Entry / Intermediate / Expert), expected duration and weekly hours.
- Required skills (Upwork's skill tags).
- Client: country, payment verified, total spent, hire rate, rating. Strong signals of a serious client.
- Proposals count and the posting date ("Posted 3 hours ago" is relative: convert to an absolute time at collection).

## Matching specifics

- Pay is hourly or per project, while the CV salary expectation is monthly: matching converts (about 160 working hours a month). The generated prompt already says so.
- "Expert" maps to senior/lead, "Intermediate" to middle; "Entry" is usually a dislike for a senior candidate.
- A posting from an unverified client with no spending history is a weakening signal, not a dealbreaker.

## Open

- Whether to apply for the API key now (lead time) or only when the automatic collection phase starts.
