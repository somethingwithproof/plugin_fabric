# Contributing

This project is in the proposal stage. Most useful contributions right now are evidence for the research issues in milestone 1, such as recorded tool output and counter behavior from real fabrics, and review of the requirements and open decisions.

## Before you start

- Read [docs/PLAN.md](docs/PLAN.md) and the [decision records](docs/decisions/).
- Find or open an issue first. Pull requests should reference the issue they resolve.
- A change that contradicts an accepted decision record needs a new decision issue, not a pull request.
- Scrub fabric data before sharing it: remove site names, host names, and anything that identifies a customer. GUIDs identify hardware, so replace them in public fixtures unless you own the hardware and accept the exposure.

## Issues

Use the issue forms:

| Form | Use it for |
|---|---|
| Requirement | A testable behavior the plugin must have |
| Research | A question that must be answered with evidence before design or code depends on it |
| Decision | A choice between options that needs a recorded outcome |
| Bug report | An error in the documents now, or in code later |

### Writing requirements

Each requirement issue lists numbered requirements with IDs in the form `REQ-AREA-NNN`, for example `REQ-COMPAT-001`. A requirement:

- states one observable behavior with **MUST**, **MUST NOT**, or **SHOULD**;
- can be verified by a test, a measurement, or an inspection named in its acceptance criteria;
- cites the plan section or upstream source it depends on.

IDs are never reused. When a requirement is dropped, its issue is closed as not planned and the ID stays retired. [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md) is the index.

### Research issues

State the question, the method, and the evidence that closes the issue. Record results in a comment with tool and firmware versions, hardware models, commands, and output, then close the issue. Results that change the design update [docs/PLAN.md](docs/PLAN.md) in the same pull request. Never run commands that reset counters or change port state on a production fabric to gather evidence; use a lab fabric.

## Labels

| Prefix | Meaning |
|---|---|
| `type:` | What kind of issue it is: requirement, research, decision, epic |
| `area:` | The part of the design it touches |
| `phase:` | The release phase it belongs to: 0 research, 1 observe, 2 ufm, 3 remote, or future |
| `priority:` | `blocker` stops its milestone; `high` is needed for the milestone; `normal` otherwise |
| `status:` | `blocked` by another issue, `needs-maintainer-decision` from Cacti, Maint, or Thold maintainers, or `needs-evidence` from a milestone 1 result |

GitHub's defaults `bug`, `documentation`, `duplicate`, `good first issue`, `help wanted`, and `wontfix` keep their usual meanings.

## Milestones

Milestones match the plan. Each has an epic issue whose sub-issues are the milestone's scope and whose body lists the exit evidence. A milestone closes when its exit evidence is attached to the epic, not when its issues are closed.

## Commits and pull requests

- Sign off every commit (`git commit -s`) to certify the [Developer Certificate of Origin](https://developercertificate.org/).
- Use [Conventional Commits](https://www.conventionalcommits.org/): `docs:`, `feat:`, `fix:`, `test:`, `chore:`, with the issue in the body (`Refs #12`, `Closes #12`).
- Keep pull requests to one issue where practical. Pull requests are squash-merged.
- Code, when it exists, follows Cacti conventions: tabs, single quotes, short array syntax, prepared queries, the `fabric` text domain for strings, and PHPStan at `phpVersion` 8.2.

## Conduct

This project follows the [Code of Conduct](CODE_OF_CONDUCT.md).
