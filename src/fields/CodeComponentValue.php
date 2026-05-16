<?php

namespace markhuot\craftai\fields;

use Craft;
use craft\base\ElementInterface;
use Twig\Markup;

/**
 * The normalized value of a {@see CodeComponent} field. Holds the three
 * authoring tabs (`twig`, `css`, `js`) and renders itself as Twig markup
 * so `{{ entry.handle }}` emits the compiled output without `|raw`.
 *
 * The value object extends `\Twig\Markup` for exactly that reason: Twig's
 * escape filter short-circuits on any `Markup` instance, so the value
 * round-trips through `{{ … }}` as already-safe HTML even though we
 * compute it lazily in `__toString()` (we don't have the surrounding
 * element context at `normalizeValue` time, so the parent `Markup`
 * content is left empty until the template asks for it).
 *
 * Renders Twig against Craft's standard site-template view so the same
 * `craft.*` globals an editor would expect (entries, the current site,
 * etc.) are available. The owning element is exposed as `entry` so a
 * component author can interpolate the surrounding record without having
 * to query for it.
 */
class CodeComponentValue extends Markup
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
     * Yii's `BaseObject`-style constructor signature is preserved so the
     * many call sites that do `new CodeComponentValue(['twig' => …])`
     * continue to work. We can't extend `BaseObject` anymore because
     * `Twig\Markup` is single-inheritance, so each property gets a
     * manual assignment here. Unknown keys are ignored (matching the old
     * BaseObject behavior, which would have thrown).
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        // `Markup` stores a precomputed string; we render lazily in
        // `__toString()`, so the parent's `$content` stays empty. The
        // charset is still meaningful for the parent class's `getCharset()`
        // accessor in case anything reads it.
        parent::__construct('', Craft::$app->charset);

        if (isset($config['twig']) && is_string($config['twig'])) {
            $this->twig = $config['twig'];
        }
        if (isset($config['css']) && is_string($config['css'])) {
            $this->css = $config['css'];
        }
        if (isset($config['js']) && is_string($config['js'])) {
            $this->js = $config['js'];
        }
        if (array_key_exists('agentSessionId', $config)) {
            $raw = $config['agentSessionId'];
            $this->agentSessionId = is_string($raw) && $raw !== '' ? $raw : null;
        }
        if (array_key_exists('element', $config) && $config['element'] instanceof ElementInterface) {
            $this->element = $config['element'];
        }
    }

    /**
     * Render the component as an HTML fragment safe to drop into a Twig
     * template. Most callers don't need to invoke this directly — Twig
     * picks up the same content via `__toString()` when the value is
     * interpolated as `{{ entry.handle }}` — but it's exposed for
     * symmetry with other markup-returning helpers in Craft. Empty
     * sections are omitted so a CSS-less component doesn't litter the
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
