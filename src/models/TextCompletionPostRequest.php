<?php

namespace markhuot\craftai\models;

use markhuot\craftai\casts\CastTo;
use markhuot\craftai\casts\Model as ModelCast;
use markhuot\craftai\db\Model;
use markhuot\craftai\features\Completion;

class TextCompletionPostRequest extends Model
{
    public string $content;

    #[CastTo(Backend::class)]
    public ?Completion $backend = null;

    protected array $casts = [
        'backend' => ModelCast::class,
    ];

    public function safeAttributes()
    {
        return array_merge(parent::safeAttributes(), ['backend']);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            ['content', 'required'],
        ];
    }
}
