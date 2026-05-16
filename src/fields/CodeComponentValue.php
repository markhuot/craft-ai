<?php

namespace markhuot\craftai\fields;

use Craft;
use craft\base\ElementInterface;
use Twig\Markup;
use yii\base\BaseObject;

/**
 * The normalized value of a {@see CodeComponent} field. Holds the three
 * authoring tabs (`twig`, `css`, `js`) and knows how to render itself —
 * call `{{ entry.handle.render() }}` from a template to emit the
 * compiled Twig output together with a `<style>` and `<script>` block.
 *
 * Renders Twig against Craft's standard site-template view so the same
 * `craft.*` globals an editor would expect (entries, the current site,
 * etc.) are available. The owning element is exposed as `entry` so a
 * component author can interpolate the surrounding record without having
 * to query for it.
 */
class CodeComponentValue extends BaseObject implements \Stringable
{
    public string $twig = '';

    public string $css = '';

    public string $js = '';

    /**
     * UUID of the chat session bound to this field on this element. Stored
     * inside the field's JSON value so the Prompt tab keeps the same
     * conversation across page reloads. Populated lazily by the editor UI
     * the first time the user opens the Prompt tab; null until then.
     */
    public ?string $agentSessionId = null;

    public ?ElementInterface $element = null;

    /**
     * Render the component as an HTML fragment safe to drop into a
     * Twig template via `{{ field.render() }}`. Empty sections are
     * omitted entirely so a CSS-less component doesn't litter the
     * page with a blank `<style>` tag.
     */
    public function render(): Markup
    {
        $view = Craft::$app->getView();

        $renderedTwig = '';
        if ($this->twig !== '') {
            $renderedTwig = $view->renderString($this->twig, [
                'entry' => $this->element,
            ]);
        }

        $parts = [];
        if ($renderedTwig !== '') {
            $parts[] = $renderedTwig;
        }
        if ($this->css !== '') {
            $parts[] = '<style>'.$this->css.'</style>';
        }
        if ($this->js !== '') {
            $parts[] = '<script>'.$this->js.'</script>';
        }

        return new Markup(implode("\n", $parts), Craft::$app->charset);
    }

    public function __toString(): string
    {
        return (string) $this->render();
    }

    /**
     * Snapshot suitable for JSON serialization back into the DB column.
     * Mirrors the keys the editor UI emits so the round-trip is symmetric.
     *
     * @return array{twig: string, css: string, js: string, agentSessionId: ?string}
     */
    public function toArray(): array
    {
        return [
            'twig' => $this->twig,
            'css' => $this->css,
            'js' => $this->js,
            'agentSessionId' => $this->agentSessionId,
        ];
    }
}
