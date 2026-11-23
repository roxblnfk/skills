<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

/**
 * Skill directories already validated, by {@see SkillFiles::identity()}. Validators that
 * share one instance check a directory once, however many of them reach it.
 *
 * @internal
 *
 * @psalm-external-mutation-free
 */
final class ValidatedSkills
{
    /** @var array<string, true> */
    private array $identities = [];

    /**
     * Marks a directory as validated.
     *
     * @return bool `true` when it had not been validated yet
     *
     * @psalm-external-mutation-free
     */
    public function claim(string $identity): bool
    {
        if (isset($this->identities[$identity])) {
            return false;
        }
        $this->identities[$identity] = true;

        return true;
    }
}
