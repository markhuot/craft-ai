<?php

// craftcms/ckeditor isn't installed in the test environment, but
// LeaveComment guards CKEditor targets with `instanceof
// \craft\ckeditor\Field`. Provide a minimal stand-in (a PlainText
// subclass so Craft can still save/instantiate a field of this type)
// so tests can drive the real code path. Guarded so a real install
// — or a second require — never collides.
namespace craft\ckeditor {
    if (! class_exists(Field::class, false)) {
        class Field extends \CraftCms\Cms\Field\PlainText {}
    }
}
