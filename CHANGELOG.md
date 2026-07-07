# Changelog

## [1.8.1](https://github.com/roxblnfk/skills/compare/1.8.0...1.8.1) (2026-07-07)


### Bug Fixes

* load 0.x donors on skills:add (pre-1.0 caret) + dedupe archive inspection ([#25](https://github.com/roxblnfk/skills/issues/25)) ([5b3ace4](https://github.com/roxblnfk/skills/commit/5b3ace40dc3db3cf4d3a32b8f1927083fb7ed941))

## [1.8.0](https://github.com/roxblnfk/skills/compare/1.7.1...1.8.0) (2026-06-22)


### Features

* target skills above the project root via verified path-from-root ([#23](https://github.com/roxblnfk/skills/issues/23)) ([6be2ae2](https://github.com/roxblnfk/skills/commit/6be2ae22f2000afd9fdd4c515e5199a96d092b85))

## [1.7.1](https://github.com/roxblnfk/skills/compare/1.7.0...1.7.1) (2026-06-07)


### Bug Fixes

* load auth tokens into the IO so private remote fetches authenticate ([8ddc9a8](https://github.com/roxblnfk/skills/commit/8ddc9a8ac50b9138dc00539757b43ba4b13a509c))

## [1.7.0](https://github.com/roxblnfk/skills/compare/1.6.0...1.7.0) (2026-06-07)


### Features

* hint at GitLab auth setup on 401/403/404 ([631d92b](https://github.com/roxblnfk/skills/commit/631d92b76d88c7d653281a9de0a8b0fd6638a527))


### Bug Fixes

* parse GitLab SSH/SCP clone URLs in gitlab adapter ([04810fe](https://github.com/roxblnfk/skills/commit/04810fee15d88f2eba524ed7ca2fb4318c5a1354))

## [1.6.0](https://github.com/roxblnfk/skills/compare/1.5.0...1.6.0) (2026-06-07)


### Features

* GitLab remote skill provider (gitlab adapter) ([#18](https://github.com/roxblnfk/skills/issues/18)) ([884f6d0](https://github.com/roxblnfk/skills/commit/884f6d00d4ad7fa98de84772336ffbba95dca936))

## [1.5.0](https://github.com/roxblnfk/skills/compare/1.4.0...1.5.0) (2026-06-05)


### Features

* recursive SKILL.md auto-discovery (local + remote) ([8c795e5](https://github.com/roxblnfk/skills/commit/8c795e5285212d090bd2d99671f3b665df44f020))

## [1.4.0](https://github.com/roxblnfk/skills/compare/1.3.0...1.4.0) (2026-05-28)


### Features

* Add `add` command ([e2dd709](https://github.com/roxblnfk/skills/commit/e2dd7092005b0c535dfec19900eab71b5b7604d4))
* Multi-source skill providers (local + remote) ([#15](https://github.com/roxblnfk/skills/issues/15)) ([e2dd709](https://github.com/roxblnfk/skills/commit/e2dd7092005b0c535dfec19900eab71b5b7604d4))

## [1.3.0](https://github.com/roxblnfk/skills/compare/1.2.0...1.3.0) (2026-05-26)


### Features

* Add command `skills:init` command ([8d2aadf](https://github.com/roxblnfk/skills/commit/8d2aadf3e79f574b810b39a3053192fd75cefbbc))
* Migrate to external `skills.json` config ([8d2aadf](https://github.com/roxblnfk/skills/commit/8d2aadf3e79f574b810b39a3053192fd75cefbbc))


### Code Refactoring

* Add `provider` contract to add other vendors in the future ([#4](https://github.com/roxblnfk/skills/issues/4)) ([8d2aadf](https://github.com/roxblnfk/skills/commit/8d2aadf3e79f574b810b39a3053192fd75cefbbc))

## [1.2.0](https://github.com/roxblnfk/skills/compare/1.1.1...1.2.0) (2026-05-20)


### Features

* Trust direct dependencies implicitly ([3ecd9c5](https://github.com/roxblnfk/skills/commit/3ecd9c5090b996220f11a52538f6f8e34646cf4f))


### Documentation

* Update trusted vendors guidelines for submissions ([df8a5b5](https://github.com/roxblnfk/skills/commit/df8a5b5d0f39fd4009f2d43f7c3c8db5299d1331))

## [1.1.1](https://github.com/roxblnfk/skills/compare/1.1.0...1.1.1) (2026-05-19)


### Bug Fixes

* Treat vendor `extra.skills` without `source` as non-donor ([#10](https://github.com/roxblnfk/skills/issues/10)) ([f503793](https://github.com/roxblnfk/skills/commit/f503793d2eca77c1f4e5971e5d35f331b2b45dab))

## [1.1.0](https://github.com/roxblnfk/skills/compare/1.0.1...1.1.0) (2026-05-16)


### Features

* Add composer auto-sync ([953337d](https://github.com/roxblnfk/skills/commit/953337da327a482ae138f620f55a0987c1e47554))
* Mirror target via junction/symlink aliases ([#8](https://github.com/roxblnfk/skills/issues/8)) ([234e5bf](https://github.com/roxblnfk/skills/commit/234e5bfaa8a14b195669d7540aa0813d93f109cc))

## [1.0.1](https://github.com/roxblnfk/skills/compare/1.0.0...1.0.1) (2026-05-15)


### Documentation

* Update CLAUDE.md and README with installation instructions and guidelines ([ce1479f](https://github.com/roxblnfk/skills/commit/ce1479fd8839d910df347d08e17a6430e2bf23a0))

## [1.0.0](https://github.com/roxblnfk/skills/compare/0.3.0...1.0.0) (2026-05-14)


### ⚠ BREAKING CHANGES

* Prepare to release

### Miscellaneous

* Prepare to release ([4246a89](https://github.com/roxblnfk/skills/commit/4246a89a3aa679ad77a7e85be6b2b76ae1268f9a))

## [0.3.0](https://github.com/roxblnfk/skills/compare/0.2.0...0.3.0) (2026-05-14)


### Features

* Add a new command `show` ([9610a07](https://github.com/roxblnfk/skills/commit/9610a07949552f2ac23ff556f4ee31d56d0358b4))
* Add discovery mode ([9dbc757](https://github.com/roxblnfk/skills/commit/9dbc75766e9ca0e302aa2b0ae1f4c3348c0e4015))
* Auto-discover skills and treat named packages as trusted ([bf6a15d](https://github.com/roxblnfk/skills/commit/bf6a15d7937a080da5d6664dd0c16e1fd6657858))


### Documentation

* Update README with improved installation and command usage instructions ([30f82bb](https://github.com/roxblnfk/skills/commit/30f82bbf83e81bd222241228cff6ad47c4d806e4))

## [0.2.0](https://github.com/roxblnfk/skills/compare/0.1.0...0.2.0) (2026-05-14)


### ⚠ BREAKING CHANGES

* Rename `skills:sync` to `skills:update`

### Code Refactoring

* Rename `skills:sync` to `skills:update` ([27a83ec](https://github.com/roxblnfk/skills/commit/27a83ecfacde90aeea9fb8888aa86b0292b3507d))
* Separate Discovery service ([b9c597a](https://github.com/roxblnfk/skills/commit/b9c597ab27c1072dfb4e4ddce814081e9548ec80))
