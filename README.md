# Admidio Requirements Plugin — track recurring requirements and the activities that fulfil them

A third-party plugin for Admidio 6.0 and later that tracks recurring requirements members have to
fulfil — continuing professional development (CPD), security training, recurring certifications or
examinations — and the activities (courses, seminars, examinations) that count towards them.

Members enter their activities and the credits each one earns. For the running and the previous periods
the plugin shows whether the requirements that apply to their roles are met. Reviewers check what was
claimed and can correct the accepted credits.

## Installation

As administrator, go to Admidio's plugin manager (Admidio 6.0 or later and PHP 8.2 or later required),
select "Add plugin" and upload the plugin ZIP file, then enable the plugin. The ZIP is built from a
checkout of this repository in `plugins/requirements` of an Admidio installation:

```
php admidio plugin:archive requirements
```

The tables are created when the plugin is installed (`db_scripts/install.sql`). Uninstalling removes the
preferences, and the tables too if you choose so (`db_scripts/uninstall.sql`). The plugin has pages and
therefore an own menu entry; its texts are available in English and German.

## What it does

* **Providers:** the organisations that offer training. An activity keeps the provider's name even if
  the provider is deleted later.
* **Activities:** a member records a course, seminar or examination with its dates, location,
  attendance, a link and comments. An activity is a *draft* until the member *submits* it for review;
  a submitted activity can be *withdrawn*. A normal member sees only their own activities.
* **Credits:** one activity can earn credits for several requirements, for example 6 CPD and 3 security
  credits, each assigned to a period. A reviewer's accepted value overrides the claimed one; an accepted
  value of 0 therefore means "nothing accepted".
* **Requirement types:** a name, a description and a period: a number of calendar units (year, quarter,
  month or week), or a period that recurs after the last fulfilment. If a member qualifies through
  several roles, the type decides whether the highest, the lowest or the sum of the role obligations
  applies.
* **Role rules:** the credits a role has to earn for a requirement type, with optional validity dates
  so that a changed requirement does not apply to older periods. A rule demands either the full amount
  or an amount pro rata to the membership, counted by month, week or day. Earlier periods use the
  historical role memberships of Admidio.
* **Requirements page:** one page for the organisation's requirements and the member's own.
  Administrators define the organisation's requirements and role rules there; a member sees, read-only,
  those that apply to them through their current roles.
* **Own requirements and own targets:** every member defines requirements of their own, for example the
  training obligation of their employer, with a target and the day it applies from. A member can also
  set an own target for a requirement of the organisation that no role of theirs carries - an IDD
  requirement, say, that the organisation books credits to for everybody who attends a course but that
  only some members need for their job. Both are calculated like the organisation's requirements and
  appear in the member's overview. Other members never see them, administrators only as credits on the
  member's activities, and the review and the command line leave them out.
* **Fulfilment overview:** above the activity list, for each applicable requirement the credits earned
  against the credits required, for the running period and a configurable number of previous ones,
  with buttons to page back to earlier periods. A requirement that recurs after its last fulfilment
  shows each renewal as the window it is valid for.
  The same overview is available as a widget of the overview page, with its own setting for who sees
  it and how many previous periods it shows.
* **Administrative review:** per requirement and period, the coverage of every member, with the
  accepted credits editable in place (administrators only).
* **Import:** a wizard reads a CSV or spreadsheet of activities and their credits (file, column
  mapping, preview, import, result). Rows that were imported before are recognised and skipped.
* **Certificate files:** optionally members attach the certificate they received to an activity. The
  files are stored below `adm_my_files/` and can only be read through the plugin, which checks the
  permission.
* **Change history:** all tables write to Admidio's change history.
* **Command line:** everything above is also available as `admidio requirements:…` commands:

  | Commands | Purpose |
  |---|---|
  | `provider-add`, `-list`, `-update`, `-delete` | providers |
  | `activity-add`, `-list`, `-update`, `-submit`, `-delete` | activities |
  | `credit-set`, `credit-delete` | the credited periods of an activity |
  | `file-add`, `-list`, `-remove`, `files-gc` | certificate files; `files-gc` reports orphans and deletes them with `--commit` |
  | `requirement-add`, `-list`, `-update`, `-delete` | requirement types |
  | `rule-add`, `-list`, `-update`, `-delete` | role rules |
  | `report` | the coverage of a period, optionally with the contributing activities |
  | `import` | the import; it only reports what would happen unless `--commit` is given |

## Settings

*Administration → Preferences → Contents → Requirements*

| Setting | Default | Meaning |
|---|---|---|
| Plugin access | Logged in user | Who may use the plugin; *Disabled* switches it off. |
| Member activity editing | Always | Members may always edit and delete their own activities, or only drafts. Administrators can always review and correct records. |
| Default pro-rata basis | Month | The granularity preset for newly created pro-rata role rules. It is stored on each rule, so a later change does not reinterpret old rules. |
| Previous periods in the overview | 4 | How many periods before the running one the fulfilment overview shows. |
| Allow certificate files | off | Lets members attach certificates to activities. |
| Files per activity | 5 | |
| Maximum file size (MiB) | 10 | The server's own upload limit still applies. |
| Allowed file types | pdf,jpg,jpeg,png | Comma-separated; only pdf, jpg and png can be stored. |
| Total storage (MiB) | 0 | The size of all certificates of the organisation; 0 means no limit. |

## Database

`req_providers`, `req_activities`, `req_requirement_types`, `req_requirement_role_rules`,
`req_activity_credits` and `req_activity_files`, with the table prefix of the installation.

## Tests

`tests/Unit`, `tests/Integration` and `tests/Cli` run with the Admidio suite, e.g.

```
php vendor/bin/phpunit plugins/requirements/tests
```
