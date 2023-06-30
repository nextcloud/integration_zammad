# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## 2.0.6 – 2023-06-30

### Changed

- Add debug log when something goes wrong when getting notifications
- Handle missing user/ticket information when getting notifications

## 2.0.5 – 2023-06-30

### Changed

- Use `@nextcloud/vue` only and remove `@nextcloud/vue-dashboard`

### Fixed

- Handle missing user information when getting notifications

## 2.0.4 – 2023-04-13
### Changed
- Improve errors in the reference widget and on settings save failures
- Do not enforce https anymore

### Fixed
- fix plural translation in notifier

## 2.0.3 – 2023-02-23
### Changed
- update npm pkgs

### Fixed
- screenshot URL for the appstore

## 2.0.2 – 2023-02-09
### Added
- make reference provider discoverable and searchable

### Changed
- speedup search provider by at least x10
- use proper pagination
- add org info and severity in search result
- add severity in ref widget
- lazy load dashboard widget and reference widget

### Fixed
- search provider incorrect avatar URLs

## 2.0.1 – 2022-10-13
### Changed
- use @nextcloud/vue v7.0.0

### Fixed
- Avoid reference provider crash when customer has no org
  [#35](https://github.com/nextcloud/integration_zammad/issues/35) @nickvergessen
- better wrapping when reference widget gets narrow

## 2.0.0 – 2022-09-28
### Fixed
- refactoring mistake

## 1.0.7 – 2022-09-27
### Changed
- use new reference class

### Fixed
- undefined vars

## 1.0.5 – 2022-09-23
### Added
- Link reference widget for tickets and ticket comments

## 1.0.4 – 2022-09-03
### Changed
- use node 16
- use material icons, adjust to new eslint config
- improve settings style
- make it ready for NC 25, remove svg api etc...

## 1.0.2 – 2021-06-28
### Changed
- bump js libs
- get rid of all deprecated stuff
- bump min NC version to 22
- cleanup backend code

## 1.0.1 – 2021-06-21
### Changed
- bump js libs
- stop polling widget content when document is hidden
  [#14](https://github.com/nextcloud/integration_zammad/issues/14) @nickvergessen

## 1.0.0 – 2021-03-19
### Changed
- bump js libs

## 0.0.18 – 2021-02-16
### Changed
- app certificate

## 0.0.17 – 2021-02-12
### Changed
- bump js libs
- bump max NC version

### Fixed
- import nc dialogs style

## 0.0.16 – 2021-01-25
### Fixed
- url check was too restrictive
[#10](https://github.com/nextcloud/integration_zammad/issues/10) @Bosi1024

## 0.0.15 – 2021-01-21
### Changed
- bump js libs
- update translations

### Fixed
- avoid using invalid Zammad URL

## 0.0.14 – 2020-11-08
### Added
- optional navigation link to Zammad instance

### Changed
- bump js libs

## 0.0.13 – 2020-10-22
### Added
- GitHub Action to build and publish release on new v* tag

### Changed
- use Webpack 5 and stylelint

### Fixed
- always use redirect URI that is generated on browser side
- background job declaration

## 0.0.6 – 2020-10-12
### Changed
- various small improvements, mostly in the backend

## 0.0.5 – 2020-10-02
### Fixed
- mistake when saving settings
[#5](https://github.com/nextcloud/integration_zammad/issues/5) @Ludovicis

## 0.0.4 – 2020-10-02
### Added
- lots of translations

### Changed
- improve code quality
- bump libs

## 0.0.3 – 2020-09-21
### Added
* notifications for new open tickets
* unified search provider

### Changed
* improve authentication design
* improve widgets empty content

## 0.0.2 – 2020-09-02
### Fixed
* image loading with new webpack config

## 0.0.1 – 2020-09-02
### Added
* the app
