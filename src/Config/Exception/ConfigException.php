<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Exception;

/**
 * Base class for configuration mapping failures.
 *
 * Subclasses carry the policy hint about whether the failure should be fatal:
 * {@see MalformedProjectConfig} is — the consumer owns their `composer.json`
 * and we want loud failure; {@see MalformedVendorConfig} is not — sync skips
 * the offending donor and continues.
 */
abstract class ConfigException extends \RuntimeException {}
