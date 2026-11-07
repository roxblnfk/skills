<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

/**
 * Something about one donor's skill that the default output must tell the user:
 * the skill is not installed, or installed under another name.
 *
 * @psalm-immutable
 */
final readonly class SkillNotice
{
    /**
     * @param non-empty-string $packageName donor the skill belongs to
     * @param non-empty-string $message what happened to the skill, without the package name
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $packageName,
        public string $message,
    ) {}

    /**
     * @return non-empty-string `<package>: <message>`
     *
     * @psalm-mutation-free
     */
    public function describe(): string
    {
        return $this->packageName . ': ' . $this->message;
    }
}
