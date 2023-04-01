# Craft AI

An intelligent integration for [Craft CMS](https://craftcms.com/).

![screenshot.png](assets%2Fscreenshot.png)

Craft AI provides additional AI functionality to Craft CMS, including:

- Generating image assets from text prompts
- Generating caption text from existing assets
- Editing image assets with AI-powered inpainting
- Completing text prompts via the Redactor field or a stand-alone form
- Editing text via the Redactor field or a stand-alone form
- Intelligent chat with an AI powered virtual assistant powered by your site's content
- Semantic kNN search of site content

Each of these can be enabled and disabled per-backend.

## Getting started

First you need to install the plugin either through the Craft plugin store or in your terminal by running
`composer require markhuot/craft-ai`. Once the plugin has been downloaded it must be installed in the
Craft UI or in your terminal with `php craft plugin/install ai`.

After the plugin is installed you should see a new "AI" menu in the Craft control panel. Clicking on AI
will bring you to the plugin's settings page. Here you can enable and disable the various AI backends. Each
backend is capable of providing multiple features. For example installing the "Stable Diffusion" back-end
only provides image generation and editing features while installing Open AI provides text generation and
editing as well.

You can click on each backend's name to install it. The backend will require a name and URL in order to
function. The name is used to identify the backend in the Craft control panel. The URL is used to connect
to the backend. Both are pre-filled for you out of the box.

All backends also require some sort of API key. You can find a deep link to the generate an API key on
each backend's settings page. For example,

![settings-openai.png](assets%2Fsettings-openai.png)

## Using fake data

Each backend offers a "free" tier during your initial sign up. If you've exhausted your free tier you can
also disable "Use Live Data" on the plugin settings page. This will cause the plugin to use fake data
instead of reaching out to the back-end. Note: the fake data does not actually use AI to generate responses.
While it should retain the same approximate shape of a real AI response, it will not be accurate to the
given prompt.
