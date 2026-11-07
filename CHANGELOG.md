# Changelog

## [1.16.0](https://github.com/roxblnfk/skills/compare/1.15.0...1.16.0) (2026-11-07)


### Features

* **filesystem:** compare directories by identity, not by spelling ([99001a0](https://github.com/roxblnfk/skills/commit/99001a09eee624bf59cfd3584cb3cd5b18aa535d))
* **sync:** fold skill names into collision keys ([9d0b5bb](https://github.com/roxblnfk/skills/commit/9d0b5bb27dd9e55d969eece3ebdd195c13450dd5))


### Bug Fixes

* **add:** refuse an entry that would install nothing ([ee178b1](https://github.com/roxblnfk/skills/commit/ee178b1e8ea871fe3203e9978934d2062560c481))
* **discovery:** accept a remote repository whose root is the skill ([56dfa5d](https://github.com/roxblnfk/skills/commit/56dfa5d1b5f2bab402a1ca0bcc0f2dcbdfe79dae))
* **discovery:** install a repository with a root SKILL.md as one skill ([fca6d1a](https://github.com/roxblnfk/skills/commit/fca6d1ac08f49651c1517ad29868b2144053e71c))
* **discovery:** leave the target out of a donor that contains it ([#63](https://github.com/roxblnfk/skills/issues/63)) ([0f885b3](https://github.com/roxblnfk/skills/commit/0f885b32a040126585a41ead3d05aed5da435222)), closes [#57](https://github.com/roxblnfk/skills/issues/57)
* **discovery:** let a hidden copy give way without a root SKILL.md ([2d78945](https://github.com/roxblnfk/skills/commit/2d789450843d18cbb9d5d61f32241db5a1ec9a94)), closes [#56](https://github.com/roxblnfk/skills/issues/56)
* **discovery:** refuse non-ASCII skill names when Unicode case cannot be folded ([#64](https://github.com/roxblnfk/skills/issues/64)) ([df13f30](https://github.com/roxblnfk/skills/commit/df13f3027298c6922d1899ad46a9d4b7e564e0e6)), closes [#58](https://github.com/roxblnfk/skills/issues/58)
* **show:** flag a conflict on a skill whose source is the target ([39c2ec0](https://github.com/roxblnfk/skills/commit/39c2ec0feb1d4c9465b38c4be421f4b191646362))
* **show:** keep a conflicting skill listed when its directory is a link ([4fb5c50](https://github.com/roxblnfk/skills/commit/4fb5c5040b494cfb71e5405f628e8ab20452fb44))
* **show:** list a skill linked inside the target as skipped ([f10dd0a](https://github.com/roxblnfk/skills/commit/f10dd0ae2b1b54b23ffaa69832599859f5fbb1ce))
* **show:** report what the sync does ([bf514b7](https://github.com/roxblnfk/skills/commit/bf514b79d8f64e4687b9ec6e722572c2cd73963f))
* **sync:** do not report a directory in the target as a hard link ([7659436](https://github.com/roxblnfk/skills/commit/765943647491d74faa264a9a3bbc721039935f5d))
* **sync:** never overwrite a source or another donor's skill ([3cf5b05](https://github.com/roxblnfk/skills/commit/3cf5b054ec1551cb8d72dbe359f170a68a1a2f19))
* **sync:** never write through a link inside the target ([3ab868b](https://github.com/roxblnfk/skills/commit/3ab868bc0eb5f5b92f445183bf16e8f4db33c6ba))
* **sync:** replace a hard-linked file instead of writing into it ([6962312](https://github.com/roxblnfk/skills/commit/696231228cb8f2002d23f9527e82e47cba4916d5))
* **sync:** report a link deeper in a skill with one path separator ([466bf6e](https://github.com/roxblnfk/skills/commit/466bf6e11a1c5f08611829c069dff921817af5a4))


### Documentation

* describe how links in the target show up in a dry run and skills:show ([0968be3](https://github.com/roxblnfk/skills/commit/0968be37c2abfca9a72e007e055d7efe36870777))
* describe root skills, refused names and the safety of a sync ([82399b0](https://github.com/roxblnfk/skills/commit/82399b04123201e35e6c2eff1d25b40193be0f41))
* drop the `--no-scripts` opt-out claim for auto-sync ([#51](https://github.com/roxblnfk/skills/issues/51)) ([32d24f6](https://github.com/roxblnfk/skills/commit/32d24f6cbf4d5bcbcb592df46ee4a1b092f98719))
* name the linked skill directory a sync counts as in place ([f12eef8](https://github.com/roxblnfk/skills/commit/f12eef8193252264c3b175e4cd2188d33554534a))
* name where a hidden copy gives way to a visible skill ([9088e4b](https://github.com/roxblnfk/skills/commit/9088e4bf9e244cd82e6ded7bfc01a36734e65fb8))
* note that a clean dry run assumes a linked skill can be removed ([66e2062](https://github.com/roxblnfk/skills/commit/66e20629abdbdd5a9270cafb27df59a7f5dd808d))
* re-wrap the clean refusal note and temper the dir donor claim ([8e119d4](https://github.com/roxblnfk/skills/commit/8e119d44eff50e0601bc9d768284d95ae80cf65b))
* say which linked skill directories the drift check still compares ([09910c9](https://github.com/roxblnfk/skills/commit/09910c9161411dbc28da18845227edfbb0a33d0f))


### Code Refactoring

* **test:** name the extension-free PHP helper after both extensions it drops ([79bc8f7](https://github.com/roxblnfk/skills/commit/79bc8f7de2b1b8d1541d2c511f18f07824d0ffb7))

## [1.15.0](https://github.com/roxblnfk/skills/compare/1.14.0...1.15.0) (2026-10-15)


### Features

* ship standalone binaries for Linux, macOS and Windows ([#48](https://github.com/roxblnfk/skills/issues/48)) ([0cc134c](https://github.com/roxblnfk/skills/commit/0cc134c989f2dbf2a0a8664f22aeae630c9e86bd))

## [1.14.0](https://github.com/roxblnfk/skills/compare/1.13.0...1.14.0) (2026-09-16)


### Features

* `--clean` reinstalls skills instead of merging into them ([#45](https://github.com/roxblnfk/skills/issues/45)) ([1c8a1e4](https://github.com/roxblnfk/skills/commit/1c8a1e43351e32d5a7ea660dad959a6fc650ff29))

## [1.13.0](https://github.com/roxblnfk/skills/compare/1.12.0...1.13.0) (2026-09-15)


### Features

* **init:** add `--quick` and a flag for every config value ([2181d48](https://github.com/roxblnfk/skills/commit/2181d48c50d847fd26b3bb6ec47405d2d2cfed85))
* offer a configuration to a project that has none ([#43](https://github.com/roxblnfk/skills/issues/43)) ([2181d48](https://github.com/roxblnfk/skills/commit/2181d48c50d847fd26b3bb6ec47405d2d2cfed85))

## [1.12.0](https://github.com/roxblnfk/skills/compare/1.11.1...1.12.0) (2026-09-03)


### Features

* vendor packages can declare external skill sources (`extra.skills.sources`) ([#41](https://github.com/roxblnfk/skills/issues/41)) ([67e04cc](https://github.com/roxblnfk/skills/commit/67e04cc51b3d0dabeb7225706fc8aa8d9da6f273))


### Bug Fixes

* **schema:** drop unimplemented url-only adapters from `sources[].from` autocomplete ([67e04cc](https://github.com/roxblnfk/skills/commit/67e04cc51b3d0dabeb7225706fc8aa8d9da6f273))

## [1.11.1](https://github.com/roxblnfk/skills/compare/1.11.0...1.11.1) (2026-08-26)


### Bug Fixes

* **discovery:** deduplicate donors sharing an install path ([#39](https://github.com/roxblnfk/skills/issues/39)) ([41bfa51](https://github.com/roxblnfk/skills/commit/41bfa51a80c12b5b17ed6b609a3e7e0791800e2b))

## [1.11.0](https://github.com/roxblnfk/skills/compare/1.10.0...1.11.0) (2026-08-25)


### Features

* accept a list of directories in `extra.skills.source` ([#36](https://github.com/roxblnfk/skills/issues/36)) ([3ad2850](https://github.com/roxblnfk/skills/commit/3ad28500409559fad98f191d85f2d6bdc6b8e221))

## [1.10.0](https://github.com/roxblnfk/skills/compare/1.9.0...1.10.0) (2026-08-24)


### Features

* report unresolvable source donors in the sync listing and skills:show ([553fb0b](https://github.com/roxblnfk/skills/commit/553fb0b1a254e7d3f11eaa6ce7410a00e4542b4e))
* tilde `sources[].ref` constraints and visible source failures ([553fb0b](https://github.com/roxblnfk/skills/commit/553fb0b1a254e7d3f11eaa6ce7410a00e4542b4e))

## [1.9.0](https://github.com/roxblnfk/skills/compare/1.8.1...1.9.0) (2026-07-08)


### Features

* `dir` source adapter — local directories as explicit donors ([#29](https://github.com/roxblnfk/skills/issues/29)) ([a22c496](https://github.com/roxblnfk/skills/commit/a22c496c34ced9c6830fee22f0ce50da1b9b3a53))
* per-package-manager `dependencies` block with scoped trust ([#30](https://github.com/roxblnfk/skills/issues/30)) ([044fc6f](https://github.com/roxblnfk/skills/commit/044fc6f89399cbb11d2be10a376bf2730bee09b5))
* rename the `remote` config key to `sources` with auto-migration ([#27](https://github.com/roxblnfk/skills/issues/27)) ([8689a10](https://github.com/roxblnfk/skills/commit/8689a10a8840a31b536406341e27275ec64ac6bd))


### Code Refactoring

* rename Remote→Source and local→manager internals; drop spec references ([#31](https://github.com/roxblnfk/skills/issues/31)) ([8826f23](https://github.com/roxblnfk/skills/commit/8826f2362e2e7eb26500730ccdcce6ed57dbbb69))

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
