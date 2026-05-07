<?php

namespace markhuot\craftai\tools;

/**
 * Classifies a tool by what kind of side effects it can produce.
 *
 * The session-scoped tool mode (see SessionRecord::$toolMode) filters the
 * tool list passed to the LLM by this kind:
 *
 *  - Read-only mode keeps Read tools.
 *  - Draft mode keeps Read + DraftWrite (mutations that don't affect the
 *    publicly-visible site — drafts are author-only until applied).
 *  - Full mode keeps everything.
 */
enum ToolKind: string
{
    /** Pure read; no side effects on Craft state. */
    case Read = 'read';

    /** Mutates draft-only state — never visible to public site visitors. */
    case DraftWrite = 'draftWrite';

    /** Mutates state visible to the live site (entries, templates, sections, fields, etc.). */
    case LiveWrite = 'liveWrite';
}
