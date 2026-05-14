---
name: auto-skill
description: Demonstrates auto-discovery — this skill is found via the well-known skills/ root, not via extra.skills.
---

This skill lives under `skills/auto-skill/` in a package whose `composer.json`
deliberately omits `extra.skills`. The `--discovery` flag (or
`extra.skills.discovery: true` in the project) opts in to including it.
