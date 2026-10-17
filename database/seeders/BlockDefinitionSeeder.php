<?php

namespace Database\Seeders;

use App\Models\BlockDefinition;
use Illuminate\Database\Seeder;

class BlockDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $blocks = [
            [
                'key' => 'core.hero',
                'name' => 'Hero',
                'category' => 'layout',
                'description' => 'Large heading with a subheading and call-to-action button. Good as a page opener.',
                'sort_order' => 1,
                'content_schema' => [
                    ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'target' => 'content'],
                    ['key' => 'subheading', 'label' => 'Subheading', 'type' => 'richtext', 'target' => 'content'],
                    ['key' => 'button_text', 'label' => 'Button text', 'type' => 'text', 'target' => 'content'],
                    ['key' => 'button_url', 'label' => 'Button link', 'type' => 'url', 'target' => 'content'],
                    ['key' => 'background_color', 'label' => 'Background color', 'type' => 'color', 'target' => 'style'],
                ],
                'default_content' => [
                    'heading' => 'Your headline goes here',
                    'subheading' => 'A short line describing what you offer and why it matters.',
                    'button_text' => 'Get started',
                    'button_url' => '#',
                ],
                'default_style' => ['padding_y' => 64, 'align' => 'center'],
            ],
            [
                'key' => 'core.text_image',
                'name' => 'Text + Image',
                'category' => 'content',
                'description' => 'A heading and paragraph next to an image. Good for explaining a product or service.',
                'sort_order' => 1,
                'content_schema' => [
                    ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'target' => 'content'],
                    ['key' => 'body', 'label' => 'Body text', 'type' => 'richtext', 'target' => 'content'],
                    ['key' => 'image', 'label' => 'Image', 'type' => 'image', 'target' => 'content'],
                    ['key' => 'image_position', 'label' => 'Image side', 'type' => 'select', 'options' => ['left', 'right'], 'target' => 'content'],
                ],
                'default_content' => [
                    'heading' => 'Tell your story',
                    'body' => 'Describe the benefit in a sentence or two — keep it simple and focused on the reader.',
                    // Image fields store { media_id, url } together — url is
                    // kept alongside the id so the renderer can display it
                    // immediately without a lookup, same pattern as the
                    // product form's MediaPicker (modelValue + previewUrl).
                    'image' => ['media_id' => null, 'url' => null],
                    'image_position' => 'right',
                ],
                'default_style' => [],
            ],
            [
                'key' => 'core.feature_grid',
                'name' => 'Feature Grid',
                'category' => 'content',
                'description' => 'Three-column list of features or benefits.',
                'sort_order' => 2,
                'content_schema' => [
                    ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'target' => 'content'],
                ],
                'default_content' => [
                    'heading' => 'Why choose us',
                    'features' => [
                        ['title' => 'Fast', 'description' => 'Ships quickly and reliably.'],
                        ['title' => 'Reliable', 'description' => 'Built to last, tested thoroughly.'],
                        ['title' => 'Supported', 'description' => 'Real people ready to help.'],
                    ],
                ],
                'default_style' => [],
            ],
            [
                'key' => 'core.gallery',
                'name' => 'Gallery',
                'category' => 'media',
                'description' => 'A grid of images.',
                'sort_order' => 1,
                'content_schema' => [],
                'default_content' => ['images' => []],
                'default_style' => [],
            ],
            [
                'key' => 'core.testimonial',
                'name' => 'Testimonial',
                'category' => 'content',
                'description' => 'A quote from a happy customer.',
                'sort_order' => 3,
                'content_schema' => [
                    ['key' => 'quote', 'label' => 'Quote', 'type' => 'richtext', 'target' => 'content'],
                    ['key' => 'author', 'label' => 'Author', 'type' => 'text', 'target' => 'content'],
                ],
                'default_content' => [
                    'quote' => 'This product completely changed how we work.',
                    'author' => 'Jane Doe, CEO of Example Co.',
                ],
                'default_style' => [],
            ],
            [
                'key' => 'core.cta_banner',
                'name' => 'Call to Action',
                'category' => 'layout',
                'description' => 'A banner with a heading and button — good for driving a conversion at the bottom of a page.',
                'sort_order' => 2,
                'content_schema' => [
                    ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'target' => 'content'],
                    ['key' => 'button_text', 'label' => 'Button text', 'type' => 'text', 'target' => 'content'],
                    ['key' => 'button_url', 'label' => 'Button link', 'type' => 'url', 'target' => 'content'],
                    ['key' => 'background_color', 'label' => 'Background color', 'type' => 'color', 'target' => 'style'],
                ],
                'default_content' => [
                    'heading' => 'Ready to get started?',
                    'button_text' => 'Contact us',
                    'button_url' => '#',
                ],
                'default_style' => [],
            ],
            [
                'key' => 'core.contact_form',
                'name' => 'Contact Form',
                'category' => 'forms',
                'description' => 'A simple name/email/message contact form.',
                'sort_order' => 1,
                'content_schema' => [
                    ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'target' => 'content'],
                    ['key' => 'button_text', 'label' => 'Button text', 'type' => 'text', 'target' => 'content'],
                ],
                'default_content' => [
                    'heading' => 'Get in touch',
                    'button_text' => 'Send message',
                ],
                'default_style' => [],
            ],
            [
                'key' => 'core.rich_text',
                'name' => 'Rich Text',
                'category' => 'content',
                'description' => 'A free-form paragraph of text — good for filler content, policies, or long-form copy.',
                'sort_order' => 4,
                'content_schema' => [
                    ['key' => 'body', 'label' => 'Body text', 'type' => 'richtext', 'target' => 'content'],
                ],
                'default_content' => [
                    'body' => 'Write your content here.',
                ],
                'default_style' => [],
            ],
        ];

        foreach ($blocks as $block) {
            BlockDefinition::updateOrCreate(['key' => $block['key']], $block);
        }
    }
}