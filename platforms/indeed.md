# Indeed

Context for collecting and matching Indeed (indeed.com) job postings. Decisions are recorded here before the code for this platform is written, and updated along the way.

## Access

- **Now: browser** (`PlatformAccessType::Browser`). Claude Code opens the owner's logged-in Indeed session, runs the job searches and posts each job to our intake API.
- **API: none for reading jobs.** The Publisher (job search) API is closed to new publishers; the current Indeed APIs (Job Sync, Disposition Sync, etc.) are for employers and ATS partners posting jobs, not for searching them.
- **RSS: none.** The search RSS feeds are no longer served.
- Pages are behind bot protection (Cloudflare challenges); scraping without the owner's session at a manual pace is unreliable.
- Indeed owns Glassdoor and its job posts appear there too (see [glassdoor.md](glassdoor.md)): the two overlap heavily.

## What to collect per posting

- Title, company, description, URL (canonical `https://<country>.indeed.com/viewjob?jk=<id>`; search links carry tracking parameters and sponsored redirects, drop them and keep the `jk` id).
- Location and remote flag, employment type.
- Salary: the employer's own range or Indeed's estimate: keep which one it is, an estimate is not an offer.
- How to apply: "Apply with Indeed" (on the platform) vs the company's site.
- Posting date (relative, convert to absolute).

## Matching specifics

- Indeed is country-specific (`indeed.com`, `de.indeed.com`, …): salary period and currency follow the country (hourly or yearly in the US, yearly or monthly in Europe); matching converts to monthly.
- An Indeed salary estimate shouldn't weigh like an employer's own range.
- Many postings are reposts by staffing agencies: the real employer may be unnamed.

## Open

- Which country sites to search (only `indeed.com`, or the owner's target countries too).
- Store "Apply with Indeed" vs an external application link as a field (same question as LinkedIn's "Easy Apply").
