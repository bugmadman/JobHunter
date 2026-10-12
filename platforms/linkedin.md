# LinkedIn

Context for collecting and matching LinkedIn job postings. Decisions are recorded here before the code for this platform is written, and updated along the way.

## Access

- **Now: browser** (`PlatformAccessType::Browser`). Claude Code opens the owner's logged-in LinkedIn session, runs the job searches and posts each job to our intake API.
- **API: none for reading jobs.** LinkedIn's job APIs (Job Posting API, Talent Solutions) are for approved partners and only publish jobs; there is no self-serve way to search them.
- **RSS: none.**
- Automated collection is the riskiest here: LinkedIn's User Agreement forbids scraping and automated access, it rate-limits and shows auth walls, and an automated owner account can be restricted. Hence the decision already in NOTES: the automatic collection phase starts with another platform, not LinkedIn. Collection through the owner's session stays manual-paced (Claude Code driven by the owner, small volumes).

## What to collect per posting

- Title, company, description, URL (canonical `https://www.linkedin.com/jobs/view/<id>/`; the numeric id is stable, useful for deduplication later; search result links carry tracking parameters, drop them).
- Location and workplace type (on-site / hybrid / remote), employment type (full-time, contract, …), seniority level as LinkedIn states it.
- Salary, when shown (often missing; sometimes LinkedIn's own estimate, which is not the employer's figure: keep them apart).
- Posting date (relative, convert to absolute) and "Easy Apply" vs an external application link.
- Reposted jobs and promoted listings show up repeatedly; duplicates are acceptable for now (NOTES).

## Matching specifics

- Salary is usually yearly when present; matching converts to monthly.
- Remote postings are often restricted to a country or region ("Remote, United States"): the location line matters as much as the "Remote" label.
- A missing salary is not a dislike on its own (the generated prompt already says that for any missing field).

## Open

- Whether "Easy Apply" vs an external link should be stored as a field (it affects how the owner applies).
