# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## 0.0.9 – 2020-11-17
### Changed
- bump js libs
- automate releases

### Fixed
- restrict connection to Jira software to NC admins
[#9](https://github.com/nextcloud/integration_jira/issues/9) @karl-in-office

## 0.0.8 – 2020-10-20
### Fixed
- wrong redirect URL protocol on server side with some setups

## 0.0.7 – 2020-10-19
### Fixed
- mismatch redirect URL between server side and browser side (possibly because of overwrite.cli.url)

## 0.0.6 – 2020-10-18
### Changed
- use webpack 5 and style lint
- show hint when oauth config missing for jira cloud

### Fixed
- missing background job declaration
- avoid some warnings

## 0.0.5 – 2020-10-12
### Fixed
- register search and widget even if oauth settings are missing

## 0.0.4 – 2020-10-12
### Added
- compatibility with self hosted Jira instances

### Fixed
- generate avatar URL on server side
- fix partial term search (\*word\* does not work on Jira Cloud)
- avoid API request loop on error when token is fine

## 0.0.3 – 2020-10-03
### Fixed
- notification setting not being loaded
- only save what's needed in perso settings

## 0.0.2 – 2020-10-02
### Added
- lots of translations

## 0.0.1 – 2020-10-01
### Added
* the app
