<?php

namespace markhuot\craftai\records;

use craft\db\ActiveRecord;

/**
 * A rendered HTML artifact produced by an agent or a compare session — e.g. a
 * revision diff. Stored in the database (not on disk) so it survives page
 * reloads, is addressable by a single id across a multi-node deploy, can be
 * served standalone as `diff.html`, and is cascade-deleted with its session.
 *
 * The HTML is untrusted (model/CKEditor output) and is therefore only ever
 * served through {@see \markhuot\craftai\controllers\ArtifactsController} under
 * a strict CSP and rendered inside a sandboxed iframe.
 *
 * @property int $id
 * @property string $sessionId Owning session (ownership/authorization flows through it)
 * @property int|null $entryId Canonical entry the artifact relates to, when applicable
 * @property string $title Human title shown above the artifact
 * @property string $html The self-contained, script-free HTML document
 * @property string $mimeType Served Content-Type (defaults to text/html)
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ArtifactRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftai_artifacts}}';
    }
}
