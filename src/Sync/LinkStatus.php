<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

/**
 * Discrete outcomes a {@see SymlinkLinker::link()} call can produce.
 *
 * See {@see LinkResult} for the carrying type and the meaning of each
 * case.
 */
enum LinkStatus: string
{
    case Created = 'created';
    case AlreadyCorrect = 'already-correct';
    case WouldCreate = 'would-create';
    case Failed = 'failed';
}
