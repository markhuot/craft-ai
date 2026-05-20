<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

/**
 * Pin a comment to a specific span inside a long-form field.
 *
 * Field-level comments (the original shape) live at `(elementId,
 * fieldHandle)` granularity — one dot on the field heading regardless of
 * which sentence the editor cares about. CKEditor fields are too long
 * for that to land usefully: a comment on "the third paragraph" still
 * just dots the whole `bodyContent` heading. `referenceId` narrows the
 * comment to a specific marked span (a stable UUID stored both on the
 * row and inside the field HTML as `data-craft-ai-comment-id="<uuid>"`).
 *
 * Null = a field-level comment, same as before. The CP overlay falls
 * back to heading-level placement when it can't find the matching span
 * (content was edited around the marker, or the field hasn't loaded
 * yet), so a missing referenceId never strands a comment.
 */
class m260518_000003_add_reference_id_to_comments extends Migration
{
    public function safeUp(): bool
    {
        if (! $this->db->columnExists('{{%craftai_comments}}', 'referenceId')) {
            $this->addColumn(
                '{{%craftai_comments}}',
                'referenceId',
                $this->string(64)->null(),
            );
        }

        $this->createIndex(
            'idx_craftai_comments_reference',
            '{{%craftai_comments}}',
            ['elementId', 'isDraft', 'fieldHandle', 'referenceId'],
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropIndexIfExists('idx_craftai_comments_reference', '{{%craftai_comments}}');

        if ($this->db->columnExists('{{%craftai_comments}}', 'referenceId')) {
            $this->dropColumn('{{%craftai_comments}}', 'referenceId');
        }

        return true;
    }
}
