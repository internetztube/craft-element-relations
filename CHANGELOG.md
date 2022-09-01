# Element Relations Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 2.0.2 - 2022-09-01
### Fixed
Fix error, if entry doesn't exist in primary site. Thank you, [@sfsmfc](https://github.com/sfsmfc)! [#28](https://github.com/internetztube/craft-element-relations/pull/28)

## 2.0.1 - 2022-08-27
### Fixed
- Fallback to PrimarySiteId when siteId is not set. [#27](https://github.com/internetztube/craft-element-relations/issues/27)

## 2.0.0 - 2022-06-16
### Fixed
- Fix bug when requested element id is null in `ElementRelationsController::actionGetByElementId`. `elementId` in  is now always an int. (0 = null = unused). [#21](https://github.com/internetztube/craft-element-relations/issues/21)

## 2.0.0-beta.3 - 2022-05-25
### Fixed
- Fix Exception while creating a new entry. [#19](https://github.com/internetztube/craft-element-relations/issues/19)

## 2.0.0-beta.2 - 2022-05-21
### Fixed
- Fixed Utility Exception when total is zero. 

## 2.0.0-beta.1 - 2022-05-11
### Added
- Added basic support for Craft 4.

## 1.3.6 - 2022-05-11
### Fixed
- Fixed Exception which occurred while saving a user. [#16](https://github.com/internetztube/craft-element-relations/issues/16)

## 1.3.5 - 2022-04-11
### Fixed
- Fixed utilities total count mismatch. [#13](https://github.com/internetztube/craft-element-relations/issues/13)
- Fix percentage of CreateRefreshElementRelationsJobsJob. [#15](https://github.com/internetztube/craft-element-relations/issues/15)

## 1.3.4 - 2022-04-11
### Updated
- Minimize queue job duplicates.
- Updated database field `elementrelations.relations` from `text` to `mediumText`.

### Fixed
- Fixed performance when redactor/linkit is enabled.

### Removed
- Removed `bulkJobSize` config option.

## 1.3.3 - 2022-02-21
### Updated
- Prioritize tasks that arrived in the queue via the `request refresh` buttons.

### Fixed
- Fixed bug that occurred on overview pages when cache is disabled.

## 1.3.2 - 2022-02-20
### Updated
- Updated Queue Job Priority to 4096.

## 1.3.1 - 2022-02-20
### Added
- Added config option for bulk job size.

## 1.3.0 - 2022-02-20
### Added
- Added refresh and reload buttons to element detail field.
- Show last update date on element detail page.
- Allow for disabling element events when caching is enabled.

### Updated
- Updated Queue Job Names.
- Don't refresh non-stale element relations when rebuilding cache without force option.

### Fixed
- Use illuminate/collections as `collect()` package. #9
- Only load one element relation at a time. Improves performance on overview pages.

## 1.2.6 - 2022-02-04
Version Bump

## 1.2.5 - 2022-02-04
### Fixed
- Better fix for [#6](https://github.com/internetztube/craft-element-relations/issues/6) Safely dropping foreign keys and index on `siteId` and `markup` columns.

## 1.2.4 - 2022-02-02
### Fixed
- Fixed [#6](https://github.com/internetztube/craft-element-relations/issues/6).

## 1.2.3 - 2022-01-07
### Added
- Added support for LinkIt.

## 1.2.2 - 2022-01-06
### Fixed
- Fix purging of custom fields on User Elements.

## 1.2.1 - 2022-01-06
### Added
- Added Support for Redactor.

### Updated
- Improved Cache Invalidation.

## 1.2.0 - 2022-01-04
### Updated
- Update caching logic.

### Fixed
- There is now always a hint when an element is used in another site.

## 1.1.3 - 2021-12-28
### Fixed
- Add csrf token to utilities form.

## 1.1.2 - 2021-12-28
### Added
- Added Utility Page for Cache Refresh

## 1.1.1 - 2021-12-28
### Fixed
- Database Migrations

## 1.1.0 - 2021-12-28
### Added
- Added caching system and new table to store data and speed up repeated fetches. Big thanks to [@gbowne](https://github.com/gbowne-quickbase) for the implementation! #3

## 1.0.6 - 2021-11-18
### Added
- Added support for Profile Photos.

### Fixed
- Fixed bug that occurred on prefixed SEOmatic fields.
- SEOmatic and Profile Photo check now only occur when element is an Asset. -> Performance

## 1.0.5 - 2021-11-13
### Fixed
- Fixed bug that occurred on Craft CMS installations with table prefixes. Thank you @gbowne-quickbase! #1

## 1.0.4 - 2021-10-25
### Added
- Added support for SEOmatic.

## 1.0.3 - 2021-10-25
### Fixed
- Removed translation methods in field edit view..

## 1.0.2 - 2021-10-25
### Added
- Show element relations of other sites, when there are none in the current site.
- Improved frontend performance by delaying the requests by 100ms.

## 1.0.1 - 2021-10-23
### Fixed
- Fixed composer.lock

## 1.0.0 - 2021-10-23
### Added
- Initial release
