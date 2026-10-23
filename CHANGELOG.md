<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: CC0-1.0
-->
# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## 1.5.1 - 2026-10-23

### Changed

- Load the admin settings lazily instead of on every request
- Accept only the app's own admin settings, as strings, on the admin endpoints
- Update dependencies & translations

### Fixed

- Show the link preview of an issue whose fields carry no labels or no project
- Show no date rather than today's for an issue without a created or updated time
- Point the dashboard widget's "show more" link at the Jira instance whose issues it shows
- Keep the dashboard widget polling after a network error, instead of stopping until the page is reloaded
- List a notification whose issue has no creator instead of emptying the widget
- Keep a notification that arrived while a second request was still in flight
- Show the newest version of an issue in the dashboard widget
- Keep the project selector usable when a saved project is no longer available
- Show the issues of the selected projects in the filtered dashboard widget when connected with an API token or a login and password, instead of none
- Show the issues of a Jira Cloud site on atlassian.net connected with an API token in the dashboard widget and the search, and its open tickets notification once the site is connected again
- Show no avatar rather than a server error when an avatar image cannot be fetched
- Report an answer that is not a JSON object or array, such as the HTML page of a proxy in front of Jira, as an error when connected with a login and password
- Show the link preview of an issue whose project key contains digits, such as AB12C-345
- Show the link preview of an issue only from the Jira site the link points to, and only for a site the user is connected to, instead of the issue with the same key on the user's own site; a Jira Cloud site connected with an API token through Atlassian's API gateway before this change shows its link previews again once it is connected again
- Show the generic link preview without PHP warnings in the log when an issue cannot be shown, such as one the user cannot see

### Security

- Send the Jira credentials only to Jira itself when fetching an avatar, not to avatar hosts such as Gravatar

## 1.5.0 - 2026-09-29

### Added

- Added support for Nextcloud 36

### Changed

- Update Psalm and @nextcloud/eslint-config, and sync the lint workflows
- Update dependencies & translations

### Fixed

- Show the content of the dashboard widgets again, they stayed empty because of an error when loading
- Ask users who did not connect a Jira account to connect one in the dashboard widgets, instead of telling them there are no notifications

## 1.4.3 - 2026-07-27

### Changed

- Use the outline style for the home and account icons in the personal settings
- Update Psalm to 6.16 and nextcloud/coding-standard to 1.5
- Build the frontend in CI for dependency updates, and lint all style files
- Update dependencies & translations

## 1.4.2 - 2026-05-27

### Added

- Added support for Nextcloud 34 and 35

### Removed

- Nextcloud 30 to 32 and PHP 8.1 support

### Fixed

- Stop spamming the log with `\InvalidArgumentException` deprecation warnings from the notifier; throw `OCP\Notification\UnknownNotificationException` instead for unknown notifications
- Fix `ValueError` from `vsprintf()` that broke notifications for non-English users
- Migrate Jira Cloud search and notification calls to `/rest/api/3/search/jql`; the legacy `/rest/api/2/search` endpoint was removed by Atlassian (CHANGE-2046) and Cloud users were getting `410 Gone`; self-hosted Jira (Server / Data Center) is unaffected and keeps the v2 endpoint

## 1.4.1 - 2025-11-10

### Added

- Added support for Nextcloud 33

### Changed

- Update dependencies & translations

## 1.4.0 - 2025-07-20

### Added

- Added support for Nextcloud 32

### Changed

- Update dependencies

### Removed

- Nextcloud 28 and 29 support

## 1.3.0 - 2025-03-13

### Added

- Link previews for Jira issues, toggle options in settings
- Second dashboard widget to display notifications only for selected projects available to your Jira account

### Changed

- Update dependencies

## 1.2.1 - 2024-11-18

### Added

- Added support for Nextcloud 31

### Changed

- Update dependencies

### Fixed

- Encrypt secrets in the database and not expose them to the UI

## 1.2.0 - 2024-07-24

### Added

- Added support for Nextcloud 30

### Changed

- Update dependencies

### Removed

- Nextcloud 27 support

## 1.1.0 - 2024-03-07

### Added

- Added support for Nextcloud 29

### Changed

- Update dependencies

### Removed

- Nextcloud 26 support

## 1.0.7 - 2024-01-19

### Changed

- Update dependencies

### Removed

- Nextcloud 25 support

### Fixed

- Invalid error handling that prevented the refresh token from updating

## 1.0.6 - 2023-10-24

### Added

- Added support for Nextcloud 28

### Changed

- Update dependencies

### Removed

- Nextcloud 22 to 24 support

## 1.0.5 - 2023-05-12

### Added

- Added support for Nextcloud 26 and 27

### Changed

- Update dependencies

## 1.0.4 - 2022-08-29

### Added

- Added support for Nextcloud 24 and 25

### Changed

- Adjust to Nextcloud 25
- Token refresh is now based on the expiration date
- Update dependencies

### Fixed

- Token refresh was not storing the new refresh token

## 1.0.1 - 2021-06-28

### Added

- Added support for Nextcloud 23

### Changed

- Stop polling the widget content when the document is hidden
- Get rid of all deprecated stuff
- Clean up the backend code
- Update dependencies

### Removed

- Nextcloud 20 and 21 support

## 1.0.0 - 2021-01-19

### Changed

- Update dependencies & translations

## 0.0.15 - 2021-02-16

### Changed

- Republish to use the new app certificate

## 0.0.14 - 2021-02-14

### Added

- Admin setting to force a self-hosted Jira URL

### Fixed

- Safer API requests error management

## 0.0.12 - 2021-02-12

### Added

- Added support for Nextcloud 22

### Changed

- Update dependencies

### Fixed

- Import the Nextcloud dialog style

## 0.0.11 - 2020-12-10

### Changed

- Update dependencies

### Fixed

- Crash when the accessibility app is not installed

## 0.0.10 - 2020-11-17

### Fixed

- Search with non-ASCII characters not working with the JQL wildcard

## 0.0.9 - 2020-11-17

### Changed

- Automate releases
- Update dependencies

### Fixed

- Let users who are not Nextcloud admins connect to a self-hosted Jira

## 0.0.8 - 2020-10-20

### Fixed

- Wrong redirect URL protocol on the server side with some setups

## 0.0.7 - 2020-10-19

### Fixed

- Mismatched redirect URL between the server side and the browser side (possibly because of overwrite.cli.url)

## 0.0.6 - 2020-10-18

### Changed

- Use webpack 5 and stylelint
- Show a hint when the OAuth config is missing for Jira Cloud

### Fixed

- Missing background job declaration
- Avoid some warnings

## 0.0.5 - 2020-10-12

### Fixed

- Register the search and the widget even if the OAuth settings are missing

## 0.0.4 - 2020-10-12

### Added

- Compatibility with self-hosted Jira instances

### Fixed

- Generate the avatar URL on the server side
- Fix partial term search (\*word\* does not work on Jira Cloud)
- Avoid an API request loop on error when the token is fine

## 0.0.3 - 2020-10-03

### Fixed

- Notification setting not being loaded
- Only save what's needed in the personal settings

## 0.0.2 - 2020-10-02

### Added

- Lots of translations

## 0.0.1 - 2020-10-01

### Added

- Initial release for Nextcloud 20 and 21
