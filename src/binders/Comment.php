<?php

namespace markhuot\craftai\binders;

use markhuot\craftai\records\CommentRecord;

class Comment implements Binder
{
    public function bind(mixed $value, array $arguments): ?CommentRecord
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $record = CommentRecord::find()->where(['id' => (int) $value])->one();

        return $record instanceof CommentRecord ? $record : null;
    }
}
