# Glassdoor

Context for collecting and matching Glassdoor (glassdoor.com) job postings. Decisions are recorded here before the code for this platform is written, and updated along the way.

## Access

- **Now: browser** (`PlatformAccessType::Browser`). Claude Code opens the owner's logged-in Glassdoor session, runs the job searches and posts each job to our intake API.
- **API: none.** Glassdoor's public partner API is long closed to new users, and no job search API is offered.
- **RSS: none.**
- Glassdoor is part of Indeed (same owner, Recruit Holdings; Glassdoor sign-in goes through an Indeed account since late 2025, and employers' job posts on Indeed appear on Glassdoor). So many Glassdoor postings duplicate Indeed ones. Duplicates are acceptable for now (NOTES), but if deduplication comes, Glassdoor and Indeed are the first pair to check.
- Pages are behind bot protection and nag with sign-in modals; scraping without the owner's session is unreliable.

## What to collect per posting

- Title, company, description, URL (canonical job listing URL with the `jl=<id>` / `jobListingId` id; search links carry tracking parameters, drop them).
- Location and remote flag, employment type.
- Salary: employer-provided range or Glassdoor's estimate ("Employer est." vs "Glassdoor est."): keep which one it is, an estimate is not an offer.
- Company rating (and, if cheap to read, the review count): a signal for the owner, not for the match itself.
- Posting date (relative, convert to absolute).

## Matching specifics

- Salary ranges are usually yearly (US) or monthly/yearly depending on the country; matching converts to monthly.
- A Glassdoor estimate shouldn't weigh like an employer's own range.

## Open

- Whether to collect Glassdoor at all while Indeed is collected too (heavy overlap), or keep it only for the company rating.
